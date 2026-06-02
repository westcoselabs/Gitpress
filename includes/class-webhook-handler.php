<?php
/**
 * GitHub webhook handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGS_Webhook_Handler {

	/**
	 * Register REST hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_route' ) );
	}

	/**
	 * Register the webhook route.
	 *
	 * @return void
	 */
	public static function register_webhook_route() {
		register_rest_route(
			'dgs/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return the webhook URL to show in settings.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		return rest_url( 'dgs/v1/webhook' );
	}

	/**
	 * Handle GitHub webhook requests.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_webhook( WP_REST_Request $request ) {
		$payload   = (string) $request->get_body();
		$signature = (string) $request->get_header( 'X-Hub-Signature-256' );
		$event     = (string) $request->get_header( 'X-GitHub-Event' );

		if ( ! DGS_GitHub_API::verify_webhook_signature( $payload, $signature ) ) {
			return new WP_Error(
				'dgs_invalid_webhook_signature',
				__( 'GitHub webhook signature verification failed.', 'gitpress' ),
				array( 'status' => 403 )
			);
		}

		if ( 'ping' === $event ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'message' => __( 'Webhook received.', 'gitpress' ),
				),
				200
			);
		}

		if ( 'push' !== $event ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'message' => __( 'Event ignored. Only push events invalidate cache.', 'gitpress' ),
				),
				200
			);
		}

		$data = json_decode( $payload, true );

		if ( ! is_array( $data ) || empty( $data['repository']['full_name'] ) ) {
			return new WP_Error(
				'dgs_invalid_webhook_payload',
				__( 'GitHub webhook payload was invalid.', 'gitpress' ),
				array( 'status' => 400 )
			);
		}

		$repository = explode( '/', (string) $data['repository']['full_name'] );
		$branch     = self::branch_from_ref( isset( $data['ref'] ) ? (string) $data['ref'] : '' );
		$paths      = self::collect_changed_paths( $data );

		if ( 2 !== count( $repository ) || '' === $branch ) {
			return new WP_Error(
				'dgs_invalid_webhook_repository',
				__( 'GitHub webhook did not contain a usable repository or branch.', 'gitpress' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $paths ) ) {
			$purged = DGS_Cache_Handler::purge_repository_branch( $repository[0], $repository[1], $branch );
		} else {
			$purged = DGS_Cache_Handler::purge_paths( $repository[0], $repository[1], $branch, $paths );
		}

		do_action( 'dgs_cache_invalidated', $repository[0] . '/' . $repository[1], $paths, $branch, $purged );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'owner'   => $repository[0],
				'repo'    => $repository[1],
				'branch'  => $branch,
				'paths'   => array_values( array_unique( $paths ) ),
				'purged'  => $purged,
				'message' => __( 'Cache invalidation completed.', 'gitpress' ),
			),
			200
		);
	}

	/**
	 * Normalize refs/heads/main to main.
	 *
	 * @param string $ref Git ref.
	 * @return string
	 */
	private static function branch_from_ref( $ref ) {
		if ( 0 === strpos( $ref, 'refs/heads/' ) ) {
			return substr( $ref, 11 );
		}

		return '';
	}

	/**
	 * Collect changed paths from a push payload.
	 *
	 * @param array $data Push payload.
	 * @return array
	 */
	private static function collect_changed_paths( $data ) {
		$paths = array();

		if ( empty( $data['commits'] ) || ! is_array( $data['commits'] ) ) {
			return $paths;
		}

		foreach ( $data['commits'] as $commit ) {
			if ( ! is_array( $commit ) ) {
				continue;
			}

			foreach ( array( 'added', 'modified', 'removed' ) as $bucket ) {
				if ( empty( $commit[ $bucket ] ) || ! is_array( $commit[ $bucket ] ) ) {
					continue;
				}

				foreach ( $commit[ $bucket ] as $path ) {
					$normalized = DGS_Cache_Handler::normalize_path( (string) $path );

					if ( '' !== $normalized ) {
						$paths[] = $normalized;
					}
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}
}
