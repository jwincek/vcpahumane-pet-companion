<?php
/**
 * Render: vcpa-pet-companion/featured-pets
 *
 * Fetches a small pool of recent available pets, optionally filtered by
 * animal type and/or pet attributes (special-needs, good-with-kids, etc).
 * Pending and on-hold pets stay listed on the shelter's site but are not
 * taking applications, so a grid inviting visitors to meet them leaves
 * them out.
 * If `random` is on, the pool is shuffled locally and sliced — wp/v2
 * has no orderby=rand, and shuffling client-side keeps the cache hit
 * rate high (every visitor shares the same fetched pool, only the
 * displayed slice rotates).
 *
 * @var array $attributes
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$count   = max( 1, min( 12, (int) ( $attributes['count'] ?? 3 ) ) );
$random  = ! empty( $attributes['random'] );
$columns = max( 1, min( 6, (int) ( $attributes['columns'] ?? 3 ) ) );

$animals = array_values( array_filter( array_map( 'intval', (array) ( $attributes['animals'] ?? [] ) ) ) );
$attrs   = array_values( array_filter( array_map( 'intval', (array) ( $attributes['petAttributes'] ?? [] ) ) ) );

// Pull a wider pool when randomizing so the rotation feels real.
$pool_size = $random ? min( 30, max( $count * 4, 12 ) ) : $count;

$query_args = [
	'per_page' => $pool_size,
	'orderby'  => 'date',
	'order'    => 'desc',
];
if ( $animals ) {
	$query_args['pet_animal'] = implode( ',', $animals );
}
if ( $attrs ) {
	$query_args['pet_attribute'] = implode( ',', $attrs );
}
// A source without statuses has no way to say a pet isn't available.
$available = VCPA_Pet_Companion_Client::status_id( VCPA_Pet_Companion_Client::AVAILABLE );
if ( $available > 0 ) {
	$query_args['pet_status'] = $available;
}

$result = VCPA_Pet_Companion_Client::get_pets( $query_args );

$wrapper_args = [ 'class' => 'vcpa-pet-grid vcpa-pet-grid--cols-' . $columns . ' vcpa-pet-featured' ];

if ( empty( $result['pets'] ) ) {
	printf( '<div %s>%s</div>', get_block_wrapper_attributes( $wrapper_args ), vcpa_pet_companion_render_empty( $result['error'] ) );
	return;
}

$pets = $result['pets'];
if ( $random ) {
	shuffle( $pets );
}
$pets = array_slice( $pets, 0, $count );

echo '<div ' . get_block_wrapper_attributes( $wrapper_args ) . '>';
foreach ( $pets as $pet ) {
	echo vcpa_pet_companion_render_card( $pet );
}
echo '</div>';
