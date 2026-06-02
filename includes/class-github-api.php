<?php
/**
 * GitHub API client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGS_GitHub_API {

	const API_BASE_URL = 'https://api.github.com';

	/**
	 * Fetch a file from GitHub's contents API.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param string $file_path Path inside the repo.
	 * @param string $branch Branch name.
	 * @return array|WP_Error
	 */
	public function get_file_content( $owner, $repo, $file_path, $branch = 'main' ) {
		$file_path = DGS_Cache_Handler::normalize_path( $file_path );
		$url       = $this->build_contents_url( $owner, $repo, $file_path, $branch );
		$response  = wp_remote_get(
			$url,
			array(
				'headers' => $this->headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'dgs_github_api_error',
				$this->extract_error_message( $status_code, $data )
			);
		}

		if ( ! is_array( $data ) || empty( $data['type'] ) || 'file' !== $data['type'] ) {
			return new WP_Error(
				'dgs_invalid_github_file',
				__( 'GitHub returned something other than a file.', 'gitpress' )
			);
		}

		$content = $this->extract_content( $data );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return array(
			'owner'      => sanitize_text_field( $owner ),
			'repo'       => sanitize_text_field( $repo ),
			'branch'     => sanitize_text_field( $branch ),
			'path'       => $file_path,
			'content'    => $content,
			'sha'        => isset( $data['sha'] ) ? sanitize_text_field( (string) $data['sha'] ) : '',
			'html_url'   => isset( $data['html_url'] ) ? esc_url_raw( (string) $data['html_url'] ) : '',
			'download'   => isset( $data['download_url'] ) ? esc_url_raw( (string) $data['download_url'] ) : '',
			'size'       => isset( $data['size'] ) ? absint( $data['size'] ) : 0,
			'fetched_at' => time(),
			'is_stale'   => false,
		);
	}

	/**
	 * Verify a GitHub webhook signature.
	 *
	 * @param string $payload Raw request body.
	 * @param string $signature Signature header.
	 * @return bool
	 */
	public static function verify_webhook_signature( $payload, $signature ) {
		$secret = (string) get_option( 'dgs_github_webhook_secret', '' );

		if ( '' === $secret || '' === $signature ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Build a contents API URL for a repo file.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param string $file_path Path inside the repo.
	 * @param string $branch Branch name.
	 * @return string
	 */
	private function build_contents_url( $owner, $repo, $file_path, $branch ) {
		$segments = array_map( 'rawurlencode', explode( '/', $file_path ) );
		$path     = implode( '/', $segments );
		$base     = self::API_BASE_URL . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/contents/' . $path;

		return add_query_arg( 'ref', $branch, $base );
	}

	/**
	 * Build GitHub API headers.
	 *
	 * @return array
	 */
	private function headers() {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => $this->user_agent(),
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		$token = (string) get_option( 'dgs_github_token', '' );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Build a valid GitHub API user agent.
	 *
	 * @return string
	 */
	private function user_agent() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return 'GitPress/' . DGS_VERSION . ( $host ? ' ' . $host : ' WordPress' );
	}

	/**
	 * Extract file content from a contents API response.
	 *
	 * @param array $data JSON payload.
	 * @return string|WP_Error
	 */
	private function extract_content( $data ) {
		if ( ! empty( $data['content'] ) && 'base64' === ( $data['encoding'] ?? '' ) ) {
			$decoded = base64_decode( (string) $data['content'], true );

			if ( false !== $decoded ) {
				return $decoded;
			}
		}

		if ( ! empty( $data['download_url'] ) ) {
			$response = wp_remote_get(
				esc_url_raw( (string) $data['download_url'] ),
				array(
					'headers' => array(
						'User-Agent' => $this->user_agent(),
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
				return wp_remote_retrieve_body( $response );
			}
		}

		return new WP_Error(
			'dgs_unsupported_github_file',
			__( 'GitHub did not return file contents for this path. Keep rendered partials small and text-based.', 'gitpress' )
		);
	}

	/**
	 * Normalize GitHub API error messages.
	 *
	 * @param int   $status_code HTTP status.
	 * @param array $data JSON payload.
	 * @return string
	 */
	private function extract_error_message( $status_code, $data ) {
		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			return sanitize_text_field( (string) $data['message'] );
		}

		if ( 404 === $status_code ) {
			return __( 'GitHub file not found. Check the owner, repo, branch, and path values.', 'gitpress' );
		}

		if ( 403 === $status_code ) {
			return __( 'GitHub rejected the request. Check your token or increase the cache TTL.', 'gitpress' );
		}

		return __( 'GitHub could not return the requested file.', 'gitpress' );
	}
}
