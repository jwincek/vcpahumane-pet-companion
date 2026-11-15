<?php
/**
 * Fail unless every place that states the plugin's version agrees.
 *
 * - The `Version:` header, which WordPress shows and updates by.
 * - VCPA_PET_COMPANION_VERSION, which versions the stylesheet URL.
 * - Each block's edit.asset.php, which versions its editor script URL. A
 *   stale one lets browsers keep an old editor script: 0.3.0 found both
 *   still at 0.2.2, months after edit.js had changed.
 * - The newest released CHANGELOG.md entry, so a release cannot ship unlogged.
 *
 * Usage: php bin/check-versions.php
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

$root   = dirname( __DIR__ );
$main   = (string) file_get_contents( $root . '/vcpahumane-pet-companion.php' );
$found  = [];
$errors = [];

if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $m ) ) {
	$found['plugin header'] = $m[1];
} else {
	$errors[] = 'no Version: header in vcpahumane-pet-companion.php';
}

if ( preg_match( "/define\(\s*'VCPA_PET_COMPANION_VERSION',\s*'([^']+)'/", $main, $m ) ) {
	$found['VCPA_PET_COMPANION_VERSION'] = $m[1];
} else {
	$errors[] = 'no VCPA_PET_COMPANION_VERSION constant in vcpahumane-pet-companion.php';
}

$assets = glob( $root . '/blocks/*/edit.asset.php' ) ?: [];
if ( ! $assets ) {
	$errors[] = 'no blocks/*/edit.asset.php found';
}
foreach ( $assets as $file ) {
	$relative = substr( $file, strlen( $root ) + 1 );
	if ( preg_match( "/'version'\s*=>\s*'([^']+)'/", (string) file_get_contents( $file ), $m ) ) {
		$found[ $relative ] = $m[1];
	} else {
		$errors[] = "no 'version' in $relative";
	}
}

// An [Unreleased] section collects changes between releases; skip it.
if ( preg_match( '/^## \[(?!Unreleased\])([^\]]+)\]/m', (string) file_get_contents( $root . '/CHANGELOG.md' ), $m ) ) {
	$found['newest CHANGELOG.md entry'] = $m[1];
} else {
	$errors[] = 'no "## [x.y.z]" entry in CHANGELOG.md';
}

if ( count( array_unique( $found ) ) > 1 ) {
	$errors[] = "versions disagree:\n" . implode(
		"\n",
		array_map( static fn( string $where, string $version ): string => "  $version  $where", array_keys( $found ), $found )
	);
}

if ( $errors ) {
	fwrite( STDERR, '✗ ' . implode( "\n✗ ", $errors ) . "\n" );
	exit( 1 );
}

echo '✓ every version is ' . reset( $found ) . ' (' . count( $found ) . " places)\n";
