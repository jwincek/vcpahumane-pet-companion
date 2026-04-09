<?php
/**
 * Plugin Name: VCPA Humane Pet Companion
 * Description: Display adoptable pets from the Venango County Humane Society on partner WordPress sites via Gutenberg blocks. Pulls live pet data from vcpahumane.org's public REST API and links visitors back to the shelter's adoption pages.
 * Version: 0.2.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Venango County Humane Society
 * License: GPL-2.0-or-later
 * Text Domain: vcpahumane-pet-companion
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VCPA_PET_COMPANION_VERSION', '0.2.2' );
define( 'VCPA_PET_COMPANION_FILE', __FILE__ );
define( 'VCPA_PET_COMPANION_DIR', plugin_dir_path( __FILE__ ) );
define( 'VCPA_PET_COMPANION_URL', plugin_dir_url( __FILE__ ) );

require_once VCPA_PET_COMPANION_DIR . 'includes/class-client.php';
require_once VCPA_PET_COMPANION_DIR . 'includes/class-settings.php';
require_once VCPA_PET_COMPANION_DIR . 'includes/render-helpers.php';

add_action( 'init', static function (): void {
	// Register the shared style handle first so block.json's "style" field resolves.
	wp_register_style(
		'vcpa-pet-companion',
		VCPA_PET_COMPANION_URL . 'assets/style.css',
		[],
		VCPA_PET_COMPANION_VERSION
	);

	register_block_type( VCPA_PET_COMPANION_DIR . 'blocks/pet-card' );
	register_block_type( VCPA_PET_COMPANION_DIR . 'blocks/featured-pets' );

	// Block style variants for the grid only — Pet Card is a profile, not a card,
	// and the variants don't make sense there.
	register_block_style( 'vcpa-pet-companion/featured-pets', [
		'name'  => 'card',
		'label' => __( 'Card', 'vcpahumane-pet-companion' ),
	] );
	register_block_style( 'vcpa-pet-companion/featured-pets', [
		'name'  => 'minimal',
		'label' => __( 'Minimal', 'vcpahumane-pet-companion' ),
	] );
	register_block_style( 'vcpa-pet-companion/featured-pets', [
		'name'  => 'overlay',
		'label' => __( 'Overlay', 'vcpahumane-pet-companion' ),
	] );
} );

/**
 * Expose the configured source URL to editor scripts so they can fetch
 * terms and search pets directly from the source REST API.
 */
add_action( 'enqueue_block_editor_assets', static function (): void {
	$source = rtrim( (string) VCPA_Pet_Companion_Settings::get( 'source_url' ), '/' );
	wp_add_inline_script(
		'wp-blocks',
		'window.vcpaPetCompanion = ' . wp_json_encode( [ 'sourceUrl' => $source ] ) . ';',
		'before'
	);
} );

add_action( 'admin_menu', [ 'VCPA_Pet_Companion_Settings', 'register_menu' ] );
add_action( 'admin_init', [ 'VCPA_Pet_Companion_Settings', 'register_settings' ] );
