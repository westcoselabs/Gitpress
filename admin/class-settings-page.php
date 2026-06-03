<?php
/**
 * Plugin settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGS_Settings_Page {

	/**
	 * Settings group used by options.php.
	 */
	const SETTINGS_GROUP = 'dgs_settings';

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

			<?php settings_errors(); ?>

			<?php if ( 'purged' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Cache cleared. %d entries removed.', 'gitpress' ), $purged ) ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>
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

			<?php self::render_shortcode_creator(); ?>

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
	 * Register settings for options.php handling.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			'dgs_github_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_github_token' ),
				'default'           => '',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			'dgs_github_webhook_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_webhook_secret' ),
				'default'           => '',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			'dgs_cache_ttl',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_ttl' ),
				'default'           => DGS_DEFAULT_CACHE_TTL,
			)
		);
	}

	/**
	 * Handle the purge cache form.
	 *
	 * @return void
	 */
	private static function maybe_handle_post() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $request_method ) {
			return;
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
	public static function sanitize_ttl( $ttl ) {
		$original_ttl = $ttl;
		$ttl = absint( $ttl );

		if ( $ttl < 60 ) {
			$ttl = 60;
		}

		if ( $ttl > DAY_IN_SECONDS ) {
			$ttl = DAY_IN_SECONDS;
		}

		if ( (string) $ttl !== (string) absint( $original_ttl ) ) {
			add_settings_error(
				'dgs_messages',
				'dgs_cache_ttl_clamped',
				__( 'Default cache TTL was adjusted to stay within the allowed 60-second to 1-day range.', 'gitpress' ),
				'warning'
			);
		}

		return $ttl;
	}

	/**
	 * Sanitize the GitHub token option.
	 *
	 * @param mixed $token Raw token value.
	 * @return string
	 */
	public static function sanitize_github_token( $token ) {
		if ( is_array( $token ) || is_object( $token ) ) {
			add_settings_error(
				'dgs_messages',
				'dgs_github_token_invalid',
				__( 'GitHub token could not be saved because the submitted value was invalid.', 'gitpress' ),
				'error'
			);
			return '';
		}

		return sanitize_text_field( trim( wp_unslash( (string) $token ) ) );
	}

	/**
	 * Sanitize the webhook secret option.
	 *
	 * @param mixed $secret Raw webhook secret value.
	 * @return string
	 */
	public static function sanitize_webhook_secret( $secret ) {
		if ( is_array( $secret ) || is_object( $secret ) ) {
			add_settings_error(
				'dgs_messages',
				'dgs_webhook_secret_invalid',
				__( 'Webhook secret could not be saved because the submitted value was invalid.', 'gitpress' ),
				'error'
			);
			return '';
		}

		return sanitize_text_field( trim( wp_unslash( (string) $secret ) ) );
	}

	/**
	 * Render the client-side shortcode creator UI.
	 *
	 * @return void
	 */
	private static function render_shortcode_creator() {
		$format_options = array(
			'auto'     => __( 'auto', 'gitpress' ),
			'html'     => __( 'html', 'gitpress' ),
			'markdown' => __( 'markdown', 'gitpress' ),
			'text'     => __( 'text', 'gitpress' ),
			'code'     => __( 'code', 'gitpress' ),
			'raw'      => __( 'raw', 'gitpress' ),
		);
		?>
		<h2><?php esc_html_e( 'Shortcode Creator', 'gitpress' ); ?></h2>
		<p><?php esc_html_e( 'Paste a GitHub file URL and generate a ready-to-use GitPress shortcode.', 'gitpress' ); ?></p>

		<div id="dgs-shortcode-creator" class="dgs-shortcode-creator">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="dgs-shortcode-url"><?php esc_html_e( 'GitHub file URL', 'gitpress' ); ?></label>
						</th>
						<td>
							<input
								id="dgs-shortcode-url"
								type="url"
								class="regular-text code"
								placeholder="<?php echo esc_attr( 'https://github.com/owner/repo/blob/main/path/file.html' ); ?>"
								autocomplete="off"
								spellcheck="false"
							>
							<p class="description"><?php esc_html_e( 'Supported: github.com blob/raw URLs and raw.githubusercontent.com URLs.', 'gitpress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="dgs-shortcode-format"><?php esc_html_e( 'Format', 'gitpress' ); ?></label>
						</th>
						<td>
							<select id="dgs-shortcode-format">
								<?php foreach ( $format_options as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose auto to detect the shortcode format from the file extension.', 'gitpress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="dgs-shortcode-output"><?php esc_html_e( 'Generated shortcode', 'gitpress' ); ?></label>
						</th>
						<td>
							<textarea id="dgs-shortcode-output" rows="4" class="large-text code" readonly></textarea>
						</td>
					</tr>
				</tbody>
			</table>

			<details class="dgs-shortcode-creator__advanced">
				<summary><?php esc_html_e( 'Advanced fields', 'gitpress' ); ?></summary>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="dgs-shortcode-owner"><?php esc_html_e( 'Owner', 'gitpress' ); ?></label></th>
							<td><input id="dgs-shortcode-owner" type="text" class="regular-text code" autocomplete="off" spellcheck="false"></td>
						</tr>
						<tr>
							<th scope="row"><label for="dgs-shortcode-repo"><?php esc_html_e( 'Repo', 'gitpress' ); ?></label></th>
							<td><input id="dgs-shortcode-repo" type="text" class="regular-text code" autocomplete="off" spellcheck="false"></td>
						</tr>
						<tr>
							<th scope="row"><label for="dgs-shortcode-branch"><?php esc_html_e( 'Branch', 'gitpress' ); ?></label></th>
							<td><input id="dgs-shortcode-branch" type="text" class="regular-text code" autocomplete="off" spellcheck="false"></td>
						</tr>
						<tr>
							<th scope="row"><label for="dgs-shortcode-path"><?php esc_html_e( 'Path', 'gitpress' ); ?></label></th>
							<td><input id="dgs-shortcode-path" type="text" class="large-text code" autocomplete="off" spellcheck="false"></td>
						</tr>
					</tbody>
				</table>
			</details>

			<p class="submit">
				<button type="button" class="button button-primary" id="dgs-generate-shortcode"><?php esc_html_e( 'Generate Shortcode', 'gitpress' ); ?></button>
				<button type="button" class="button" id="dgs-copy-shortcode"><?php esc_html_e( 'Copy Shortcode', 'gitpress' ); ?></button>
			</p>

			<p id="dgs-shortcode-message" class="dgs-shortcode-creator__message" role="status" aria-live="polite"></p>
		</div>

		<style>
			.dgs-shortcode-creator__advanced {
				margin-top: 1rem;
			}

			.dgs-shortcode-creator__advanced summary {
				cursor: pointer;
				font-weight: 600;
			}

			.dgs-shortcode-creator__message {
				margin-top: 0.75rem;
				margin-bottom: 0;
			}

			.dgs-shortcode-creator__message.is-error {
				color: #b91c1c;
			}

			.dgs-shortcode-creator__message.is-success {
				color: #166534;
			}
		</style>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				var creator = document.getElementById('dgs-shortcode-creator');

				if (!creator) {
					return;
				}

				var urlField = document.getElementById('dgs-shortcode-url');
				var formatField = document.getElementById('dgs-shortcode-format');
				var outputField = document.getElementById('dgs-shortcode-output');
				var ownerField = document.getElementById('dgs-shortcode-owner');
				var repoField = document.getElementById('dgs-shortcode-repo');
				var branchField = document.getElementById('dgs-shortcode-branch');
				var pathField = document.getElementById('dgs-shortcode-path');
				var messageField = document.getElementById('dgs-shortcode-message');
				var generateButton = document.getElementById('dgs-generate-shortcode');
				var copyButton = document.getElementById('dgs-copy-shortcode');
				var messages = <?php echo wp_json_encode(
					array(
						'invalid'        => __( 'Enter a valid GitHub file URL using github.com or raw.githubusercontent.com.', 'gitpress' ),
						'unsupported'    => __( 'That GitHub URL format is not supported. Use a blob URL, a github.com raw URL, or a raw.githubusercontent.com URL.', 'gitpress' ),
						'missing'        => __( 'Fill in owner, repo, branch, and path before generating the shortcode.', 'gitpress' ),
						'parsed'         => __( 'GitHub URL parsed. You can adjust the fields before generating.', 'gitpress' ),
						'generated'      => __( 'Shortcode generated.', 'gitpress' ),
						'copied'         => __( 'Shortcode copied to clipboard.', 'gitpress' ),
						'nothingToCopy'  => __( 'Generate a shortcode before copying it.', 'gitpress' ),
						'copyFailed'     => __( 'Copy failed. Select the shortcode manually and copy it.', 'gitpress' ),
					)
				); ?>;

				function setMessage(text, type) {
					messageField.textContent = text || '';
					messageField.className = 'dgs-shortcode-creator__message';

					if (type) {
						messageField.classList.add(type === 'error' ? 'is-error' : 'is-success');
					}
				}

				function parseGitHubUrl(rawUrl) {
					var parsedUrl;
					var hostname;
					var segments;

					try {
						parsedUrl = new URL(rawUrl);
					} catch (error) {
						return { error: messages.invalid };
					}

					hostname = parsedUrl.hostname.toLowerCase();
					segments = parsedUrl.pathname.replace(/^\/+|\/+$/g, '').split('/');

					if (!segments[0]) {
						return { error: messages.invalid };
					}

					if ((hostname === 'github.com' || hostname === 'www.github.com') && segments.length >= 5) {
						if (segments[2] !== 'blob' && segments[2] !== 'raw') {
							return { error: messages.unsupported };
						}

						return {
							owner: segments[0],
							repo: segments[1],
							branch: segments[3],
							path: segments.slice(4).join('/')
						};
					}

					if (hostname === 'raw.githubusercontent.com' && segments.length >= 4) {
						return {
							owner: segments[0],
							repo: segments[1],
							branch: segments[2],
							path: segments.slice(3).join('/')
						};
					}

					return { error: messages.unsupported };
				}

				function detectFormat(path) {
					var normalizedPath = path.toLowerCase();

					if (/\.(html?|xhtml)$/.test(normalizedPath)) {
						return 'html';
					}

					if (/\.(md|markdown)$/.test(normalizedPath)) {
						return 'markdown';
					}

					if (/\.txt$/.test(normalizedPath)) {
						return 'text';
					}

					if (/\.(css|js|json|php|jsx|tsx|ts)$/.test(normalizedPath)) {
						return 'code';
					}

					return 'raw';
				}

				function escapeShortcodeAttribute(value) {
					return String(value).replace(/"/g, '&quot;');
				}

				function populateFields(parsed) {
					ownerField.value = parsed.owner || '';
					repoField.value = parsed.repo || '';
					branchField.value = parsed.branch || '';
					pathField.value = parsed.path || '';
				}

				function maybeParseUrl() {
					var rawUrl = urlField.value.trim();
					var parsed;

					if (!rawUrl) {
						setMessage('', '');
						return;
					}

					parsed = parseGitHubUrl(rawUrl);

					if (parsed.error) {
						setMessage('', '');
						return;
					}

					populateFields(parsed);
					setMessage(messages.parsed, 'success');
				}

				function getFieldValues() {
					return {
						owner: ownerField.value.trim(),
						repo: repoField.value.trim(),
						branch: branchField.value.trim(),
						path: pathField.value.trim()
					};
				}

				function generateShortcode() {
					var rawUrl = urlField.value.trim();
					var parsed = rawUrl ? parseGitHubUrl(rawUrl) : null;
					var values;
					var selectedFormat;
					var shortcodeFormat;

					if (parsed && parsed.error) {
						setMessage(parsed.error, 'error');
						return;
					}

					if (parsed) {
						populateFields(parsed);
					}

					values = getFieldValues();

					if (!values.owner || !values.repo || !values.branch || !values.path) {
						setMessage(messages.missing, 'error');
						return;
					}

					selectedFormat = formatField.value;
					shortcodeFormat = selectedFormat === 'auto' ? detectFormat(values.path) : selectedFormat;

					outputField.value = '[divi_github_content owner="' + escapeShortcodeAttribute(values.owner) + '" repo="' + escapeShortcodeAttribute(values.repo) + '" branch="' + escapeShortcodeAttribute(values.branch) + '" path="' + escapeShortcodeAttribute(values.path) + '" format="' + escapeShortcodeAttribute(shortcodeFormat) + '"]';
					outputField.focus();
					outputField.select();
					setMessage(messages.generated, 'success');
				}

				function fallbackCopy() {
					outputField.focus();
					outputField.select();
					outputField.setSelectionRange(0, outputField.value.length);

					try {
						if (document.execCommand('copy')) {
							setMessage(messages.copied, 'success');
							return;
						}
					} catch (error) {
					}

					setMessage(messages.copyFailed, 'error');
				}

				function copyShortcode() {
					if (!outputField.value) {
						setMessage(messages.nothingToCopy, 'error');
						return;
					}

					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(outputField.value).then(function() {
							outputField.focus();
							outputField.select();
							setMessage(messages.copied, 'success');
						}).catch(function() {
							fallbackCopy();
						});
						return;
					}

					fallbackCopy();
				}

				generateButton.addEventListener('click', generateShortcode);
				copyButton.addEventListener('click', copyShortcode);
				urlField.addEventListener('input', maybeParseUrl);
				urlField.addEventListener('keydown', function(event) {
					if (event.key === 'Enter') {
						event.preventDefault();
						generateShortcode();
					}
				});
			});
		</script>
		<?php
	}
}
