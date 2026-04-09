<?php
/**
 * Remote REST client for fetching pets from the source WP site.
 *
 * Uses the standard /wp-json/wp/v2/pet endpoint with _embed for the
 * featured image. Results are cached in transients to keep partner
 * sites fast and to avoid hammering the source.
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VCPA_Pet_Companion_Client {

	private const TRANSIENT_PREFIX = 'vcpa_pets_';
	private const INDEX_OPTION     = 'vcpa_pet_companion_cache_keys';

	/**
	 * Negative-cache TTLs.
	 *
	 * Two tiers, because the failure modes are different:
	 *
	 * - HARD failures (HTTP 4xx/5xx, malformed JSON) mean the source is
	 *   genuinely broken. Cache for 60s so a brief outage doesn't make every
	 *   visitor pay a timeout.
	 *
	 * - SOFT failures (cURL timeouts, DNS hiccups, connection refused) usually
	 *   resolve themselves in seconds — especially in the editor, where loopback
	 *   PHP-FPM worker contention can stall a single request even when the source
	 *   itself is fine. Caching these for 60s makes the editor feel broken,
	 *   because changing a block attribute triggers a new query that hits the
	 *   poisoned slot. 5s is long enough to absorb a hot-loop retry storm but
	 *   short enough that the next user interaction succeeds.
	 */
	private const NEGATIVE_TTL_HARD = 60;
	private const NEGATIVE_TTL_SOFT = 5;

	/**
	 * Fetch pets from the source site.
	 *
	 * @param array $args Query args (per_page, orderby, order, include, search, taxonomy filters).
	 * @return array{pets: array, error: ?string} Normalized pet array and any error.
	 */
	public static function get_pets( array $args = [] ): array {
		$defaults = [
			'per_page' => 12,
			'orderby'  => 'date',
			'order'    => 'desc',
			'_embed'   => 'wp:featuredmedia',
		];
		$args = array_filter(
			array_merge( $defaults, $args ),
			static fn( $v ) => $v !== '' && $v !== null
		);

		$cache_key = self::TRANSIENT_PREFIX . md5( wp_json_encode( $args ) );
		$cached    = get_transient( $cache_key );

		// Cache hit. Two shapes:
		// - array → successful fetch, return pets
		// - ['__error' => msg] → negative cache, return the remembered error
		//   without re-attempting until NEGATIVE_TTL expires.
		if ( is_array( $cached ) ) {
			if ( isset( $cached['__error'] ) ) {
				return [ 'pets' => [], 'error' => (string) $cached['__error'] ];
			}
			return [ 'pets' => $cached, 'error' => null ];
		}

		$source = rtrim( (string) VCPA_Pet_Companion_Settings::get( 'source_url' ), '/' );
		if ( $source === '' ) {
			return [ 'pets' => [], 'error' => __( 'Source URL is not configured.', 'vcpahumane-pet-companion' ) ];
		}

		$url = add_query_arg( $args, $source . '/wp-json/wp/v2/pet' );

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 8,
				'headers' => [ 'Accept' => 'application/json' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			// Network-layer failure (timeout, DNS, refused connection). Soft TTL.
			return self::cache_failure( $cache_key, $response->get_error_message(), self::NEGATIVE_TTL_SOFT );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return self::cache_failure(
				$cache_key,
				sprintf( /* translators: %d: HTTP code */ __( 'Source returned HTTP %d.', 'vcpahumane-pet-companion' ), $code ),
				self::NEGATIVE_TTL_HARD
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return self::cache_failure( $cache_key, __( 'Invalid JSON from source.', 'vcpahumane-pet-companion' ), self::NEGATIVE_TTL_HARD );
		}

		$normalized = array_map( [ self::class, 'normalize_pet' ], $body );

		$ttl = (int) VCPA_Pet_Companion_Settings::get( 'cache_ttl' );
		set_transient( $cache_key, $normalized, $ttl );
		self::remember_key( $cache_key );

		return [ 'pets' => $normalized, 'error' => null ];
	}

	/**
	 * Store a failure marker in the same transient slot so subsequent
	 * requests fail fast instead of retrying for $ttl seconds.
	 */
	private static function cache_failure( string $cache_key, string $message, int $ttl ): array {
		set_transient( $cache_key, [ '__error' => $message ], $ttl );
		self::remember_key( $cache_key );
		return [ 'pets' => [], 'error' => $message ];
	}

	/**
	 * Reduce a wp/v2/pet record to the fields we render.
	 */
	private static function normalize_pet( array $raw ): array {
		$image = '';
		$alt   = '';
		if ( ! empty( $raw['_embedded']['wp:featuredmedia'][0] ) ) {
			$media = $raw['_embedded']['wp:featuredmedia'][0];
			$alt   = (string) ( $media['alt_text'] ?? '' );
			// Prefer a medium-large size if available, fall back to source_url.
			$sizes = $media['media_details']['sizes'] ?? [];
			if ( ! empty( $sizes['medium_large']['source_url'] ) ) {
				$image = (string) $sizes['medium_large']['source_url'];
			} elseif ( ! empty( $sizes['medium']['source_url'] ) ) {
				$image = (string) $sizes['medium']['source_url'];
			} elseif ( ! empty( $media['source_url'] ) ) {
				$image = (string) $media['source_url'];
			}
		}

		return [
			'id'      => (int) ( $raw['id'] ?? 0 ),
			'slug'    => (string) ( $raw['slug'] ?? '' ),
			'title'   => wp_strip_all_tags( (string) ( $raw['title']['rendered'] ?? '' ) ),
			'excerpt' => wp_strip_all_tags( (string) ( $raw['excerpt']['rendered'] ?? '' ) ),
			'content' => (string) ( $raw['content']['rendered'] ?? '' ),
			'image'   => $image,
			'alt'     => $alt !== '' ? $alt : (string) ( $raw['title']['rendered'] ?? '' ),
		];
	}

	/**
	 * Build the public URL for a pet on the source/link domain.
	 */
	public static function pet_url( string $slug ): string {
		$base = rtrim( (string) VCPA_Pet_Companion_Settings::get( 'link_domain' ), '/' );
		return $base . '/adopt/pets/' . rawurlencode( $slug ) . '/';
	}

	/**
	 * Track transient keys so flush_cache can clear them all.
	 */
	private static function remember_key( string $key ): void {
		$keys = get_option( self::INDEX_OPTION, [] );
		if ( ! is_array( $keys ) ) {
			$keys = [];
		}
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::INDEX_OPTION, $keys, false );
		}
	}

	public static function flush_cache(): void {
		$keys = get_option( self::INDEX_OPTION, [] );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}
		delete_option( self::INDEX_OPTION );
	}
}
