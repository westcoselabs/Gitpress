<?php
/**
 * Page-level shortcode manager for non-Divi workflows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGS_Page_Shortcode_Manager {

	const META_KEY_SHORTCODE = '_dgs_page_shortcode';
	const META_KEY_PLACEMENT = '_dgs_page_shortcode_placement';
	const META_KEY_FULL_PAGE = '_dgs_page_shortcode_full_page';
	const META_BOX_ID        = 'dgs-page-shortcode';
	const ADMIN_BAR_ID       = 'dgs-page-shortcode';

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
				__( 'GitHub Shortcode', 'gitpress' ),
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
		$shortcode = (string) get_post_meta( $post->ID, self::META_KEY_SHORTCODE, true );
		$placement = self::sanitize_placement( get_post_meta( $post->ID, self::META_KEY_PLACEMENT, true ) );
		$full_page = self::is_full_page_enabled( $post->ID );
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
				<label for="dgs-page-shortcode-placement"><strong><?php esc_html_e( 'Render position', 'gitpress' ); ?></strong></label>
			</p>
			<p>
				<select id="dgs-page-shortcode-placement" name="dgs_page_shortcode_placement">
					<option value="before" <?php selected( $placement, 'before' ); ?>><?php esc_html_e( 'Before page content', 'gitpress' ); ?></option>
					<option value="after" <?php selected( $placement, 'after' ); ?>><?php esc_html_e( 'After page content', 'gitpress' ); ?></option>
					<option value="replace" <?php selected( $placement, 'replace' ); ?>><?php esc_html_e( 'Replace page content', 'gitpress' ); ?></option>
				</select>
			</p>
			<p>
				<label>
					<input type="checkbox" name="dgs_page_shortcode_full_page" value="1" <?php checked( $full_page ); ?>>
					<strong><?php esc_html_e( 'Full page canvas', 'gitpress' ); ?></strong>
				</label>
			</p>
			<p class="description"><?php esc_html_e( 'When enabled, the plugin bypasses the theme content wrapper and renders the shortcode as the main page body. Use this when you need the repo design to control the full viewport.', 'gitpress' ); ?></p>
			<p class="description"><?php esc_html_e( 'In full page canvas mode, the shortcode output becomes the page body and the placement setting is ignored.', 'gitpress' ); ?></p>
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

		$shortcode = isset( $_POST['dgs_page_shortcode'] ) ? trim( sanitize_textarea_field( wp_unslash( (string) $_POST['dgs_page_shortcode'] ) ) ) : '';
		$placement = isset( $_POST['dgs_page_shortcode_placement'] ) ? self::sanitize_placement( wp_unslash( (string) $_POST['dgs_page_shortcode_placement'] ) ) : 'after';
		$full_page = ! empty( $_POST['dgs_page_shortcode_full_page'] );

		if ( '' === $shortcode ) {
			delete_post_meta( $post_id, self::META_KEY_SHORTCODE );
			delete_post_meta( $post_id, self::META_KEY_PLACEMENT );
			delete_post_meta( $post_id, self::META_KEY_FULL_PAGE );
			return;
		}

		update_post_meta( $post_id, self::META_KEY_SHORTCODE, $shortcode );
		update_post_meta( $post_id, self::META_KEY_PLACEMENT, $placement );

		if ( $full_page ) {
			update_post_meta( $post_id, self::META_KEY_FULL_PAGE, '1' );
		} else {
			delete_post_meta( $post_id, self::META_KEY_FULL_PAGE );
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

		$shortcode = trim( (string) get_post_meta( $post->ID, self::META_KEY_SHORTCODE, true ) );
		$placement = self::sanitize_placement( get_post_meta( $post->ID, self::META_KEY_PLACEMENT, true ) );
		$full_page = self::is_full_page_enabled( $post->ID );
		$title     = '' === $shortcode ? __( 'Add GitHub Shortcode', 'gitpress' ) : __( 'GitHub Shortcode', 'gitpress' );
		$edit_url  = $edit_link . '#dgs-page-shortcode-panel';

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
				'title'  => esc_html( sprintf( __( 'Placement: %s', 'gitpress' ), self::placement_label( $placement ) ) ),
				'href'   => esc_url( $edit_url ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => self::ADMIN_BAR_ID . '-canvas',
				'parent' => self::ADMIN_BAR_ID,
				'title'  => esc_html( sprintf( __( 'Full Page Canvas: %s', 'gitpress' ), $full_page ? __( 'On', 'gitpress' ) : __( 'Off', 'gitpress' ) ) ),
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
		$rendered  = do_shortcode( $shortcode );

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

		$rendered = do_shortcode( $shortcode );

		if ( '' === trim( (string) $rendered ) ) {
			return;
		}

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
		exit;
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
	 * Check whether a post uses full page canvas mode.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_full_page_enabled( $post_id ) {
		return '1' === (string) get_post_meta( $post_id, self::META_KEY_FULL_PAGE, true );
	}
}
