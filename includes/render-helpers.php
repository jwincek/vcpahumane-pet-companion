<?php
/**
 * Shared markup helpers for block render callbacks.
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a single pet card. Used by both blocks for visual consistency.
 *
 * @param array  $pet            Normalized pet array from VCPA_Pet_Companion_Client.
 * @param string $extra_attrs    Optional pre-built HTML attribute string (e.g. block wrapper attrs).
 *                               When provided, replaces the default class-only opening tag so the
 *                               wrapper inherits user-set color/border/spacing styles.
 * @param string $extra_class    Optional extra class names to merge into the default class list.
 */
function vcpa_pet_companion_render_card( array $pet, string $extra_attrs = '', string $extra_class = '' ): string {
	$url   = VCPA_Pet_Companion_Client::pet_url( $pet['slug'] );
	$title = $pet['title'] !== '' ? $pet['title'] : __( 'Adoptable pet', 'vcpahumane-pet-companion' );

	$img = '';
	if ( $pet['image'] !== '' ) {
		$img = sprintf(
			'<img class="vcpa-pet-card__img" src="%s" alt="%s" loading="lazy" />',
			esc_url( $pet['image'] ),
			esc_attr( $pet['alt'] )
		);
	}

	$excerpt = $pet['excerpt'] !== ''
		? '<p class="vcpa-pet-card__excerpt">' . esc_html( wp_trim_words( $pet['excerpt'], 22 ) ) . '</p>'
		: '';

	$class = trim( 'vcpa-pet-card ' . $extra_class );
	$open  = $extra_attrs !== ''
		? sprintf( '<a %s href="%s" target="_blank" rel="noopener">', $extra_attrs, esc_url( $url ) )
		: sprintf( '<a class="%s" href="%s" target="_blank" rel="noopener">', esc_attr( $class ), esc_url( $url ) );

	return sprintf(
		'%1$s
			<div class="vcpa-pet-card__image">%2$s</div>
			<div class="vcpa-pet-card__body">
				<h3 class="vcpa-pet-card__title">%3$s</h3>
				%4$s
				<span class="vcpa-pet-card__cta">%5$s</span>
			</div>
		</a>',
		$open,
		$img,
		esc_html( $title ),
		$excerpt,
		esc_html__( 'Meet me &rarr;', 'vcpahumane-pet-companion' )
	);
}

/**
 * Render a friendly fallback when no pets / error.
 */
function vcpa_pet_companion_render_empty( ?string $error = null ): string {
	if ( $error && current_user_can( 'manage_options' ) ) {
		return '<div class="vcpa-pet-empty"><p>' . esc_html( sprintf(
			/* translators: %s: error message */
			__( 'Could not load pets: %s', 'vcpahumane-pet-companion' ),
			$error
		) ) . '</p></div>';
	}
	return '<div class="vcpa-pet-empty"><p>' . esc_html__( 'No adoptable pets to show right now. Check back soon!', 'vcpahumane-pet-companion' ) . '</p></div>';
}
