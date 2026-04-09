<?php
/**
 * Render: vcpa-pet-companion/pet-card
 *
 * Renders one pet as a *profile* (not a summary card): big image,
 * full story, prominent CTA back to vcpahumane.org. Two layouts:
 * image-left (default, magazine-style) or image-top (stacks first).
 *
 * When the live REST query returns empty (typically because the pet
 * was adopted and removed from the source site), we fall back to the
 * cached petName / petImage stored as block attributes at pick time
 * and show a celebration profile instead.
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

$args = [ 'per_page' => 1 ];
if ( $pet_id > 0 ) {
	$args['include'] = [ $pet_id ];
} else {
	$args['slug'] = $pet_slug;
}

$result = VCPA_Pet_Companion_Client::get_pets( $args );
$link_base = rtrim( (string) VCPA_Pet_Companion_Settings::get( 'link_domain' ), '/' );

if ( ! empty( $result['pets'] ) ) {
	$pet     = $result['pets'][0];
	$wrapper = get_block_wrapper_attributes( [ 'class' => $base_class ] );
	$pet_url = VCPA_Pet_Companion_Client::pet_url( $pet['slug'] );
	$title   = $pet['title'] !== '' ? $pet['title'] : __( 'Adoptable pet', 'vcpahumane-pet-companion' );

	$img_html = '';
	if ( $pet['image'] !== '' ) {
		$img_html = sprintf(
			'<img class="vcpa-pet-profile__img" src="%s" alt="%s" loading="lazy" />',
			esc_url( $pet['image'] ),
			esc_attr( $pet['alt'] )
		);
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

	printf(
		'<div %1$s>
			<div class="vcpa-pet-profile__media">%2$s</div>
			<div class="vcpa-pet-profile__body">
				<h2 class="vcpa-pet-profile__name">%3$s</h2>
				%4$s
				<p class="vcpa-pet-profile__cta-row">
					<a class="vcpa-pet-profile__cta" href="%5$s" target="_blank" rel="noopener">%6$s</a>
				</p>
			</div>
		</div>',
		$wrapper,
		$img_html,
		esc_html( $title ),
		$body_html,
		esc_url( $pet_url ),
		esc_html__( 'Start the adoption process', 'vcpahumane-pet-companion' )
	);
	return;
}

// Empty live result + cached name → adopted celebration profile.
if ( $cached_name !== '' ) {
	$wrapper = get_block_wrapper_attributes( [ 'class' => $base_class . ' vcpa-pet-profile--adopted' ] );

	$img_html = '';
	if ( $cached_image !== '' ) {
		$img_html = sprintf(
			'<img class="vcpa-pet-profile__img" src="%s" alt="%s" loading="lazy" />',
			esc_url( $cached_image ),
			esc_attr( $cached_alt !== '' ? $cached_alt : $cached_name )
		);
	}

	printf(
		'<div %1$s>
			<div class="vcpa-pet-profile__media">%2$s<span class="vcpa-pet-profile__badge">%3$s</span></div>
			<div class="vcpa-pet-profile__body">
				<h2 class="vcpa-pet-profile__name">%4$s</h2>
				<p class="vcpa-pet-profile__story">%5$s</p>
				<p class="vcpa-pet-profile__cta-row">
					<a class="vcpa-pet-profile__cta" href="%6$s" target="_blank" rel="noopener">%7$s</a>
				</p>
			</div>
		</div>',
		$wrapper,
		$img_html,
		esc_html__( 'Adopted!', 'vcpahumane-pet-companion' ),
		esc_html( sprintf( /* translators: %s: pet name */ __( '%s found a home!', 'vcpahumane-pet-companion' ), $cached_name ) ),
		esc_html__( 'Thank you to everyone who shared, donated, and showed up. There are still wonderful pets waiting for their forever family.', 'vcpahumane-pet-companion' ),
		esc_url( $link_base . '/adopt/pets/' ),
		esc_html__( 'Meet other adoptable pets', 'vcpahumane-pet-companion' )
	);
	return;
}

// Nothing live, nothing cached.
$wrapper = get_block_wrapper_attributes( [ 'class' => 'vcpa-pet-empty' ] );
printf( '<div %s>%s</div>', $wrapper, vcpa_pet_companion_render_empty( $result['error'] ) );
