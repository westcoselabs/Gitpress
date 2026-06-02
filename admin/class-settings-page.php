<?php
/**
 * Plugin settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGS_Settings_Page {

	/**
	 * Render the admin settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gitpress' ) );
		}

		self::maybe_handle_post();

		$status         = isset( $_GET['dgs_status'] ) ? sanitize_key( wp_unslash( $_GET['dgs_status'] ) ) : '';
		$purged         = isset( $_GET['dgs_purged'] ) ? absint( $_GET['dgs_purged'] ) : 0;
		$github_token   = (string) get_option( 'dgs_github_token', '' );
		$webhook_secret = (string) get_option( 'dgs_github_webhook_secret', '' );
		$cache_ttl      = absint( get_option( 'dgs_cache_ttl', DGS_DEFAULT_CACHE_TTL ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GitPress', 'gitpress' ); ?></h1>
			<p><?php esc_html_e( 'Use GitHub as the source of truth for HTML partials, Markdown, text, or code snippets, then render them server-side inside any WordPress theme with a shortcode.', 'gitpress' ); ?></p>

			<?php if ( 'saved' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'gitpress' ); ?></p></div>
			<?php elseif ( 'purged' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Cache cleared. %d entries removed.', 'gitpress' ), $purged ) ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'dgs_save_settings', 'dgs_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="dgs-github-token"><?php esc_html_e( 'GitHub token', 'gitpress' ); ?></label></th>
							<td>
								<input id="dgs-github-token" name="dgs_github_token" type="password" class="regular-text" value="<?php echo esc_attr( $github_token ); ?>">
								<p class="description"><?php esc_html_e( 'Optional for public repositories, required for private repositories. Prefer a fine-grained token with read-only contents access.', 'gitpress' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dgs-cache-ttl"><?php esc_html_e( 'Default cache TTL', 'gitpress' ); ?></label></th>
							<td>
								<input id="dgs-cache-ttl" name="dgs_cache_ttl" type="number" min="60" max="<?php echo esc_attr( DAY_IN_SECONDS ); ?>" class="small-text" value="<?php echo esc_attr( $cache_ttl ); ?>">
								<p class="description"><?php esc_html_e( 'Seconds to keep GitHub content before checking again. Webhooks can still invalidate changed files immediately.', 'gitpress' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dgs-webhook-secret"><?php esc_html_e( 'Webhook secret', 'gitpress' ); ?></label></th>
							<td>
								<input id="dgs-webhook-secret" name="dgs_github_webhook_secret" type="password" class="regular-text" value="<?php echo esc_attr( $webhook_secret ); ?>">
								<p class="description"><?php esc_html_e( 'Use the same secret when you configure the GitHub repository webhook.', 'gitpress' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Webhook URL', 'gitpress' ); ?></th>
							<td>
								<code><?php echo esc_html( DGS_Webhook_Handler::get_webhook_url() ); ?></code>
								<p class="description"><?php esc_html_e( 'Create a push-event webhook in GitHub so changed files purge their matching cache entries.', 'gitpress' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save settings', 'gitpress' ), 'primary', 'dgs_save_settings_submit' ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Shortcode examples', 'gitpress' ); ?></h2>
			<pre><code>[divi_github_content owner="acme" repo="site-content" path="partials/hero.html" branch="main" format="html"]</code></pre>
			<pre><code>[divi_github_content url="https://github.com/acme/site-content/blob/main/partials/hero.html" format="html"]</code></pre>

			<h2><?php esc_html_e( 'Recommended SEO-safe workflow', 'gitpress' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Store render-ready HTML partials or simple Markdown in GitHub.', 'gitpress' ); ?></li>
				<li><?php esc_html_e( 'Place the shortcode inside a Divi Code or Text module so WordPress renders the content on the server.', 'gitpress' ); ?></li>
				<li><?php esc_html_e( 'Avoid iframes and front-end API fetches when that content needs to rank in search results.', 'gitpress' ); ?></li>
				<li><?php esc_html_e( 'Use webhooks for fast updates instead of lowering cache TTL too aggressively.', 'gitpress' ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'Cache maintenance', 'gitpress' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'dgs_purge_cache', 'dgs_purge_cache_nonce' ); ?>
				<?php submit_button( __( 'Purge cache now', 'gitpress' ), 'secondary', 'dgs_clear_cache_submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle settings and purge forms.
	 *
	 * @return void
	 */
	private static function maybe_handle_post() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $request_method ) {
			return;
		}

		if ( isset( $_POST['dgs_save_settings_submit'] ) ) {
			check_admin_referer( 'dgs_save_settings', 'dgs_nonce' );

			$github_token   = isset( $_POST['dgs_github_token'] ) ? sanitize_text_field( trim( wp_unslash( (string) $_POST['dgs_github_token'] ) ) ) : '';
			$webhook_secret = isset( $_POST['dgs_github_webhook_secret'] ) ? sanitize_text_field( trim( wp_unslash( (string) $_POST['dgs_github_webhook_secret'] ) ) ) : '';
			$cache_ttl      = isset( $_POST['dgs_cache_ttl'] ) ? self::sanitize_ttl( $_POST['dgs_cache_ttl'] ) : DGS_DEFAULT_CACHE_TTL;

			update_option( 'dgs_github_token', $github_token, false );
			update_option( 'dgs_github_webhook_secret', $webhook_secret, false );
			update_option( 'dgs_cache_ttl', $cache_ttl, false );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'gitpress',
						'dgs_status' => 'saved',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( isset( $_POST['dgs_clear_cache_submit'] ) ) {
			check_admin_referer( 'dgs_purge_cache', 'dgs_purge_cache_nonce' );

			$purged = DGS_Cache_Handler::clear_all();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'gitpress',
						'dgs_status' => 'purged',
						'dgs_purged' => $purged,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Clamp TTL values to a safe range.
	 *
	 * @param mixed $ttl Raw TTL value.
	 * @return int
	 */
	private static function sanitize_ttl( $ttl ) {
		$ttl = absint( $ttl );

		if ( $ttl < 60 ) {
			$ttl = 60;
		}

		if ( $ttl > DAY_IN_SECONDS ) {
			$ttl = DAY_IN_SECONDS;
		}

		return $ttl;
	}
}
