<?php
/**
 * Render: vcpa-pet-companion/pet-card
 *
 * Renders one pet as a *profile* (not a summary card): big image,
 * full story, prominent CTA back to the shelter. Two layouts:
 * image-left (default, magazine-style) or image-top (stacks first).
 *
 * A listed pet shows its live profile. Only an available pet invites an
 * adoption application; a pending or on-hold pet shows its status instead.
 *
 * A pet the source no longer lists may have been adopted, or returned,
 * transferred or euthanized: the source does not say which. So we ask
 * whether it was adopted, and celebrate only then, using the petName /
 * petImage cached as block attributes at pick time. Otherwise the card
 * says only that the pet isn't listed.
 *
 * @var array $attributes
 */

declare( strict_types = 1 );

$pet_id   = (int) ( $attributes['petId'] ?? 0 );
$pet_slug = trim( (string) ( $attributes['petSlug'] ?? '' ) );

$cached_name  = trim( (string) ( $attributes['petName'] ?? '' ) );
$cached_image = trim( (string) ( $attributes['petImage'] ?? '' ) );
$cached_alt   = trim( (string) ( $attributes['petAlt'] ?? '' ) );

$image_position = ( ( $attributes['imagePosition'] ?? 'left' ) === 'top' ) ? 'top' : 'left';
$show_full      = ! isset( $attributes['showFullStory'] ) || ! empty( $attributes['showFullStory'] );

$base_class = 'vcpa-pet-profile vcpa-pet-profile--image-' . $image_position;

// Editor-only nudge when nothing is configured.
if ( $pet_id <= 0 && $pet_slug === '' ) {
	if ( current_user_can( 'edit_posts' ) ) {
		$wrapper = get_block_wrapper_attributes( [ 'class' => 'vcpa-pet-empty' ] );
		echo '<div ' . $wrapper . '>'
			. esc_html__( 'Pick a pet in the block sidebar.', 'vcpahumane-pet-companion' )
			. '</div>';
	}
	return;
}

/**
 * One profile. Every state shares the markup and differs in what it says.
 *
 * @param array{modifier: string, image: string, alt: string, badge: string, badge_class: string, name: string, body: string, cta_url: string, cta: string} $p
 */
$render_profile = static function ( array $p ) use ( $base_class ): void {
	$wrapper = get_block_wrapper_attributes( [ 'class' => trim( $base_class . ' ' . $p['modifier'] ) ] );

	$img_html = '';
	if ( $p['image'] !== '' ) {
		$img_html = sprintf(
			'<img class="vcpa-pet-profile__img" src="%s" alt="%s" loading="lazy" />',
			esc_url( $p['image'] ),
			esc_attr( $p['alt'] )
		);
	}
	$badge_html = $p['badge'] !== ''
		? sprintf( '<span class="%s">%s</span>', esc_attr( trim( 'vcpa-pet-profile__badge ' . $p['badge_class'] ) ), esc_html( $p['badge'] ) )
		: '';

	printf(
		'<div %1$s>
			<div class="vcpa-pet-profile__media">%2$s%3$s</div>
			<div class="vcpa-pet-profile__body">
				<h2 class="vcpa-pet-profile__name">%4$s</h2>
				%5$s
				<p class="vcpa-pet-profile__cta-row">
					<a class="vcpa-pet-profile__cta" href="%6$s" target="_blank" rel="noopener">%7$s</a>
				</p>
			</div>
		</div>',
		$wrapper,
		$img_html,
		$badge_html,
		esc_html( $p['name'] ),
		$p['body'],
		esc_url( $p['cta_url'] ),
		esc_html( $p['cta'] )
	);
};

$thanks = '<p class="vcpa-pet-profile__story">'
	. esc_html__( 'Thank you to everyone who shared, donated, and showed up. There are still wonderful pets waiting for their forever family.', 'vcpahumane-pet-companion' )
	. '</p>';

$args = [ 'per_page' => 1 ];
if ( $pet_id > 0 ) {
	$args['include'] = [ $pet_id ];
} else {
	$args['slug'] = $pet_slug;
}

$result = VCPA_Pet_Companion_Client::get_pets( $args );

if ( ! empty( $result['pets'] ) ) {
	$pet    = $result['pets'][0];
	$title  = $pet['title'] !== '' ? $pet['title'] : __( 'Adoptable pet', 'vcpahumane-pet-companion' );
	$status = VCPA_Pet_Companion_Client::status_of( $pet );
	$slug   = $status['slug'] ?? '';

	// A shelter that keeps adopted pets listed: celebrate with the live profile.
	if ( $slug === VCPA_Pet_Companion_Client::ADOPTED ) {
		$render_profile( [
			'modifier'    => 'vcpa-pet-profile--adopted',
			'image'       => $pet['image'],
			'alt'         => $pet['alt'],
			'badge'       => __( 'Adopted!', 'vcpahumane-pet-companion' ),
			'badge_class' => '',
			/* translators: %s: pet name */
			'name'        => sprintf( __( '%s found a home!', 'vcpahumane-pet-companion' ), $title ),
			'body'        => $thanks,
			'cta_url'     => VCPA_Pet_Companion_Client::listing_url( VCPA_Pet_Companion_Client::adoption( $pet['id'] )['listing_url'] ),
			'cta'         => __( 'Meet other adoptable pets', 'vcpahumane-pet-companion' ),
		] );
		return;
	}

	// Body text: prefer the full content (the shelter's writeup) when
	// showFullStory is on; otherwise fall back to the excerpt.
	if ( $show_full && trim( (string) $pet['content'] ) !== '' ) {
		// content is rendered HTML from wp/v2; allow safe post-content tags.
		$body_html = '<div class="vcpa-pet-profile__story">'
			. wp_kses_post( $pet['content'] )
			. '</div>';
	} elseif ( $pet['excerpt'] !== '' ) {
		$body_html = '<p class="vcpa-pet-profile__story">' . esc_html( $pet['excerpt'] ) . '</p>';
	} else {
		$body_html = '';
	}

	// No status at all is a source without statuses: treat the pet as available.
	$takes_applications = $slug === '' || $slug === VCPA_Pet_Companion_Client::AVAILABLE;

	$render_profile( [
		'modifier'    => $takes_applications ? '' : 'vcpa-pet-profile--status-' . sanitize_html_class( $slug ),
		'image'       => $pet['image'],
		'alt'         => $pet['alt'],
		'badge'       => $takes_applications ? '' : (string) $status['name'],
		'badge_class' => 'vcpa-pet-profile__badge--status',
		'name'        => $title,
		'body'        => $body_html,
		'cta_url'     => VCPA_Pet_Companion_Client::pet_url( $pet ),
		'cta'         => $takes_applications
			? __( 'Start the adoption process', 'vcpahumane-pet-companion' )
			/* translators: %s: pet name */
			: sprintf( __( 'Learn more about %s', 'vcpahumane-pet-companion' ), $title ),
	] );
	return;
}

// The source could not be read: say nothing about the pet. Only an empty
// answer means it is no longer listed.
if ( $result['error'] !== null || $cached_name === '' ) {
	$wrapper = get_block_wrapper_attributes( [ 'class' => 'vcpa-pet-empty' ] );
	printf( '<div %s>%s</div>', $wrapper, vcpa_pet_companion_render_empty( $result['error'] ) );
	return;
}

$adoption = VCPA_Pet_Companion_Client::adoption( $pet_id );
$listing  = VCPA_Pet_Companion_Client::listing_url( $adoption['listing_url'] );
$alt      = $cached_alt !== '' ? $cached_alt : $cached_name;

if ( $adoption['adopted'] === true ) {
	$render_profile( [
		'modifier'    => 'vcpa-pet-profile--adopted',
		'image'       => $cached_image,
		'alt'         => $alt,
		'badge'       => __( 'Adopted!', 'vcpahumane-pet-companion' ),
		'badge_class' => '',
		/* translators: %s: pet name */
		'name'        => sprintf( __( '%s found a home!', 'vcpahumane-pet-companion' ), $cached_name ),
		'body'        => $thanks,
		'cta_url'     => $listing,
		'cta'         => __( 'Meet other adoptable pets', 'vcpahumane-pet-companion' ),
	] );
	return;
}

// Not adopted, or the source can't say. Nothing more is ours to tell.
$render_profile( [
	'modifier'    => 'vcpa-pet-profile--unlisted',
	'image'       => $cached_image,
	'alt'         => $alt,
	'badge'       => '',
	'badge_class' => '',
	/* translators: %s: pet name */
	'name'        => sprintf( __( '%s isn\'t up for adoption right now', 'vcpahumane-pet-companion' ), $cached_name ),
	'body'        => '<p class="vcpa-pet-profile__story">'
		. esc_html__( 'There are still wonderful pets waiting for their forever family.', 'vcpahumane-pet-companion' )
		. '</p>',
	'cta_url'     => $listing,
	'cta'         => __( 'Meet the adoptable pets', 'vcpahumane-pet-companion' ),
] );
