<?php
/**
 * The REST client: requests, caching and links.
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

final class ClientTest extends Companion_TestCase {

	private function set_settings( array $changes ): void {
		update_option(
			VCPA_Pet_Companion_Settings::OPTION_KEY,
			array_merge( (array) get_option( VCPA_Pet_Companion_Settings::OPTION_KEY ), $changes )
		);
	}

	// ─── Caching ────────────────────────────────────────────────────────────

	public function test_a_result_is_fetched_once_per_ttl(): void {
		$this->source->add_pet();

		VCPA_Pet_Companion_Client::get_pets();
		VCPA_Pet_Companion_Client::get_pets();

		$this->assertCount( 1, $this->source->requests );
		$this->assertEqualsWithDelta( 3600, $this->cached( $this->source->requests[0] )['expires_in'], 5 );
	}

	public function test_changing_the_source_never_serves_the_old_sources_pets(): void {
		$this->source->hosts[] = 'other-shelter.test';
		$this->source->add_pet();

		VCPA_Pet_Companion_Client::get_pets();
		$this->set_settings( [ 'source_url' => 'https://other-shelter.test' ] );
		VCPA_Pet_Companion_Client::get_pets();

		$this->assertCount( 1, $this->source->requests_to( 'https://other-shelter.test/' ), 'cached results are keyed by the source too' );
	}

	public function test_a_network_failure_is_remembered_for_seconds_and_an_error_for_a_minute(): void {
		$this->source->failure = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		$soft                  = VCPA_Pet_Companion_Client::get_pets( [ 'search' => 'soft' ] );
		$this->assertSame( 'cURL error 28: timed out', $soft['error'] );
		$this->assertLessThanOrEqual( 5, $this->cached( end( $this->source->requests ) )['expires_in'] );

		$this->source->failure = 503;
		$hard                  = VCPA_Pet_Companion_Client::get_pets( [ 'search' => 'hard' ] );
		$this->assertSame( 'Source returned HTTP 503.', $hard['error'] );
		$this->assertEqualsWithDelta( 60, $this->cached( end( $this->source->requests ) )['expires_in'], 2 );

		$asked = count( $this->source->requests );
		VCPA_Pet_Companion_Client::get_pets( [ 'search' => 'hard' ] );
		$this->assertCount( $asked, $this->source->requests, 'the failure is answered from the cache' );
	}

	public function test_a_route_the_source_lacks_is_remembered_for_the_full_ttl(): void {
		$this->source->answers_adoptions = false;

		$this->assertSame( [ 'adopted' => null, 'listing_url' => '' ], VCPA_Pet_Companion_Client::adoption( 7 ) );
		$this->assertEqualsWithDelta( 3600, $this->cached( $this->source->requests[0] )['expires_in'], 5 );
	}

	public function test_a_missing_pet_route_is_an_error_not_an_empty_shelter(): void {
		$this->source->hosts[] = 'not-a-shelter.test';
		$this->set_settings( [ 'source_url' => 'https://not-a-shelter.test' ] );
		$this->source->failure = 404;

		$this->assertSame( 'Source returned HTTP 404.', VCPA_Pet_Companion_Client::get_pets()['error'] );
	}

	public function test_flush_cache_forgets_everything(): void {
		$this->source->add_pet();
		VCPA_Pet_Companion_Client::get_pets();
		VCPA_Pet_Companion_Client::statuses();

		VCPA_Pet_Companion_Client::flush_cache();
		VCPA_Pet_Companion_Client::get_pets();
		VCPA_Pet_Companion_Client::statuses();

		$this->assertCount( 4, $this->source->requests );
	}

	public function test_query_values_are_encoded(): void {
		VCPA_Pet_Companion_Client::get_pets( [ 'search' => 'Mr & Mrs #1' ] );

		$this->assertStringContainsString( 'search=Mr%20%26%20Mrs%20%231', $this->source->requests[0] );
	}

	public function test_no_source_means_no_request(): void {
		$this->set_settings( [ 'source_url' => '' ] );

		$this->assertSame( 'Source URL is not configured.', VCPA_Pet_Companion_Client::get_pets()['error'] );
		$this->assertSame( [], $this->source->requests );
	}

	// ─── Links ──────────────────────────────────────────────────────────────

	public function test_a_link_domain_replaces_only_the_domain(): void {
		$this->set_settings( [ 'link_domain' => 'https://www.example.org:8443/ignored-path' ] );

		$this->assertSame(
			'https://www.example.org:8443/shelter/adopt/pets/bagel/?ref=partner#photos',
			VCPA_Pet_Companion_Client::pet_url( [ 'link' => 'http://shelter.test/shelter/adopt/pets/bagel/?ref=partner#photos' ] )
		);
		$this->assertSame( 'https://www.example.org:8443/our-animals/', VCPA_Pet_Companion_Client::listing_url( Fake_Source::URL . '/our-animals/' ) );
	}

	public function test_without_a_link_the_shelterkit_pets_address_is_used(): void {
		$this->assertSame( Fake_Source::URL . '/adopt/pets/bagel/', VCPA_Pet_Companion_Client::pet_url( [ 'slug' => 'bagel' ] ) );
		$this->assertSame( Fake_Source::URL . '/adopt/pets/', VCPA_Pet_Companion_Client::listing_url() );

		$this->set_settings( [ 'link_domain' => 'https://example.org' ] );
		$this->assertSame( 'https://example.org/adopt/pets/bagel/', VCPA_Pet_Companion_Client::pet_url( [ 'slug' => 'bagel' ] ) );
	}

	// ─── Settings ───────────────────────────────────────────────────────────

	public function test_settings_are_sanitized(): void {
		$this->assertSame(
			[
				'source_url'  => 'https://shelter.test',
				'link_domain' => '',
				'cache_ttl'   => 60,
			],
			VCPA_Pet_Companion_Settings::sanitize(
				[
					'source_url'  => 'https://shelter.test',
					'link_domain' => '',
					'cache_ttl'   => '5',
				]
			)
		);
		$this->assertSame( '', VCPA_Pet_Companion_Settings::sanitize( [ 'source_url' => 'javascript:alert(1)' ] )['source_url'] );
	}
}
