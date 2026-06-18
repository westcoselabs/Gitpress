<?php
/**
 * Page-level shortcode manager for non-Divi workflows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGS_Page_Shortcode_Manager {

	const META_KEY_SHORTCODE   = '_dgs_page_shortcode';
	const META_KEY_PLACEMENT   = '_dgs_page_shortcode_placement';
	const META_KEY_RENDER_MODE = '_dgs_page_shortcode_render_mode';
	const META_KEY_FULL_WIDTH  = '_dgs_page_shortcode_full_width';
	const META_KEY_FULL_PAGE   = '_dgs_page_shortcode_full_page';
	const META_KEY_DIVI_LAYOUT_BACKUP = '_dgs_page_shortcode_divi_layout_backup';
	const META_KEY_DIVI_TITLE_BACKUP  = '_dgs_page_shortcode_divi_title_backup';
	const META_BOX_ID          = 'dgs-page-shortcode';
	const ADMIN_BAR_ID         = 'dgs-page-shortcode';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
			add_action( 'save_post', array( __CLASS__, 'save_meta_box' ) );
		}

		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_item' ), 85 );
		add_filter( 'the_content', array( __CLASS__, 'inject_saved_shortcode' ), 25 );
		add_filter( 'body_class', array( __CLASS__, 'filter_body_classes' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_theme_wrapped_full_width_styles' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_full_page_canvas' ), 0 );
	}

	/**
	 * Register the meta box on supported post types.
	 *
	 * @return void
	 */
	public static function register_meta_boxes() {
		foreach ( self::supported_post_types() as $post_type ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'GitPress Shortcode', 'gitpress' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the page-level shortcode UI.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		$shortcode   = (string) get_post_meta( $post->ID, self::META_KEY_SHORTCODE, true );
		$placement   = self::sanitize_placement( get_post_meta( $post->ID, self::META_KEY_PLACEMENT, true ) );
		$render_mode = self::get_render_mode( $post->ID );
		$full_width  = self::is_full_width_content_area_enabled( $post->ID );
		?>
		<div id="dgs-page-shortcode-panel">
			<?php wp_nonce_field( 'dgs_save_page_shortcode', 'dgs_page_shortcode_nonce' ); ?>
			<p><?php esc_html_e( 'Paste a GitPress shortcode here to attach repo-managed content directly to this page or post.', 'gitpress' ); ?></p>
			<p>
				<label for="dgs-page-shortcode-field"><strong><?php esc_html_e( 'Shortcode', 'gitpress' ); ?></strong></label>
			</p>
			<p>
				<textarea id="dgs-page-shortcode-field" name="dgs_page_shortcode" rows="5" style="width: 100%; font-family: Consolas, Monaco, monospace;"><?php echo esc_textarea( $shortcode ); ?></textarea>
			</p>
			<p class="description"><?php esc_html_e( 'Example: [divi_github_content owner="acme" repo="site-content" path="partials/hero.html" format="html"]', 'gitpress' ); ?></p>
			<p>
				<strong><?php esc_html_e( 'Render Mode', 'gitpress' ); ?></strong>
			</p>
			<div id="dgs-page-shortcode-render-mode">
				<p>
					<label>
						<input type="radio" name="dgs_page_shortcode_render_mode" value="theme_wrapped" <?php checked( $render_mode, 'theme_wrapped' ); ?>>
						<strong><?php esc_html_e( 'Theme Wrapped', 'gitpress' ); ?></strong>
					</label>
					<br>
					<span class="description"><?php esc_html_e( 'Keeps the normal WordPress/Divi header, menu, footer, and theme wrapper.', 'gitpress' ); ?></span>
				</p>
				<p>
					<label>
						<input type="radio" name="dgs_page_shortcode_render_mode" value="full_canvas" <?php checked( $render_mode, 'full_canvas' ); ?>>
						<strong><?php esc_html_e( 'Full Canvas', 'gitpress' ); ?></strong>
					</label>
					<br>
					<span class="description"><?php esc_html_e( 'Standalone landing page mode. The shortcode output becomes the page body and the theme/global header/footer may be bypassed.', 'gitpress' ); ?></span>
				</p>
				<p>
					<label>
						<input type="radio" name="dgs_page_shortcode_render_mode" value="gitpress_managed" <?php checked( $render_mode, 'gitpress_managed' ); ?>>
						<strong><?php esc_html_e( 'GitPress Managed', 'gitpress' ); ?></strong>
					</label>
					<br>
					<span class="description"><?php esc_html_e( 'Uses GitPress-managed global header and footer shortcodes from GitPress settings around this page\'s shortcode output.', 'gitpress' ); ?></span>
				</p>
				<p id="dgs-page-shortcode-managed-note" class="description" style="<?php echo 'gitpress_managed' === $render_mode ? '' : 'display:none;'; ?>">
					<em><?php esc_html_e( 'This page will use the global GitPress Managed header and footer shortcodes from GitPress settings.', 'gitpress' ); ?></em>
				</p>
			</div>
			<div id="dgs-page-shortcode-placement-wrap">
				<p>
					<label for="dgs-page-shortcode-placement"><strong><?php esc_html_e( 'Render position', 'gitpress' ); ?></strong></label>
				</p>
				<p>
					<select id="dgs-page-shortcode-placement" name="dgs_page_shortcode_placement">
						<option value="before" <?php selected( $placement, 'before' ); ?>><?php esc_html_e( 'Before page content', 'gitpress' ); ?></option>
						<option value="after" <?php selected( $placement, 'after' ); ?>><?php esc_html_e( 'After page content', 'gitpress' ); ?></option>
						<option value="replace" <?php selected( $placement, 'replace' ); ?>><?php esc_html_e( 'Replace page content', 'gitpress' ); ?></option>
					</select>
				</p>
				<p id="dgs-page-shortcode-placement-help" class="description"><?php esc_html_e( 'In Full Canvas or GitPress Managed mode, placement is ignored because the shortcode becomes the page body.', 'gitpress' ); ?></p>
			</div>
			<div id="dgs-page-shortcode-full-width-wrap">
				<p>
					<label>
						<input type="checkbox" id="dgs-page-shortcode-full-width" name="dgs_page_shortcode_full_width" value="1" <?php checked( $full_width ); ?>>
						<strong><?php esc_html_e( 'Full-width content area', 'gitpress' ); ?></strong>
					</label>
				</p>
				<p class="description"><?php esc_html_e( 'Enabled by default. In Theme Wrapped mode, GitPress will keep the global Divi header and footer while setting safe full-width page meta where possible, including hiding the default page title and avoiding the right sidebar.', 'gitpress' ); ?></p>
			</div>
			<style>
				#dgs-page-shortcode-placement-wrap.is-disabled,
				#dgs-page-shortcode-full-width-wrap.is-disabled {
					opacity: 0.6;
				}
			</style>
			<script>
				(function() {
					var panel = document.getElementById('dgs-page-shortcode-panel');
					if (!panel) {
						return;
					}

					var placementWrap = document.getElementById('dgs-page-shortcode-placement-wrap');
					var placementField = document.getElementById('dgs-page-shortcode-placement');
					var helper = document.getElementById('dgs-page-shortcode-placement-help');
					var fullWidthWrap = document.getElementById('dgs-page-shortcode-full-width-wrap');
					var fullWidthField = document.getElementById('dgs-page-shortcode-full-width');
					var managedNote = document.getElementById('dgs-page-shortcode-managed-note');
					var radios = panel.querySelectorAll('input[name="dgs_page_shortcode_render_mode"]');

					function updatePlacementState() {
						var selected = panel.querySelector('input[name="dgs_page_shortcode_render_mode"]:checked');
						var isManaged = selected && 'gitpress_managed' === selected.value;
						var isFullPageMode = selected && ('full_canvas' === selected.value || isManaged);

						if (placementField) {
							placementField.disabled = !!isFullPageMode;
						}

						if (placementWrap) {
							placementWrap.classList.toggle('is-disabled', !!isFullPageMode);
						}

						if (helper) {
							helper.style.display = isFullPageMode ? 'block' : 'none';
						}

						if (fullWidthField) {
							fullWidthField.disabled = !!isFullPageMode;
						}

						if (fullWidthWrap) {
							fullWidthWrap.classList.toggle('is-disabled', !!isFullPageMode);
						}

						if (managedNote) {
							managedNote.style.display = isManaged ? 'block' : 'none';
						}
					}

					radios.forEach(function(radio) {
						radio.addEventListener('change', updatePlacementState);
					});

					updatePlacementState();
				})();
			</script>
		</div>
		<?php
	}

	/**
	 * Persist the page-level shortcode fields.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['dgs_page_shortcode_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dgs_page_shortcode_nonce'] ) ), 'dgs_save_page_shortcode' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		if ( ! in_array( $post_type, self::supported_post_types(), true ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$shortcode  = isset( $_POST['dgs_page_shortcode'] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST['dgs_page_shortcode'] ) ) ) : '';
		$placement  = isset( $_POST['dgs_page_shortcode_placement'] ) ? self::sanitize_placement( wp_unslash( (string) $_POST['dgs_page_shortcode_placement'] ) ) : 'after';
		$full_width = isset( $_POST['dgs_page_shortcode_full_width'] ) ? self::sanitize_checkbox_value( wp_unslash( (string) $_POST['dgs_page_shortcode_full_width'] ) ) : self::is_full_width_content_area_enabled( $post_id );

		if ( isset( $_POST['dgs_page_shortcode_render_mode'] ) ) {
			$render_mode = self::sanitize_render_mode( wp_unslash( (string) $_POST['dgs_page_shortcode_render_mode'] ) );
		} else {
			$render_mode = ! empty( $_POST['dgs_page_shortcode_full_page'] ) ? 'full_canvas' : 'theme_wrapped';
		}

		if ( '' === $shortcode ) {
			self::restore_theme_wrapped_layout_meta( $post_id );
			delete_post_meta( $post_id, self::META_KEY_SHORTCODE );
			delete_post_meta( $post_id, self::META_KEY_PLACEMENT );
			delete_post_meta( $post_id, self::META_KEY_RENDER_MODE );
			delete_post_meta( $post_id, self::META_KEY_FULL_WIDTH );
			delete_post_meta( $post_id, self::META_KEY_FULL_PAGE );
			return;
		}

		update_post_meta( $post_id, self::META_KEY_SHORTCODE, $shortcode );
		update_post_meta( $post_id, self::META_KEY_PLACEMENT, $placement );
		update_post_meta( $post_id, self::META_KEY_RENDER_MODE, $render_mode );
		update_post_meta( $post_id, self::META_KEY_FULL_WIDTH, $full_width ? '1' : '0' );

		if ( in_array( $render_mode, array( 'full_canvas', 'gitpress_managed' ), true ) ) {
			update_post_meta( $post_id, self::META_KEY_FULL_PAGE, '1' );
		} else {
			delete_post_meta( $post_id, self::META_KEY_FULL_PAGE );
		}

		if ( 'theme_wrapped' === $render_mode && $full_width ) {
			self::apply_theme_wrapped_layout_meta( $post_id );
		} else {
			self::restore_theme_wrapped_layout_meta( $post_id );
		}
	}

	/**
	 * Add a front-end admin bar shortcut on singular editable pages.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public static function add_admin_bar_item( $wp_admin_bar ) {
		if ( is_admin() || ! is_admin_bar_showing() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$edit_link = get_edit_post_link( $post->ID );

		if ( ! $edit_link ) {
			return;
		}

		$shortcode   = trim( (string) get_post_meta( $post->ID, self::META_KEY_SHORTCODE, true ) );
		$placement   = self::sanitize_placement( get_post_meta( $post->ID, self::META_KEY_PLACEMENT, true ) );
		$render_mode = self::get_render_mode( $post->ID );
		$full_width  = self::is_full_width_content_area_enabled( $post->ID );
		$title       = '' === $shortcode ? __( 'Add GitPress Shortcode', 'gitpress' ) : __( 'GitPress Shortcode', 'gitpress' );
		$edit_url    = $edit_link . '#dgs-page-shortcode-panel';

		$wp_admin_bar->add_node(
			array(
				'id'    => self::ADMIN_BAR_ID,
				'title' => esc_html( $title ),
				'href'  => esc_url( $edit_url ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => self::ADMIN_BAR_ID . '-edit',
				'parent' => self::ADMIN_BAR_ID,
				'title'  => esc_html__( 'Edit Page Shortcode', 'gitpress' ),
				'href'   => esc_url( $edit_url ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => self::ADMIN_BAR_ID . '-placement',
				'parent' => self::ADMIN_BAR_ID,
				'title'  => esc_html(
					sprintf(
						/* translators: %s is the placement label. */
						__( 'Placement: %s', 'gitpress' ),
						in_array( $render_mode, array( 'full_canvas', 'gitpress_managed' ), true ) ? __( 'Ignored (full page mode)', 'gitpress' ) : self::placement_label( $placement )
					)
				),
				'href'   => esc_url( $edit_url ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => self::ADMIN_BAR_ID . '-render-mode',
				'parent' => self::ADMIN_BAR_ID,
				'title'  => esc_html(
					sprintf(
						/* translators: %s is the render mode label. */
						__( 'Render Mode: %s', 'gitpress' ),
						self::render_mode_label( $render_mode )
					)
				),
				'href'   => esc_url( $edit_url ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => self::ADMIN_BAR_ID . '-full-width',
				'parent' => self::ADMIN_BAR_ID,
				'title'  => esc_html(
					sprintf(
						/* translators: %s is the full-width setting status. */
						__( 'Full-Width Content Area: %s', 'gitpress' ),
						$full_width ? __( 'On', 'gitpress' ) : __( 'Off', 'gitpress' )
					)
				),
				'href'   => esc_url( $edit_url ),
			)
		);
	}

	/**
	 * Inject the saved shortcode into front-end content.
	 *
	 * @param string $content Existing post content.
	 * @return string
	 */
	public static function inject_saved_shortcode( $content ) {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() || is_feed() ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || self::is_full_page_enabled( $post_id ) ) {
			return $content;
		}

		$shortcode = trim( (string) get_post_meta( $post_id, self::META_KEY_SHORTCODE, true ) );

		if ( '' === $shortcode ) {
			return $content;
		}

		$placement = self::sanitize_placement( get_post_meta( $post_id, self::META_KEY_PLACEMENT, true ) );
		$rendered  = self::render_shortcode_markup( $shortcode, $post_id );

		if ( '' === trim( (string) $rendered ) ) {
			return $content;
		}

		switch ( $placement ) {
			case 'before':
				return $rendered . $content;
			case 'replace':
				return $rendered;
			case 'after':
			default:
				return $content . $rendered;
		}
	}

	/**
	 * Render a repo-backed page as the full document body.
	 *
	 * @return void
	 */
	public static function maybe_render_full_page_canvas() {
		if ( is_admin() || ! is_singular() || is_feed() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		if ( ! self::is_full_page_enabled( $post->ID ) ) {
			return;
		}

		$shortcode = trim( (string) get_post_meta( $post->ID, self::META_KEY_SHORTCODE, true ) );

		if ( '' === $shortcode ) {
			return;
		}

		if ( 'gitpress_managed' === self::get_render_mode( $post->ID ) ) {
			self::render_gitpress_managed_canvas( $post, $shortcode );
		} else {
			$rendered = do_shortcode( $shortcode );

			if ( '' === trim( (string) $rendered ) ) {
				return;
			}

			self::render_full_canvas( $rendered );
		}

		exit;
	}

	/**
	 * Output the Full Canvas document body (standalone landing page mode).
	 *
	 * @param string $rendered Rendered page-level shortcode markup.
	 * @return void
	 */
	private static function render_full_canvas( $rendered ) {
		status_header( 200 );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( array( 'dgs-full-page-canvas' ) ); ?>>
	<?php wp_body_open(); ?>
	<?php echo $rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php wp_footer(); ?>
</body>
</html><?php
	}

	/**
	 * Output the GitPress Managed document body: global header, page shortcode, global footer.
	 *
	 * Bypasses the active theme's get_header()/get_footer() entirely while still
	 * calling wp_head()/wp_body_open()/wp_footer() so plugin and theme assets,
	 * and the admin bar, continue to work as expected.
	 *
	 * @param WP_Post $post           Current queried post.
	 * @param string  $page_shortcode Page-level shortcode string.
	 * @return void
	 */
	private static function render_gitpress_managed_canvas( $post, $page_shortcode ) {
		$header_html = self::render_global_managed_shortcode( 'dgs_managed_header_shortcode' );
		$rendered_body = do_shortcode( $page_shortcode );
		$footer_html = self::render_global_managed_shortcode( 'dgs_managed_footer_shortcode' );

		if ( '' === trim( (string) $rendered_body ) ) {
			return;
		}

		$body_classes = array( 'dgs-gitpress-managed', 'dgs-full-page', 'dgs-page-' . (int) $post->ID );

		status_header( 200 );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( $body_classes ); ?>>
	<?php wp_body_open(); ?>

	<div class="dgs-managed-page">
		<?php if ( '' !== $header_html ) : ?>
		<header class="dgs-managed-header">
			<?php echo $header_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</header>
		<?php endif; ?>

		<main class="dgs-managed-main" id="main">
			<?php echo $rendered_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</main>

		<?php if ( '' !== $footer_html ) : ?>
		<footer class="dgs-managed-footer">
			<?php echo $footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</footer>
		<?php endif; ?>
	</div>

	<?php wp_footer(); ?>
</body>
</html><?php
	}

	/**
	 * Render a GitPress Managed global header/footer option as shortcode output.
	 *
	 * Never lets a failure in the global shortcode take down the rest of the page.
	 *
	 * @param string $option_name Option name holding the shortcode string.
	 * @return string
	 */
	private static function render_global_managed_shortcode( $option_name ) {
		$shortcode = trim( (string) get_option( $option_name, '' ) );

		if ( '' === $shortcode ) {
			return '';
		}

		try {
			$rendered = do_shortcode( $shortcode );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				return '<!-- GitPress Managed: ' . esc_html( $option_name ) . ' failed to render: ' . esc_html( $e->getMessage() ) . ' -->';
			}

			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="dgs-error">' . esc_html__( 'A GitPress Managed shortcode failed to render. Check GitPress settings.', 'gitpress' ) . '</div>';
			}

			return '';
		}

		if ( '' === trim( (string) $rendered ) ) {
			return '';
		}

		return (string) $rendered;
	}

	/**
	 * Add scoped body classes for GitPress theme-wrapped pages.
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public static function filter_body_classes( $classes ) {
		$post_id = self::get_current_singular_post_id();

		if ( ! $post_id || ! self::has_shortcode_assignment( $post_id ) || self::is_full_page_enabled( $post_id ) ) {
			return $classes;
		}

		$classes[] = 'dgs-theme-wrapped';

		if ( self::is_full_width_content_area_enabled( $post_id ) ) {
			$classes[] = 'dgs-full-width-content';

			if ( apply_filters( 'dgs_theme_wrapped_full_width_remove_top_gap', true, $post_id ) ) {
				$classes[] = 'dgs-remove-top-gap';
			}
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Print tightly scoped styles only for GitPress full-width theme-wrapped pages.
	 *
	 * @return void
	 */
	public static function print_theme_wrapped_full_width_styles() {
		$post_id = self::get_current_singular_post_id();

		if ( ! $post_id || ! self::should_use_theme_wrapped_full_width( $post_id ) ) {
			return;
		}

		$remove_top_gap = apply_filters( 'dgs_theme_wrapped_full_width_remove_top_gap', true, $post_id );

		?>
		<style id="dgs-theme-wrapped-full-width">
			body.dgs-theme-wrapped.dgs-full-width-content .entry-title,
			body.dgs-theme-wrapped.dgs-full-width-content .main_title {
				display: none !important;
				margin: 0 !important;
				padding: 0 !important;
			}

			body.dgs-theme-wrapped.dgs-full-width-content #sidebar {
				display: none;
			}

			body.dgs-theme-wrapped.dgs-full-width-content #left-area {
				float: none;
				width: 100%;
				padding-right: 0;
			}

			body.dgs-theme-wrapped.dgs-full-width-content #content-area::before,
			body.dgs-theme-wrapped.dgs-full-width-content .container::before {
				display: none;
			}

			body.dgs-theme-wrapped.dgs-full-width-content .container {
				max-width: none;
				width: 100%;
			}

			body.dgs-theme-wrapped.dgs-full-width-content .dgs-gitpress-full-width {
				width: 100vw;
				max-width: none;
				margin-left: calc(50% - 50vw);
				margin-right: calc(50% - 50vw);
			}
			<?php if ( $remove_top_gap ) : ?>

			body.dgs-theme-wrapped.dgs-full-width-content #et-main-area,
			body.dgs-theme-wrapped.dgs-full-width-content #main-content {
				margin-top: 0 !important;
				padding-top: 0 !important;
			}

			body.dgs-theme-wrapped.dgs-full-width-content .et-l--post,
			body.dgs-theme-wrapped.dgs-full-width-content .et_builder_inner_content,
			body.dgs-theme-wrapped.dgs-full-width-content #left-area {
				margin-top: 0 !important;
				padding-top: 0 !important;
			}

			body.dgs-theme-wrapped.dgs-full-width-content #main-content .container {
				padding-top: 0 !important;
				margin-top: 0 !important;
				max-width: none !important;
				width: 100% !important;
			}

			body.dgs-theme-wrapped.dgs-full-width-content .entry-content {
				padding-top: 0 !important;
				margin-top: 0 !important;
			}

			body.dgs-theme-wrapped.dgs-full-width-content .dgs-gitpress-content {
				margin-top: 0 !important;
				padding-top: 0 !important;
			}

			body.dgs-theme-wrapped.dgs-full-width-content .dgs-gitpress-full-width {
				margin-top: 0 !important;
				padding-top: 0 !important;
			}

			body.dgs-theme-wrapped.dgs-full-width-content .entry-content > :first-child,
			body.dgs-theme-wrapped.dgs-full-width-content .dgs-gitpress-content:first-child,
			body.dgs-theme-wrapped.dgs-full-width-content .dgs-gitpress-full-width:first-child,
			body.dgs-theme-wrapped.dgs-full-width-content .dgs-gitpress-full-width > :first-child {
				margin-top: 0 !important;
				padding-top: 0 !important;
			}

			body.dgs-theme-wrapped.dgs-full-width-content .dgs-gitpress-full-width .et_pb_section:first-child,
			body.dgs-theme-wrapped.dgs-full-width-content .dgs-gitpress-full-width section:first-child {
				margin-top: 0 !important;
				padding-top: 0 !important;
			}

			body.dgs-theme-wrapped.dgs-full-width-content article,
			body.dgs-theme-wrapped.dgs-full-width-content .type-page,
			body.dgs-theme-wrapped.dgs-full-width-content .hentry {
				margin-top: 0 !important;
				padding-top: 0 !important;
			}
			<?php endif; ?>
		</style>
		<?php
	}

	/**
	 * Supported editable public post types.
	 *
	 * @return array
	 */
	private static function supported_post_types() {
		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'names'
		);

		unset( $post_types['attachment'] );

		return array_values( $post_types );
	}

	/**
	 * Normalize placement values.
	 *
	 * @param string $placement Placement string.
	 * @return string
	 */
	private static function sanitize_placement( $placement ) {
		$placement = sanitize_key( (string) $placement );
		$allowed   = array( 'before', 'after', 'replace' );

		return in_array( $placement, $allowed, true ) ? $placement : 'after';
	}

	/**
	 * Human label for placement values.
	 *
	 * @param string $placement Placement string.
	 * @return string
	 */
	private static function placement_label( $placement ) {
		switch ( $placement ) {
			case 'before':
				return __( 'Before content', 'gitpress' );
			case 'replace':
				return __( 'Replace content', 'gitpress' );
			case 'after':
			default:
				return __( 'After content', 'gitpress' );
		}
	}

	/**
	 * Normalize checkbox values.
	 *
	 * @param string $value Raw checkbox value.
	 * @return bool
	 */
	private static function sanitize_checkbox_value( $value ) {
		return '1' === (string) $value;
	}

	/**
	 * Normalize render mode values.
	 *
	 * @param string $value Render mode string.
	 * @return string
	 */
	public static function sanitize_render_mode( $value ) {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'theme_wrapped', 'full_canvas', 'gitpress_managed' );

		return in_array( $value, $allowed, true ) ? $value : 'theme_wrapped';
	}

	/**
	 * Get the effective render mode for a post.
	 *
	 * Honors the legacy full page canvas flag for backward compatibility.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_render_mode( $post_id ) {
		$render_mode = get_post_meta( $post_id, self::META_KEY_RENDER_MODE, true );

		if ( '' !== (string) $render_mode ) {
			return self::sanitize_render_mode( $render_mode );
		}

		return '1' === (string) get_post_meta( $post_id, self::META_KEY_FULL_PAGE, true ) ? 'full_canvas' : 'theme_wrapped';
	}

	/**
	 * Whether the theme-wrapped content area should be full width.
	 *
	 * Defaults to enabled when no explicit preference has been saved yet.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_full_width_content_area_enabled( $post_id ) {
		$value = get_post_meta( $post_id, self::META_KEY_FULL_WIDTH, true );

		if ( '' === (string) $value ) {
			return true;
		}

		return self::sanitize_checkbox_value( $value );
	}

	/**
	 * Human label for render mode values.
	 *
	 * @param string $render_mode Render mode string.
	 * @return string
	 */
	private static function render_mode_label( $render_mode ) {
		switch ( self::sanitize_render_mode( $render_mode ) ) {
			case 'full_canvas':
				return __( 'Full Canvas', 'gitpress' );
			case 'gitpress_managed':
				return __( 'GitPress Managed', 'gitpress' );
			case 'theme_wrapped':
			default:
				return __( 'Theme Wrapped', 'gitpress' );
		}
	}

	/**
	 * Apply safe Divi layout meta for theme-wrapped GitPress pages.
	 *
	 * Keeps the normal theme shell while encouraging a clean, full-width content area.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function apply_theme_wrapped_layout_meta( $post_id ) {
		if ( ! metadata_exists( 'post', $post_id, self::META_KEY_DIVI_LAYOUT_BACKUP ) ) {
			$current_layout = get_post_meta( $post_id, '_et_pb_page_layout', true );
			update_post_meta( $post_id, self::META_KEY_DIVI_LAYOUT_BACKUP, '' === (string) $current_layout ? '__dgs_empty__' : (string) $current_layout );
		}

		if ( ! metadata_exists( 'post', $post_id, self::META_KEY_DIVI_TITLE_BACKUP ) ) {
			$current_title = get_post_meta( $post_id, '_et_pb_show_title', true );
			update_post_meta( $post_id, self::META_KEY_DIVI_TITLE_BACKUP, '' === (string) $current_title ? '__dgs_empty__' : (string) $current_title );
		}

		update_post_meta( $post_id, '_et_pb_page_layout', 'et_full_width_page' );
		update_post_meta( $post_id, '_et_pb_show_title', 'off' );
	}

	/**
	 * Restore Divi page meta when GitPress full-width theme wrapping is disabled.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function restore_theme_wrapped_layout_meta( $post_id ) {
		if ( metadata_exists( 'post', $post_id, self::META_KEY_DIVI_LAYOUT_BACKUP ) ) {
			$layout_backup = get_post_meta( $post_id, self::META_KEY_DIVI_LAYOUT_BACKUP, true );

			if ( '__dgs_empty__' === (string) $layout_backup ) {
				delete_post_meta( $post_id, '_et_pb_page_layout' );
			} else {
				update_post_meta( $post_id, '_et_pb_page_layout', $layout_backup );
			}

			delete_post_meta( $post_id, self::META_KEY_DIVI_LAYOUT_BACKUP );
		}

		if ( metadata_exists( 'post', $post_id, self::META_KEY_DIVI_TITLE_BACKUP ) ) {
			$title_backup = get_post_meta( $post_id, self::META_KEY_DIVI_TITLE_BACKUP, true );

			if ( '__dgs_empty__' === (string) $title_backup ) {
				delete_post_meta( $post_id, '_et_pb_show_title' );
			} else {
				update_post_meta( $post_id, '_et_pb_show_title', $title_backup );
			}

			delete_post_meta( $post_id, self::META_KEY_DIVI_TITLE_BACKUP );
		}
	}

	/**
	 * Render shortcode markup with GitPress wrapper classes when needed.
	 *
	 * @param string $shortcode Shortcode string.
	 * @param int    $post_id   Post ID.
	 * @return string
	 */
	private static function render_shortcode_markup( $shortcode, $post_id ) {
		$rendered = do_shortcode( $shortcode );

		if ( '' === trim( (string) $rendered ) ) {
			return '';
		}

		$classes = array( 'dgs-gitpress-content' );

		if ( self::should_use_theme_wrapped_full_width( $post_id ) ) {
			$classes[] = 'dgs-gitpress-full-width';
		}

		$markup = sprintf(
			'<div class="%1$s">%2$s</div>',
			esc_attr( implode( ' ', $classes ) ),
			$rendered
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$markup = "<!-- DGS GitPress wrapper starts here -->\n" . $markup;
		}

		return $markup;
	}

	/**
	 * Determine whether the current page should use theme-wrapped full-width styling.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function should_use_theme_wrapped_full_width( $post_id ) {
		return self::has_shortcode_assignment( $post_id )
			&& 'theme_wrapped' === self::get_render_mode( $post_id )
			&& self::is_full_width_content_area_enabled( $post_id );
	}

	/**
	 * Check whether the post has a saved GitPress shortcode.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function has_shortcode_assignment( $post_id ) {
		return '' !== trim( (string) get_post_meta( $post_id, self::META_KEY_SHORTCODE, true ) );
	}

	/**
	 * Get the current singular post ID when available.
	 *
	 * @return int
	 */
	private static function get_current_singular_post_id() {
		if ( is_admin() || ! is_singular() ) {
			return 0;
		}

		$post = get_queried_object();

		return ( $post instanceof WP_Post ) ? (int) $post->ID : 0;
	}

	/**
	 * Check whether a post uses full page canvas mode.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_full_page_enabled( $post_id ) {
		return in_array( self::get_render_mode( $post_id ), array( 'full_canvas', 'gitpress_managed' ), true );
	}
}
