<?php
/**
 * Bootstrap for the integration suite.
 *
 * Loads the WordPress PHPUnit test library (installed by
 * bin/install-wp-tests.sh), then this plugin on `muplugins_loaded`, so its
 * blocks render through a real WordPress.
 *
 * The plugin's whole job is talking to another site. Tests answer for that
 * site with Fake_Source, and nothing may reach a real one: the source URL
 * defaults to the live shelter, so a forgotten mock would send a test's
 * requests there. The last pre_http_request filter refuses anything the fake
 * did not answer and records it, and every test fails if anything was.
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"WordPress test library not found at {$_tests_dir}.\n" .
		"Run: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] <wp-version>\n" .
		"The version must be explicit (e.g. 7.1): 'latest' is not a tag in the\n" .
		"wordpress-develop repository the library comes from.\n" .
		"Or set WP_TESTS_DIR to an existing installation.\n"
	);
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $_tests_dir . '/includes/functions.php';

$GLOBALS['vcpa_tests_unanswered'] = [];

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/vcpahumane-pet-companion.php';
	}
);

tests_add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$GLOBALS['vcpa_tests_unanswered'][] = $url;
		return new WP_Error( 'vcpa_tests_no_network', 'Tests make no real requests: ' . $url );
	},
	PHP_INT_MAX,
	3
);

require $_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-fake-source.php';
require_once __DIR__ . '/class-companion-testcase.php';
