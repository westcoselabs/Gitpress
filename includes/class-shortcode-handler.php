<?php
/**
 * Shortcode rendering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGS_Shortcode_Handler {

	/**
	 * Register shortcode aliases.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'divi_github', array( __CLASS__, 'render_shortcode' ) );
		add_shortcode( 'divi_github_content', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Validate that a string contains only safe GitPress shortcode markup.
	 *
	 * Used to validate global (settings-level) shortcode fields such as the
	 * GitPress Managed header/footer, where arbitrary HTML/PHP/script content
	 * must not be allowed. An empty string is valid (means "no shortcode").
	 *
	 * @param string $value Raw value submitted by an admin.
	 * @return true|WP_Error
	 */
	public static function validate_shortcode_string( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return true;
		}

		if ( false !== stripos( $value, '<?php' ) || false !== stripos( $value, '<script' ) || false !== stripos( $value, 'javascript:' ) ) {
			return new WP_Error(
				'dgs_unsafe_shortcode',
				__( 'Shortcode cannot contain script or PHP tags.', 'gitpress' )
			);
		}

		$allowed_tags = array( 'divi_github', 'divi_github_content' );
		$pattern      = '/' . get_shortcode_regex( $allowed_tags ) . '/';

		if ( ! preg_match_all( $pattern, $value, $matches ) ) {
			return new WP_Error(
				'dgs_invalid_shortcode',
				__( 'Only divi_github_content (or divi_github) shortcodes are allowed in this field.', 'gitpress' )
			);
		}

		$remainder = $value;

		foreach ( $matches[0] as $index => $full_match ) {
			$remainder = str_replace( $full_match, '', $remainder );

			$atts = shortcode_parse_atts( $matches[3][ $index ] );
			$atts = is_array( $atts ) ? $atts : array();

			if ( ! empty( $atts['url'] ) && self::url_contains_credentials( (string) $atts['url'] ) ) {
				return new WP_Error(
					'dgs_shortcode_token_url',
					__( 'Shortcode URLs may not include embedded tokens or credentials. Use the owner/repo/path attributes and store any token in GitPress settings instead.', 'gitpress' )
				);
			}
		}

		if ( '' !== trim( $remainder ) ) {
			return new WP_Error(
				'dgs_invalid_shortcode',
				__( 'Only GitPress shortcode markup is allowed in this field.', 'gitpress' )
			);
		}

		return true;
	}

	/**
	 * Detect URLs that embed credentials or token-like query parameters.
	 *
	 * @param string $url Raw URL value.
	 * @return bool
	 */
	private static function url_contains_credentials( $url ) {
		if ( false !== strpos( $url, '@' ) ) {
			return true;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
			return false;
		}

		parse_str( $parts['query'], $query_args );

		foreach ( array_keys( $query_args ) as $key ) {
			if ( preg_match( '/token|secret|key/i', (string) $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render a GitHub-backed shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'url'          => '',
				'owner'        => '',
				'repo'         => '',
				'path'         => '',
				'file'         => '',
				'branch'       => 'main',
				'format'       => 'html',
				'ttl'          => '',
				'class'        => '',
				'wrapper'      => 'section',
				'source_link'  => 'false',
				'updated_meta' => 'false',
				'stale_notice' => 'false',
				'schema'       => '',
				'language'     => '',
			),
			$atts,
			'divi_github_content'
		);

		$target = self::resolve_target( $atts );

		if ( is_wp_error( $target ) ) {
			return self::render_error( $target );
		}

		$atts['format']       = self::sanitize_format( $atts['format'] );
		$atts['ttl']          = self::sanitize_ttl( $atts['ttl'] );
		$atts['source_link']  = self::to_bool( $atts['source_link'] );
		$atts['updated_meta'] = self::to_bool( $atts['updated_meta'] );
		$atts['stale_notice'] = self::to_bool( $atts['stale_notice'] );
		$atts['wrapper']      = self::sanitize_wrapper( $atts['wrapper'] );
		$atts['schema']       = self::sanitize_schema( $atts['schema'] );
		$atts['language']     = self::sanitize_language( $atts['language'], $target['path'], $atts['format'] );

		$cache_key = DGS_Cache_Handler::generate_key(
			$target['owner'],
			$target['repo'],
			$target['path'],
			$target['branch'],
			$atts['format']
		);

		$payload = DGS_Cache_Handler::get( $cache_key );

		if ( false === $payload ) {
			$payload = self::refresh_payload( $cache_key, $target, $atts );
		}

		if ( is_wp_error( $payload ) ) {
			return self::render_error( $payload );
		}

		wp_enqueue_style( 'dgs-frontend' );

		return self::render_output( $payload, $atts );
	}

	/**
	 * Try GitHub first, then fall back to the last cached copy.
	 *
	 * @param string $cache_key Cache key.
	 * @param array  $target Repo target.
	 * @param array  $atts Shortcode attributes.
	 * @return array|WP_Error
	 */
	private static function refresh_payload( $cache_key, $target, $atts ) {
		$github  = new DGS_GitHub_API();
		$payload = $github->get_file_content(
			$target['owner'],
			$target['repo'],
			$target['path'],
			$target['branch']
		);

		if ( is_wp_error( $payload ) ) {
			$fallback = DGS_Cache_Handler::get_last_good( $cache_key );

			if ( false !== $fallback ) {
				$fallback['is_stale'] = true;
				return $fallback;
			}

			return $payload;
		}

		DGS_Cache_Handler::set(
			$cache_key,
			$payload,
			$atts['ttl'],
			array(
				'owner'  => $target['owner'],
				'repo'   => $target['repo'],
				'path'   => $target['path'],
				'branch' => $target['branch'],
				'format' => $atts['format'],
			)
		);

		return $payload;
	}

	/**
	 * Resolve either a GitHub URL or owner/repo/path values.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return array|WP_Error
	 */
	private static function resolve_target( $atts ) {
		$path = '' !== $atts['path'] ? $atts['path'] : $atts['file'];
		$data = array(
			'owner'  => sanitize_text_field( (string) $atts['owner'] ),
			'repo'   => sanitize_text_field( (string) $atts['repo'] ),
			'branch' => sanitize_text_field( (string) $atts['branch'] ),
			'path'   => DGS_Cache_Handler::normalize_path( (string) $path ),
		);

		if ( '' !== $atts['url'] ) {
			$parsed = self::parse_github_url( (string) $atts['url'] );

			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			$data = array_merge( $data, $parsed );
		}

		if ( '' === $data['owner'] || '' === $data['repo'] || '' === $data['path'] ) {
			return new WP_Error(
				'dgs_missing_target',
				__( 'Use either owner/repo/path or a GitHub file URL in the shortcode.', 'gitpress' )
			);
		}

		if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/', $data['owner'] ) || ! preg_match( '/^[A-Za-z0-9_.-]+$/', $data['repo'] ) ) {
			return new WP_Error(
				'dgs_invalid_repository',
				__( 'Owner and repo may only contain letters, numbers, dashes, underscores, and periods.', 'gitpress' )
			);
		}

		return $data;
	}

	/**
	 * Parse a GitHub blob URL or raw file URL.
	 *
	 * @param string $url GitHub URL.
	 * @return array|WP_Error
	 */
	private static function parse_github_url( $url ) {
		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path  = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';

		if ( '' === $host || '' === $path ) {
			return new WP_Error(
				'dgs_invalid_url',
				__( 'The provided GitHub URL could not be parsed.', 'gitpress' )
			);
		}

		$segments = explode( '/', $path );

		if ( in_array( $host, array( 'github.com', 'www.github.com' ), true ) && count( $segments ) >= 5 && 'blob' === $segments[2] ) {
			return array(
				'owner'  => sanitize_text_field( $segments[0] ),
				'repo'   => sanitize_text_field( $segments[1] ),
				'branch' => sanitize_text_field( $segments[3] ),
				'path'   => DGS_Cache_Handler::normalize_path( implode( '/', array_slice( $segments, 4 ) ) ),
			);
		}

		if ( 'raw.githubusercontent.com' === $host && count( $segments ) >= 4 ) {
			return array(
				'owner'  => sanitize_text_field( $segments[0] ),
				'repo'   => sanitize_text_field( $segments[1] ),
				'branch' => sanitize_text_field( $segments[2] ),
				'path'   => DGS_Cache_Handler::normalize_path( implode( '/', array_slice( $segments, 3 ) ) ),
			);
		}

		return new WP_Error(
			'dgs_invalid_github_url',
			__( 'Use a GitHub blob URL or a raw.githubusercontent.com file URL.', 'gitpress' )
		);
	}

	/**
	 * Render final frontend markup.
	 *
	 * @param array $payload File payload.
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	private static function render_output( $payload, $atts ) {
		$classes = array(
			'dgs-content-block',
			'dgs-format-' . $atts['format'],
		);

		if ( ! empty( $payload['is_stale'] ) ) {
			$classes[] = 'is-stale';
		}

		$classes = array_merge( $classes, self::sanitize_classes( $atts['class'] ) );

		$attributes = array(
			'class'           => implode( ' ', array_filter( $classes ) ),
			'data-dgs-repo'   => sanitize_text_field( $payload['owner'] . '/' . $payload['repo'] ),
			'data-dgs-path'   => sanitize_text_field( $payload['path'] ),
			'data-dgs-branch' => sanitize_text_field( $payload['branch'] ),
			'data-dgs-sha'    => sanitize_text_field( $payload['sha'] ),
		);

		if ( '' !== $atts['schema'] ) {
			$attributes['itemscope'] = true;
			$attributes['itemtype']  = 'https://schema.org/' . $atts['schema'];
		}

		$html  = '<' . $atts['wrapper'] . self::html_attributes( $attributes ) . '>';
		$html .= '<div class="dgs-content-block__body">' . self::render_body( (string) $payload['content'], $atts ) . '</div>';
		$html .= self::render_meta( $payload, $atts );
		$html .= '</' . $atts['wrapper'] . '>';

		return $html;
	}

	/**
	 * Render the content body by format.
	 *
	 * @param string $content Raw file content.
	 * @param array  $atts Shortcode attributes.
	 * @return string
	 */
	private static function render_body( $content, $atts ) {
		switch ( $atts['format'] ) {
			case 'markdown':
				return wp_kses( self::parse_markdown( $content ), self::allowed_html() );

			case 'text':
				return wpautop( esc_html( trim( $content ) ) );

			case 'raw':
			case 'code':
				$language = '' !== $atts['language'] ? ' class="language-' . esc_attr( $atts['language'] ) . '"' : '';
				return '<pre class="dgs-code"><code' . $language . '>' . esc_html( trim( $content ) ) . '</code></pre>';

			case 'html':
			default:
				return self::render_html_fragment( $content );
		}
	}

	/**
	 * Convert a full HTML document into an embeddable fragment and preserve repo-hosted styles.
	 *
	 * @param string $content Raw HTML content.
	 * @return string
	 */
	private static function render_html_fragment( $content ) {
		$styles = '';

		if ( preg_match_all( '/<style\b[^>]*>.*?<\/style>/is', $content, $matches ) ) {
			$styles = implode( "\n", $matches[0] );
		}

		$content = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $content );

		if ( preg_match( '/<body[^>]*>(.*)<\/body>/is', $content, $matches ) ) {
			$content = $matches[1];
		}

		$content = preg_replace( '/<head[^>]*>.*?<\/head>/is', '', $content );
		$content = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $content );
		$content = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $content );
		$content = preg_replace( '/<\/?(html|body|title|meta|link)[^>]*>/i', '', $content );

		$content = trim( (string) $content );
		$content = wp_kses( $content, self::allowed_html() );
		$content = self::render_approved_inner_shortcodes( $content );
		$styles  = self::sanitize_style_blocks( $styles );

		return $styles . $content;
	}

	/**
	 * Return the allowlist of safe inner shortcodes that may run inside GitHub HTML.
	 *
	 * @return array
	 */
	public static function allowed_inner_shortcodes() {
		$shortcodes = array(
			'fluentform',
			'gravityform',
			'wpforms',
			'contact-form-7',
			'ninja_form',
			'formidable',
		);

		$shortcodes = apply_filters( 'dgs_allowed_inner_shortcodes', $shortcodes );
		$shortcodes = is_array( $shortcodes ) ? $shortcodes : array();
		$shortcodes = array_map( 'sanitize_key', $shortcodes );
		$shortcodes = array_filter( $shortcodes );

		return array_values( array_unique( $shortcodes ) );
	}

	/**
	 * Whether approved inner shortcodes should render inside HTML fragments.
	 *
	 * @return bool
	 */
	private static function is_inner_shortcode_rendering_enabled() {
		return '1' === (string) get_option( 'dgs_enable_inner_shortcode_rendering', '1' );
	}

	/**
	 * Render approved inner shortcodes after sanitizing fetched GitHub HTML.
	 *
	 * Unknown shortcodes remain untouched as text.
	 *
	 * @param string $content Sanitized HTML fragment.
	 * @return string
	 */
	private static function render_approved_inner_shortcodes( $content ) {
		$content = (string) $content;

		if ( '' === $content || ! self::is_inner_shortcode_rendering_enabled() ) {
			return $content;
		}

		$allowed_shortcodes = self::allowed_inner_shortcodes();

		if ( empty( $allowed_shortcodes ) ) {
			return $content;
		}

		$pattern = '/' . get_shortcode_regex( $allowed_shortcodes ) . '/';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return $content;
		}

		$replacements = array();

		foreach ( $matches as $match ) {
			$full_match     = isset( $match[0] ) ? (string) $match[0] : '';
			$shortcode_name = isset( $match[2] ) ? sanitize_key( (string) $match[2] ) : '';

			if ( '' === $full_match || '' === $shortcode_name || ! in_array( $shortcode_name, $allowed_shortcodes, true ) ) {
				continue;
			}

			if ( array_key_exists( $full_match, $replacements ) ) {
				continue;
			}

			$replacements[ $full_match ] = self::render_single_approved_inner_shortcode( $full_match, $shortcode_name );
		}

		if ( empty( $replacements ) ) {
			return $content;
		}

		return strtr( $content, $replacements );
	}

	/**
	 * Render one approved inner shortcode safely.
	 *
	 * @param string $shortcode_markup Full shortcode text.
	 * @param string $shortcode_name   Sanitized shortcode name.
	 * @return string
	 */
	private static function render_single_approved_inner_shortcode( $shortcode_markup, $shortcode_name ) {
		if ( ! shortcode_exists( $shortcode_name ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return self::render_shortcode_notice(
					sprintf(
						/* translators: %s: shortcode name */
						__( 'The approved inner shortcode "%s" is not available on this site.', 'gitpress' ),
						$shortcode_name
					)
				);
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				return '<!-- GitPress: approved inner shortcode "' . esc_html( $shortcode_name ) . '" is not available. -->';
			}

			return '';
		}

		try {
			return (string) do_shortcode( $shortcode_markup );
		} catch ( \Throwable $e ) {
			if ( current_user_can( 'manage_options' ) ) {
				return self::render_shortcode_notice(
					sprintf(
						/* translators: %s: shortcode name */
						__( 'The approved inner shortcode "%s" failed to render. Check that the matching form plugin is active and the shortcode is valid.', 'gitpress' ),
						$shortcode_name
					)
				);
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				return '<!-- GitPress: approved inner shortcode "' . esc_html( $shortcode_name ) . '" failed: ' . esc_html( $e->getMessage() ) . ' -->';
			}

			return '';
		}
	}

	/**
	 * Render a generic admin-facing shortcode notice.
	 *
	 * @param string $message Notice message.
	 * @return string
	 */
	private static function render_shortcode_notice( $message ) {
		return '<div class="dgs-shortcode-notice">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Allow repo-hosted style tags while blocking attributes other than media/type.
	 *
	 * @param string $styles Raw style blocks.
	 * @return string
	 */
	private static function sanitize_style_blocks( $styles ) {
		if ( '' === trim( $styles ) ) {
			return '';
		}

		return wp_kses(
			$styles,
			array(
				'style' => array(
					'media' => true,
					'type'  => true,
				),
			)
		);
	}

	/**
	 * Render the optional meta row.
	 *
	 * @param array $payload File payload.
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	private static function render_meta( $payload, $atts ) {
		$parts = array();

		if ( ! empty( $atts['updated_meta'] ) && ! empty( $payload['fetched_at'] ) ) {
			$parts[] = sprintf(
				'<time class="dgs-content-block__updated" datetime="%1$s">%2$s</time>',
				esc_attr( gmdate( 'c', (int) $payload['fetched_at'] ) ),
				esc_html(
					sprintf(
						/* translators: %s is a localized date/time. */
						__( 'Synced %s', 'gitpress' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $payload['fetched_at'] )
					)
				)
			);
		}

		if ( ! empty( $payload['is_stale'] ) && ! empty( $atts['stale_notice'] ) ) {
			$parts[] = '<span class="dgs-content-block__stale">' . esc_html__( 'Showing the last cached copy because GitHub was unavailable.', 'gitpress' ) . '</span>';
		}

		if ( ! empty( $atts['source_link'] ) && ! empty( $payload['html_url'] ) ) {
			$parts[] = sprintf(
				'<a class="dgs-content-block__source" href="%1$s" target="_blank" rel="nofollow noopener">%2$s</a>',
				esc_url( $payload['html_url'] ),
				esc_html__( 'View source on GitHub', 'gitpress' )
			);
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return '<div class="dgs-content-block__meta">' . implode( ' ', $parts ) . '</div>';
	}

	/**
	 * Allowed HTML for remote HTML partials.
	 *
	 * @return array
	 */
	private static function allowed_html() {
		$allowed = wp_kses_allowed_html( 'post' );

		$generic = array(
			'class'            => true,
			'id'               => true,
			'title'            => true,
			'role'             => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
		);

		$allowed['section']    = $generic;
		$allowed['article']    = $generic;
		$allowed['aside']      = $generic;
		$allowed['figure']     = $generic;
		$allowed['figcaption'] = $generic;
		$allowed['picture']    = $generic;
		$allowed['source']     = array(
			'srcset' => true,
			'sizes'  => true,
			'type'   => true,
			'media'  => true,
		);
		$allowed['time']       = array(
			'class'    => true,
			'datetime' => true,
		);

		$svg = array(
			'class'              => true,
			'aria-hidden'        => true,
			'aria-label'         => true,
			'role'               => true,
			'width'              => true,
			'height'             => true,
			'viewBox'            => true,
			'viewbox'            => true,
			'fill'               => true,
			'stroke'             => true,
			'stroke-width'       => true,
			'stroke-linecap'     => true,
			'stroke-linejoin'    => true,
			'stroke-miterlimit'  => true,
			'stroke-dasharray'   => true,
			'stroke-dashoffset'  => true,
			'cx'                 => true,
			'cy'                 => true,
			'r'                  => true,
			'rx'                 => true,
			'ry'                 => true,
			'x'                  => true,
			'y'                  => true,
			'x1'                 => true,
			'y1'                 => true,
			'x2'                 => true,
			'y2'                 => true,
			'd'                  => true,
			'points'             => true,
			'transform'          => true,
			'opacity'            => true,
			'focusable'          => true,
			'xmlns'              => true,
		);

		$allowed['svg']      = $svg;
		$allowed['path']     = $svg;
		$allowed['circle']   = $svg;
		$allowed['rect']     = $svg;
		$allowed['line']     = $svg;
		$allowed['polyline'] = $svg;
		$allowed['polygon']  = $svg;
		$allowed['ellipse']  = $svg;
		$allowed['g']        = $svg;
		$allowed['title']    = array();
		$allowed['desc']     = array();

		return $allowed;
	}

	/**
	 * Minimal Markdown parser for headings, paragraphs, lists, blockquotes, links, and code fences.
	 *
	 * @param string $markdown Raw Markdown.
	 * @return string
	 */
	private static function parse_markdown( $markdown ) {
		$markdown        = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
		$lines           = explode( "\n", $markdown );
		$html            = '';
		$paragraph_lines = array();
		$list_type       = '';
		$list_items      = array();
		$quote_lines     = array();
		$in_code_block   = false;
		$code_language   = '';
		$code_lines      = array();

		$flush_paragraph = function () use ( &$paragraph_lines, &$html ) {
			if ( empty( $paragraph_lines ) ) {
				return;
			}

			$html            .= '<p>' . self::parse_inline_markdown( implode( ' ', $paragraph_lines ) ) . '</p>';
			$paragraph_lines = array();
		};

		$flush_list = function () use ( &$list_type, &$list_items, &$html ) {
			if ( '' === $list_type || empty( $list_items ) ) {
				return;
			}

			$html      .= '<' . $list_type . '><li>' . implode( '</li><li>', $list_items ) . '</li></' . $list_type . '>';
			$list_type  = '';
			$list_items = array();
		};

		$flush_quote = function () use ( &$quote_lines, &$html ) {
			if ( empty( $quote_lines ) ) {
				return;
			}

			$html       .= '<blockquote><p>' . self::parse_inline_markdown( implode( ' ', $quote_lines ) ) . '</p></blockquote>';
			$quote_lines = array();
		};

		foreach ( $lines as $line ) {
			if ( preg_match( '/^```([\w-]+)?\s*$/', $line, $matches ) ) {
				$flush_paragraph();
				$flush_list();
				$flush_quote();

				if ( ! $in_code_block ) {
					$in_code_block = true;
					$code_language = ! empty( $matches[1] ) ? sanitize_html_class( $matches[1] ) : '';
					$code_lines    = array();
				} else {
					$html         .= '<pre class="dgs-code"><code' . ( $code_language ? ' class="language-' . esc_attr( $code_language ) . '"' : '' ) . '>' . esc_html( implode( "\n", $code_lines ) ) . '</code></pre>';
					$in_code_block = false;
					$code_language = '';
					$code_lines    = array();
				}

				continue;
			}

			if ( $in_code_block ) {
				$code_lines[] = $line;
				continue;
			}

			if ( preg_match( '/^\s*$/', $line ) ) {
				$flush_paragraph();
				$flush_list();
				$flush_quote();
				continue;
			}

			if ( preg_match( '/^>\s?(.*)$/', $line, $matches ) ) {
				$flush_paragraph();
				$flush_list();
				$quote_lines[] = $matches[1];
				continue;
			}

			$flush_quote();

			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $matches ) ) {
				$flush_paragraph();
				$flush_list();
				$level = strlen( $matches[1] );
				$html .= '<h' . $level . '>' . self::parse_inline_markdown( $matches[2] ) . '</h' . $level . '>';
				continue;
			}

			if ( preg_match( '/^\d+\.\s+(.*)$/', $line, $matches ) ) {
				$flush_paragraph();

				if ( 'ol' !== $list_type ) {
					$flush_list();
					$list_type = 'ol';
				}

				$list_items[] = self::parse_inline_markdown( $matches[1] );
				continue;
			}

			if ( preg_match( '/^[-*]\s+(.*)$/', $line, $matches ) ) {
				$flush_paragraph();

				if ( 'ul' !== $list_type ) {
					$flush_list();
					$list_type = 'ul';
				}

				$list_items[] = self::parse_inline_markdown( $matches[1] );
				continue;
			}

			$flush_list();
			$paragraph_lines[] = trim( $line );
		}

		if ( $in_code_block ) {
			$html .= '<pre class="dgs-code"><code' . ( $code_language ? ' class="language-' . esc_attr( $code_language ) . '"' : '' ) . '>' . esc_html( implode( "\n", $code_lines ) ) . '</code></pre>';
		}

		$flush_paragraph();
		$flush_list();
		$flush_quote();

		return $html;
	}

	/**
	 * Parse inline Markdown.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function parse_inline_markdown( $text ) {
		$placeholders = array();

		$text = preg_replace_callback(
			'/`([^`]+)`/',
			function ( $matches ) use ( &$placeholders ) {
				$key                  = '%%DGS_CODE_' . count( $placeholders ) . '%%';
				$placeholders[ $key ] = '<code>' . esc_html( $matches[1] ) . '</code>';
				return $key;
			},
			$text
		);

		$text = esc_html( $text );

		$text = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			function ( $matches ) {
				return sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( $matches[2] ),
					esc_html( $matches[1] )
				);
			},
			$text
		);

		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/\*(.+?)\*/s', '<em>$1</em>', $text );
		$text = preg_replace( '/~~(.+?)~~/s', '<del>$1</del>', $text );

		foreach ( $placeholders as $key => $replacement ) {
			$text = str_replace( $key, $replacement, $text );
		}

		return $text;
	}

	/**
	 * Normalize format values.
	 *
	 * @param string $format Requested format.
	 * @return string
	 */
	private static function sanitize_format( $format ) {
		$format  = sanitize_key( $format );
		$allowed = array( 'html', 'markdown', 'text', 'code', 'raw' );

		return in_array( $format, $allowed, true ) ? $format : 'html';
	}

	/**
	 * Clamp TTL values.
	 *
	 * @param mixed $ttl Requested TTL.
	 * @return int
	 */
	private static function sanitize_ttl( $ttl ) {
		$ttl = '' === $ttl ? (int) get_option( 'dgs_cache_ttl', DGS_DEFAULT_CACHE_TTL ) : absint( $ttl );

		if ( $ttl < 60 ) {
			$ttl = 60;
		}

		if ( $ttl > DAY_IN_SECONDS ) {
			$ttl = DAY_IN_SECONDS;
		}

		return $ttl;
	}

	/**
	 * Normalize shortcode booleans.
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Sanitize the wrapper tag.
	 *
	 * @param string $wrapper Wrapper tag.
	 * @return string
	 */
	private static function sanitize_wrapper( $wrapper ) {
		$allowed = array( 'div', 'section', 'article', 'aside' );
		$wrapper = strtolower( (string) $wrapper );

		return in_array( $wrapper, $allowed, true ) ? $wrapper : 'section';
	}

	/**
	 * Sanitize schema type names.
	 *
	 * @param string $schema Schema type.
	 * @return string
	 */
	private static function sanitize_schema( $schema ) {
		$schema = trim( (string) $schema );

		return preg_match( '/^[A-Za-z][A-Za-z0-9]+$/', $schema ) ? $schema : '';
	}

	/**
	 * Sanitize an optional code language.
	 *
	 * @param string $language Explicit language.
	 * @param string $path File path.
	 * @param string $format Output format.
	 * @return string
	 */
	private static function sanitize_language( $language, $path, $format ) {
		if ( ! in_array( $format, array( 'code', 'raw' ), true ) ) {
			return '';
		}

		if ( '' !== $language ) {
			return sanitize_html_class( $language );
		}

		return sanitize_html_class( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Sanitize multiple CSS classes.
	 *
	 * @param string $classes Raw class string.
	 * @return array
	 */
	private static function sanitize_classes( $classes ) {
		$sanitized = array();

		foreach ( preg_split( '/\s+/', (string) $classes ) as $class_name ) {
			$class_name = sanitize_html_class( $class_name );

			if ( '' !== $class_name ) {
				$sanitized[] = $class_name;
			}
		}

		return $sanitized;
	}

	/**
	 * Build a safe HTML attribute string.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	private static function html_attributes( $attributes ) {
		$html = '';

		foreach ( $attributes as $name => $value ) {
			if ( true === $value ) {
				$html .= ' ' . esc_attr( $name );
				continue;
			}

			if ( '' === $value || null === $value ) {
				continue;
			}

			$html .= sprintf( ' %1$s="%2$s"', esc_attr( $name ), esc_attr( (string) $value ) );
		}

		return $html;
	}

	/**
	 * Render a visible error for admins and a comment for visitors.
	 *
	 * @param WP_Error $error Error object.
	 * @return string
	 */
	private static function render_error( $error ) {
		if ( current_user_can( 'manage_options' ) ) {
			return '<div class="dgs-error">' . esc_html( $error->get_error_message() ) . '</div>';
		}

		return '<!-- GitPress: ' . esc_html( $error->get_error_code() ) . ' -->';
	}
}
