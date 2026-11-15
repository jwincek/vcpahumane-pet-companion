<?php
/**
 * Remote REST client for fetching pets from the source WP site.
 *
 * The source runs ShelterKit Pets. Pets come from the standard
 * /wp-json/wp/v2/vcps_pet endpoint with _embed for the featured image, their
 * statuses from /wp/v2/pet_status, and whether an unlisted pet was adopted
 * from ShelterKit Pets' check-adoptions ability. Results are cached in
 * transients to keep partner sites fast and to avoid hammering the source.
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
	 * Status slugs on a ShelterKit Pets site. Only an available pet takes
	 * adoption applications.
	 */
	public const AVAILABLE = 'available';
	public const ADOPTED   = 'adopted';

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

		$result = self::fetch(
			'wp/v2/vcps_pet',
			$args,
			static fn( array $body ): array => array_map( [ self::class, 'normalize_pet' ], array_values( array_filter( $body, 'is_array' ) ) )
		);

		return [ 'pets' => $result['data'] ?? [], 'error' => $result['error'] ];
	}

	/**
	 * The statuses the source shows visitors, by term ID.
	 *
	 * A ShelterKit Pets site lists only the statuses it shows, so an adopted
	 * pet's status appears here only on a site that keeps adopted pets listed.
	 * Empty when the source has no statuses or cannot be reached.
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	public static function statuses(): array {
		$result = self::fetch(
			'wp/v2/pet_status',
			[
				'per_page' => 100,
				'_fields'  => 'id,slug,name',
			],
			static function ( array $body ): array {
				$statuses = [];
				foreach ( $body as $term ) {
					if ( is_array( $term ) && ! empty( $term['id'] ) ) {
						$statuses[ (int) $term['id'] ] = [
							'slug' => (string) ( $term['slug'] ?? '' ),
							'name' => wp_strip_all_tags( (string) ( $term['name'] ?? '' ) ),
						];
					}
				}
				return $statuses;
			},
			true
		);

		return is_array( $result['data'] ) ? $result['data'] : [];
	}

	/**
	 * A status's term ID on the source, or 0 when it has none to show.
	 */
	public static function status_id( string $slug ): int {
		foreach ( self::statuses() as $id => $status ) {
			if ( $status['slug'] === $slug ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * A pet's status, or null when the source shows none for it.
	 *
	 * @param array $pet Normalized pet.
	 * @return array{slug: string, name: string}|null
	 */
	public static function status_of( array $pet ): ?array {
		$statuses = self::statuses();
		foreach ( (array) ( $pet['statuses'] ?? [] ) as $id ) {
			if ( isset( $statuses[ (int) $id ] ) ) {
				return $statuses[ (int) $id ];
			}
		}
		return null;
	}

	/**
	 * Ask the source whether a pet it no longer lists was adopted.
	 *
	 * A pet leaves the listings when it is adopted, but also when it is
	 * returned, transferred or euthanized, and the source does not say which.
	 * ShelterKit Pets 2.1 and later answer this one question.
	 *
	 * @param int $pet_id Pet ID on the source.
	 * @return array{adopted: ?bool, listing_url: string} adopted is null when
	 *         the source cannot say: an older version, or not reachable.
	 */
	public static function adoption( int $pet_id ): array {
		$unknown = [ 'adopted' => null, 'listing_url' => '' ];
		if ( $pet_id <= 0 ) {
			return $unknown;
		}

		$result = self::fetch(
			'petsync/v1/petsync/check-adoptions/run',
			[ 'input' => (string) wp_json_encode( [ 'ids' => [ $pet_id ] ] ) ],
			static fn( array $body ): array => [
				'adopted'     => array_map( 'intval', (array) ( $body['adopted'] ?? [] ) ),
				'listing_url' => (string) ( $body['listing_url'] ?? '' ),
			],
			true
		);

		if ( ! is_array( $result['data'] ) ) {
			return $unknown;
		}

		return [
			'adopted'     => in_array( $pet_id, $result['data']['adopted'], true ),
			'listing_url' => $result['data']['listing_url'],
		];
	}

	/**
	 * GET a source REST route, cached.
	 *
	 * @param string   $route    Route under /wp-json/, without a leading slash.
	 * @param array    $query    Query args.
	 * @param callable $shape    Reduces the decoded body to what is cached.
	 * @param bool     $optional Whether the source may not offer the route. Its
	 *                           404 is then an answer, cached as null data for
	 *                           the full TTL, rather than an outage.
	 * @return array{data: mixed, error: ?string}
	 */
	private static function fetch( string $route, array $query, callable $shape, bool $optional = false ): array {
		$source = rtrim( (string) VCPA_Pet_Companion_Settings::get( 'source_url' ), '/' );
		if ( $source === '' ) {
			return [ 'data' => null, 'error' => __( 'Source URL is not configured.', 'vcpahumane-pet-companion' ) ];
		}

		$url = $source . '/wp-json/' . $route;
		if ( $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		// Keyed by the whole URL, so changing the source never serves the old one's pets.
		$cache_key = self::TRANSIENT_PREFIX . md5( $url );
		$cached    = get_transient( $cache_key );

		// Cache hit. Two shapes:
		// - ['data' => …] → successful fetch
		// - ['__error' => msg] → negative cache, return the remembered error
		//   without re-attempting until NEGATIVE_TTL expires.
		if ( is_array( $cached ) ) {
			if ( isset( $cached['__error'] ) ) {
				return [ 'data' => null, 'error' => (string) $cached['__error'] ];
			}
			if ( array_key_exists( 'data', $cached ) ) {
				return [ 'data' => $cached['data'], 'error' => null ];
			}
		}

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
		if ( $optional && $code === 404 ) {
			return self::cache_success( $cache_key, null );
		}
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

		return self::cache_success( $cache_key, $shape( $body ) );
	}

	/**
	 * @param mixed $data What to cache and return.
	 */
	private static function cache_success( string $cache_key, $data ): array {
		set_transient( $cache_key, [ 'data' => $data ], (int) VCPA_Pet_Companion_Settings::get( 'cache_ttl' ) );
		self::remember_key( $cache_key );
		return [ 'data' => $data, 'error' => null ];
	}

	/**
	 * Store a failure marker in the same transient slot so subsequent
	 * requests fail fast instead of retrying for $ttl seconds.
	 */
	private static function cache_failure( string $cache_key, string $message, int $ttl ): array {
		set_transient( $cache_key, [ '__error' => $message ], $ttl );
		self::remember_key( $cache_key );
		return [ 'data' => null, 'error' => $message ];
	}

	/**
	 * Reduce a wp/v2/vcps_pet record to the fields we render.
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
			'id'       => (int) ( $raw['id'] ?? 0 ),
			'slug'     => (string) ( $raw['slug'] ?? '' ),
			'link'     => (string) ( $raw['link'] ?? '' ),
			'statuses' => array_values( array_filter( array_map( 'intval', (array) ( $raw['pet_status'] ?? [] ) ) ) ),
			'title'    => wp_strip_all_tags( (string) ( $raw['title']['rendered'] ?? '' ) ),
			'excerpt'  => wp_strip_all_tags( (string) ( $raw['excerpt']['rendered'] ?? '' ) ),
			'content'  => (string) ( $raw['content']['rendered'] ?? '' ),
			'image'    => $image,
			'alt'      => $alt !== '' ? $alt : (string) ( $raw['title']['rendered'] ?? '' ),
		];
	}

	/**
	 * The public URL of a pet's page on the shelter's site.
	 *
	 * The address the source reports, so it follows the source's permalinks.
	 *
	 * @param array $pet Normalized pet.
	 */
	public static function pet_url( array $pet ): string {
		$link = (string) ( $pet['link'] ?? '' );
		if ( $link === '' ) {
			// ShelterKit Pets' address for a pet, for a source that reports none.
			return self::site_base() . '/adopt/pets/' . rawurlencode( (string) ( $pet['slug'] ?? '' ) ) . '/';
		}
		return self::on_link_domain( $link );
	}

	/**
	 * The page listing the shelter's adoptable pets.
	 *
	 * @param string $reported The address the source reported, if it did.
	 */
	public static function listing_url( string $reported = '' ): string {
		if ( $reported !== '' ) {
			return self::on_link_domain( $reported );
		}
		return self::site_base() . '/adopt/pets/';
	}

	/**
	 * Move a source address onto the link domain setting, when one is set.
	 * Only the scheme, host and port change.
	 */
	private static function on_link_domain( string $url ): string {
		$domain = wp_parse_url( (string) VCPA_Pet_Companion_Settings::get( 'link_domain' ) );
		$parts  = wp_parse_url( $url );
		if ( empty( $domain['host'] ) || ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		return ( $domain['scheme'] ?? 'https' ) . '://' . $domain['host']
			. ( isset( $domain['port'] ) ? ':' . $domain['port'] : '' )
			. ( $parts['path'] ?? '/' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	private static function site_base(): string {
		$domain = rtrim( (string) VCPA_Pet_Companion_Settings::get( 'link_domain' ), '/' );
		return $domain !== '' ? $domain : rtrim( (string) VCPA_Pet_Companion_Settings::get( 'source_url' ), '/' );
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
