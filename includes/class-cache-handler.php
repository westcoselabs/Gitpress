<?php
/**
 * Cache storage and invalidation helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGS_Cache_Handler {

	/**
	 * Normalize a repository path.
	 *
	 * @param string $path Path inside the repo.
	 * @return string
	 */
	public static function normalize_path( $path ) {
		return trim( wp_normalize_path( (string) $path ), '/' );
	}

	/**
	 * Build a deterministic cache key for a repo file and format.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param string $file_path Path inside the repo.
	 * @param string $branch Branch name.
	 * @param string $format Output format.
	 * @return string
	 */
	public static function generate_key( $owner, $repo, $file_path, $branch = 'main', $format = 'html' ) {
		return md5(
			wp_json_encode(
				array(
					'owner'  => strtolower( sanitize_text_field( $owner ) ),
					'repo'   => strtolower( sanitize_text_field( $repo ) ),
					'branch' => sanitize_text_field( $branch ),
					'path'   => self::normalize_path( $file_path ),
					'format' => sanitize_key( $format ),
				)
			)
		);
	}

	/**
	 * Read a fresh transient cache entry.
	 *
	 * @param string $cache_key Cache key.
	 * @return array|false
	 */
	public static function get( $cache_key ) {
		$cached = get_transient( self::transient_name( $cache_key ) );

		return is_array( $cached ) ? $cached : false;
	}

	/**
	 * Read the last good payload for stale fallback.
	 *
	 * @param string $cache_key Cache key.
	 * @return array|false
	 */
	public static function get_last_good( $cache_key ) {
		$cached = get_option( self::last_good_option_name( $cache_key ), false );

		return is_array( $cached ) ? $cached : false;
	}

	/**
	 * Persist a payload into transient cache and fallback storage.
	 *
	 * @param string $cache_key Cache key.
	 * @param array  $data Payload to store.
	 * @param int    $expiration Expiration in seconds.
	 * @param array  $meta Index metadata.
	 * @return bool
	 */
	public static function set( $cache_key, $data, $expiration = DGS_DEFAULT_CACHE_TTL, $meta = array() ) {
		$stored = set_transient( self::transient_name( $cache_key ), $data, $expiration );

		update_option( self::last_good_option_name( $cache_key ), $data, false );

		if ( ! empty( $meta ) ) {
			self::remember_index_entry( $cache_key, $meta );
		}

		return $stored;
	}

	/**
	 * Delete one cache entry and its fallback snapshot.
	 *
	 * @param string $cache_key Cache key.
	 * @return bool
	 */
	public static function delete( $cache_key ) {
		$deleted = delete_transient( self::transient_name( $cache_key ) );

		delete_option( self::last_good_option_name( $cache_key ) );
		self::remove_index_entry( $cache_key );

		return $deleted;
	}

	/**
	 * Clear all plugin caches.
	 *
	 * @return int Number of entries removed.
	 */
	public static function clear_all() {
		$index = self::get_index();
		$count = count( $index );

		foreach ( array_keys( $index ) as $cache_key ) {
			delete_transient( self::transient_name( $cache_key ) );
			delete_option( self::last_good_option_name( $cache_key ) );
		}

		update_option( DGS_CACHE_INDEX_OPTION, array(), false );

		return $count;
	}

	/**
	 * Purge caches matching repo, branch, and changed paths.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param string $branch Branch name.
	 * @param array  $paths Changed repo paths.
	 * @return int
	 */
	public static function purge_paths( $owner, $repo, $branch, $paths ) {
		$owner   = strtolower( sanitize_text_field( $owner ) );
		$repo    = strtolower( sanitize_text_field( $repo ) );
		$branch  = sanitize_text_field( $branch );
		$paths   = array_map( array( __CLASS__, 'normalize_path' ), array_filter( (array) $paths ) );
		$index   = self::get_index();
		$deleted = 0;

		foreach ( $index as $cache_key => $entry ) {
			$is_repo_match = isset( $entry['owner'], $entry['repo'] ) && $owner === $entry['owner'] && $repo === $entry['repo'];
			$is_branch_ok  = isset( $entry['branch'] ) && $branch === $entry['branch'];
			$is_path_ok    = isset( $entry['path'] ) && in_array( $entry['path'], $paths, true );

			if ( $is_repo_match && $is_branch_ok && $is_path_ok ) {
				self::delete( $cache_key );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Purge every cache entry for a repo branch.
	 *
	 * @param string      $owner Repository owner.
	 * @param string      $repo Repository name.
	 * @param string|null $branch Branch name or null for all branches.
	 * @return int
	 */
	public static function purge_repository_branch( $owner, $repo, $branch = null ) {
		$owner   = strtolower( sanitize_text_field( $owner ) );
		$repo    = strtolower( sanitize_text_field( $repo ) );
		$branch  = null === $branch ? null : sanitize_text_field( $branch );
		$index   = self::get_index();
		$deleted = 0;

		foreach ( $index as $cache_key => $entry ) {
			$is_repo_match = isset( $entry['owner'], $entry['repo'] ) && $owner === $entry['owner'] && $repo === $entry['repo'];
			$is_branch_ok  = null === $branch || ( isset( $entry['branch'] ) && $branch === $entry['branch'] );

			if ( $is_repo_match && $is_branch_ok ) {
				self::delete( $cache_key );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Read the cache index.
	 *
	 * @return array
	 */
	private static function get_index() {
		$index = get_option( DGS_CACHE_INDEX_OPTION, array() );

		return is_array( $index ) ? $index : array();
	}

	/**
	 * Persist repo metadata for a cache key.
	 *
	 * @param string $cache_key Cache key.
	 * @param array  $meta Repo metadata.
	 * @return void
	 */
	private static function remember_index_entry( $cache_key, $meta ) {
		$index               = self::get_index();
		$index[ $cache_key ] = array(
			'owner'      => strtolower( sanitize_text_field( (string) $meta['owner'] ) ),
			'repo'       => strtolower( sanitize_text_field( (string) $meta['repo'] ) ),
			'branch'     => sanitize_text_field( (string) $meta['branch'] ),
			'path'       => self::normalize_path( (string) $meta['path'] ),
			'format'     => sanitize_key( (string) $meta['format'] ),
			'updated_at' => time(),
		);

		update_option( DGS_CACHE_INDEX_OPTION, $index, false );
	}

	/**
	 * Remove a cache key from the index.
	 *
	 * @param string $cache_key Cache key.
	 * @return void
	 */
	private static function remove_index_entry( $cache_key ) {
		$index = self::get_index();

		if ( isset( $index[ $cache_key ] ) ) {
			unset( $index[ $cache_key ] );
			update_option( DGS_CACHE_INDEX_OPTION, $index, false );
		}
	}

	/**
	 * Build the transient name for a cache key.
	 *
	 * @param string $cache_key Cache key.
	 * @return string
	 */
	private static function transient_name( $cache_key ) {
		return DGS_CACHE_PREFIX . $cache_key;
	}

	/**
	 * Build the last-good option name for a cache key.
	 *
	 * @param string $cache_key Cache key.
	 * @return string
	 */
	private static function last_good_option_name( $cache_key ) {
		return 'dgs_last_good_' . $cache_key;
	}
}
