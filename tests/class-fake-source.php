<?php
/**
 * A shelter site running ShelterKit Pets, as far as the companion can see it.
 *
 * Answers the three routes the companion calls, the way ShelterKit Pets does:
 *
 * - /wp/v2/vcps_pet lists published pets only, honouring include, slug,
 *   pet_status, pet_animal, pet_attribute and per_page.
 * - /wp/v2/pet_status lists only the statuses the site shows.
 * - /petsync/v1/petsync/check-adoptions/run (2.1 and later) says which of
 *   the given IDs were adopted, listed or not, and nothing else.
 *
 * Flip the public properties to model an older version, a site without
 * statuses, or one that is down.
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

final class Fake_Source {

	public const URL = 'https://shelter.test';

	public const AVAILABLE = 217;
	public const PENDING   = 283;
	public const HOLD      = 284;
	public const ADOPTED   = 285;
	public const RETURNED  = 286;

	/**
	 * Every status the shelter uses. Only those in $shown are public.
	 *
	 * @var array<int, array{slug: string, name: string}>
	 */
	public array $statuses = [
		self::AVAILABLE => [ 'slug' => 'available', 'name' => 'Available' ],
		self::PENDING   => [ 'slug' => 'pending', 'name' => 'Adoption Pending' ],
		self::HOLD      => [ 'slug' => 'hold', 'name' => 'On Hold' ],
		self::ADOPTED   => [ 'slug' => 'adopted', 'name' => 'Adopted' ],
		self::RETURNED  => [ 'slug' => 'returned-to-owner', 'name' => 'Returned to Owner' ],
	];

	/** @var int[] ShelterKit Pets' defaults. */
	public array $shown = [ self::AVAILABLE, self::PENDING, self::HOLD ];

	/** @var array<int, array<string, mixed>> */
	public array $pets = [];

	/** Hosts this fake answers for. */
	public array $hosts = [ 'shelter.test' ];

	/** False models ShelterKit Pets before 2.1. */
	public bool $answers_adoptions = true;

	/** False models a source with no status taxonomy. */
	public bool $has_statuses = true;

	public string $listing_url = self::URL . '/adopt/pets/';

	/** @var WP_Error|int|null A network error, or an HTTP status, for every request. */
	public $failure = null;

	/** @var string[] Every URL asked for. */
	public array $requests = [];

	private int $next_id = 1001;

	/**
	 * Add a pet. Unlisted pets are drafts: the REST API does not return them.
	 *
	 * @param array<string, mixed> $pet Overrides.
	 * @return int Its ID.
	 */
	public function add_pet( array $pet = [] ): int {
		$id   = (int) ( $pet['id'] ?? $this->next_id++ );
		$slug = (string) ( $pet['slug'] ?? 'pet-' . $id );

		$this->pets[ $id ] = array_merge(
			[
				'id'      => $id,
				'slug'    => $slug,
				'title'   => 'Pet ' . $id,
				'status'  => self::AVAILABLE,
				'listed'  => true,
				'link'    => self::URL . '/adopt/pets/' . $slug . '/',
				'image'   => self::URL . '/wp-content/uploads/' . $slug . '-768x960.jpg',
				'content' => '<p>A good pet.</p>',
				'excerpt' => '<p>A good pet.</p>',
				'animal'  => 0,
				'attrs'   => [],
			],
			$pet,
			[ 'id' => $id ]
		);

		return $id;
	}

	/**
	 * @param false|array|WP_Error $pre  Short-circuit so far.
	 * @param array                $args Request args.
	 * @param string               $url  URL.
	 * @return false|array|WP_Error
	 */
	public function respond( $pre, $args, $url ) {
		$parts = wp_parse_url( $url );
		if ( false !== $pre || ! in_array( $parts['host'] ?? '', $this->hosts, true ) ) {
			return $pre;
		}

		$this->requests[] = $url;

		if ( $this->failure instanceof WP_Error ) {
			return $this->failure;
		}
		if ( is_int( $this->failure ) ) {
			return $this->json( [ 'code' => 'broken', 'message' => 'Broken' ], $this->failure );
		}

		parse_str( $parts['query'] ?? '', $query );
		$route = substr( $parts['path'] ?? '', strlen( '/wp-json/' ) );

		switch ( $route ) {
			case 'wp/v2/vcps_pet':
				return $this->json( $this->list_pets( $query ) );

			case 'wp/v2/pet_status':
				if ( ! $this->has_statuses ) {
					return $this->no_route();
				}
				$terms = [];
				foreach ( $this->shown as $id ) {
					$terms[] = [ 'id' => $id ] + $this->statuses[ $id ];
				}
				return $this->json( $terms );

			case 'petsync/v1/petsync/check-adoptions/run':
				if ( ! $this->answers_adoptions ) {
					return $this->no_route();
				}
				$input = json_decode( (string) ( $query['input'] ?? '' ), true );
				$asked = array_map( 'intval', (array) ( $input['ids'] ?? [] ) );
				return $this->json(
					[
						'adopted'     => array_values( array_filter( $asked, fn( int $id ): bool => self::ADOPTED === ( $this->pets[ $id ]['status'] ?? 0 ) ) ),
						'listing_url' => $this->listing_url,
					]
				);
		}

		return $this->no_route();
	}

	/**
	 * Requests whose URL contains a string.
	 *
	 * @return string[]
	 */
	public function requests_to( string $needle ): array {
		return array_values( array_filter( $this->requests, static fn( string $url ): bool => str_contains( $url, $needle ) ) );
	}

	/**
	 * @param array<string, mixed> $query Parsed query string.
	 * @return array<int, array<string, mixed>>
	 */
	private function list_pets( array $query ): array {
		$include = array_map( 'intval', (array) ( $query['include'] ?? [] ) );
		$out     = [];

		foreach ( $this->pets as $pet ) {
			$match = $pet['listed']
				&& ( ! $include || in_array( $pet['id'], $include, true ) )
				&& ( ! isset( $query['slug'] ) || $query['slug'] === $pet['slug'] )
				&& ( ! isset( $query['pet_status'] ) || in_array( $pet['status'], array_map( 'intval', explode( ',', (string) $query['pet_status'] ) ), true ) )
				&& ( ! isset( $query['pet_animal'] ) || in_array( $pet['animal'], array_map( 'intval', explode( ',', (string) $query['pet_animal'] ) ), true ) )
				&& ( ! isset( $query['pet_attribute'] ) || array_intersect( $pet['attrs'], array_map( 'intval', explode( ',', (string) $query['pet_attribute'] ) ) ) );

			if ( $match ) {
				$out[] = $this->rest_record( $pet );
			}
		}

		return array_slice( $out, 0, (int) ( $query['per_page'] ?? 10 ) );
	}

	/**
	 * @param array<string, mixed> $pet Pet.
	 * @return array<string, mixed>
	 */
	private function rest_record( array $pet ): array {
		return [
			'id'         => $pet['id'],
			'slug'       => $pet['slug'],
			'status'     => 'publish',
			'link'       => $pet['link'],
			'title'      => [ 'rendered' => $pet['title'] ],
			'content'    => [ 'rendered' => $pet['content'] ],
			'excerpt'    => [ 'rendered' => $pet['excerpt'] ],
			// A public status shows its term ID; a hidden one is still assigned,
			// and the ID is all a visitor gets.
			'pet_status' => [ $pet['status'] ],
			'_embedded'  => [
				'wp:featuredmedia' => [
					[
						'alt_text'      => '',
						'source_url'    => $pet['image'],
						'media_details' => [ 'sizes' => [ 'medium_large' => [ 'source_url' => $pet['image'] ] ] ],
					],
				],
			],
		];
	}

	/**
	 * @param mixed $body Body to encode.
	 */
	private function json( $body, int $code = 200 ): array {
		return [
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => (string) wp_json_encode( $body ),
			'response' => [
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	private function no_route(): array {
		return $this->json(
			[
				'code'    => 'rest_no_route',
				'message' => 'No route was found matching the URL and request method.',
				'data'    => [ 'status' => 404 ],
			],
			404
		);
	}
}
