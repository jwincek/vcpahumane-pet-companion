<?php
/**
 * What a Pet Card says about its pet.
 *
 * A partner's post embeds a pet that later leaves the shelter's listings.
 * Until 0.3.0 the card said "found a home!" whenever the pet was missing,
 * including when the source was simply down. Since ShelterKit Pets 2.0 a pet
 * also leaves the listings when it is returned, transferred or euthanized,
 * so the card must celebrate only a confirmed adoption.
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

final class PetCardTest extends Companion_TestCase {

	// ─── Listed pets ────────────────────────────────────────────────────────

	public function test_an_available_pet_invites_an_application(): void {
		$id   = $this->source->add_pet( [ 'title' => 'Bagel', 'link' => Fake_Source::URL . '/animals/bagel-7/' ] );
		$html = $this->card( $id );

		$this->assertSame( 'Bagel', $this->heading( $html ) );
		$this->assertSame( [ Fake_Source::URL . '/animals/bagel-7/', 'Start the adoption process' ], $this->cta( $html ), 'links where the source says the page is' );
		$this->assertStringNotContainsString( 'vcpa-pet-profile__badge', $html );
	}

	public function test_a_pending_or_held_pet_shows_its_status_instead(): void {
		foreach ( [ Fake_Source::PENDING => 'Adoption Pending', Fake_Source::HOLD => 'On Hold' ] as $status => $name ) {
			$id   = $this->source->add_pet( [ 'title' => 'Waffle', 'status' => $status ] );
			$html = $this->card( $id );

			$this->assertStringContainsString( '>' . $name . '</span>', $html );
			$this->assertSame( 'Learn more about Waffle', $this->cta( $html )[1] );
			$this->assertStringNotContainsString( 'Start the adoption process', $html );
		}
	}

	public function test_a_listed_pet_with_a_status_the_site_hides_takes_no_applications(): void {
		// Entered by hand and marked Returned: ShelterKit Pets leaves it published,
		// and only the term ID, not the status, is public.
		$id   = $this->source->add_pet( [ 'title' => 'Mochi', 'status' => Fake_Source::RETURNED ] );
		$html = $this->card( $id );

		$this->assertSame( 'Mochi', $this->heading( $html ) );
		$this->assertSame( 'Learn more about Mochi', $this->cta( $html )[1] );
		$this->assertStringNotContainsString( 'Start the adoption process', $html );
		$this->assertStringNotContainsString( 'Returned', $html, 'the hidden status stays hidden' );
		$this->assertStringNotContainsString( 'found a home', $html );
	}

	public function test_a_listed_adopted_pet_found_a_home(): void {
		// Shown by a shelter that lists adopted pets...
		$this->source->shown[] = Fake_Source::ADOPTED;
		$shown                 = $this->source->add_pet( [ 'title' => 'Pickle', 'status' => Fake_Source::ADOPTED ] );
		$this->assertSame( 'Pickle found a home!', $this->heading( $this->card( $shown ) ) );

		// ...and entered by hand at one that does not.
		array_pop( $this->source->shown );
		VCPA_Pet_Companion_Client::flush_cache();
		$hand = $this->source->add_pet( [ 'title' => 'Olive', 'status' => Fake_Source::ADOPTED ] );
		$html = $this->card( $hand );
		$this->assertSame( 'Olive found a home!', $this->heading( $html ) );
		$this->assertStringContainsString( 'Adopted!', $html );
	}

	public function test_a_card_saved_with_only_a_slug_finds_its_pet(): void {
		$this->source->add_pet( [ 'slug' => 'bagel', 'title' => 'Bagel' ] );

		$this->assertSame( 'Bagel', $this->heading( $this->render( 'pet-card', [ 'petSlug' => 'bagel' ] ) ) );
	}

	// ─── Unlisted pets ──────────────────────────────────────────────────────

	public function test_an_adopted_pet_found_a_home(): void {
		$this->source->listing_url = Fake_Source::URL . '/our-animals/';
		$id                        = $this->source->add_pet( [ 'title' => 'Brutus', 'status' => Fake_Source::ADOPTED, 'listed' => false ] );
		$html                      = $this->card( $id );

		$this->assertSame( 'Brutus found a home!', $this->heading( $html ) );
		$this->assertStringContainsString( 'Adopted!', $html );
		$this->assertSame( [ Fake_Source::URL . '/our-animals/', 'Meet other adoptable pets' ], $this->cta( $html ), 'the listing the source reports' );
		$this->assertStringContainsString( $this->source->pets[ $id ]['image'], $html, 'the photo cached at pick time' );
	}

	public function test_any_other_unlisted_pet_gets_no_celebration(): void {
		foreach ( [ Fake_Source::RETURNED, Fake_Source::AVAILABLE, Fake_Source::HOLD ] as $status ) {
			$id   = $this->source->add_pet( [ 'title' => 'Callie', 'status' => $status, 'listed' => false ] );
			$html = $this->card( $id );

			$this->assertSame( "Callie isn't up for adoption right now", $this->heading( $html ) );
			$this->assertStringNotContainsString( 'found a home', $html );
			$this->assertStringNotContainsString( 'Adopted!', $html );
			$this->assertSame( 'Meet the adoptable pets', $this->cta( $html )[1] );
		}
	}

	public function test_a_source_before_shelterkit_pets_2_1_cannot_say_so_nobody_is_celebrated(): void {
		$this->source->answers_adoptions = false;
		$id                              = $this->source->add_pet( [ 'title' => 'Brutus', 'status' => Fake_Source::ADOPTED, 'listed' => false ] );
		$html                            = $this->card( $id );

		$this->assertSame( "Brutus isn't up for adoption right now", $this->heading( $html ) );
		$this->assertSame( Fake_Source::URL . '/adopt/pets/', $this->cta( $html )[0], "ShelterKit Pets' listing address" );

		$asked = count( $this->source->requests );
		$this->card( $id );
		$this->assertCount( $asked, $this->source->requests, 'the missing route is remembered, not asked again' );
	}

	public function test_an_unlisted_pet_with_nothing_cached_shows_the_empty_state(): void {
		$id   = $this->source->add_pet( [ 'status' => Fake_Source::ADOPTED, 'listed' => false ] );
		$html = $this->render( 'pet-card', [ 'petId' => $id ] );

		$this->assertStringContainsString( 'vcpa-pet-empty', $html );
		$this->assertStringNotContainsString( 'found a home', $html );
	}

	// ─── The source can't be read ───────────────────────────────────────────

	public function test_an_unreachable_source_makes_no_claim_about_the_pet(): void {
		$id = $this->source->add_pet( [ 'title' => 'Brutus', 'status' => Fake_Source::ADOPTED, 'listed' => false ] );

		foreach ( [ new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), 500, 403 ] as $failure ) {
			VCPA_Pet_Companion_Client::flush_cache();
			$this->source->failure = $failure;
			$html                  = $this->card( $id );

			$this->assertStringContainsString( 'vcpa-pet-empty', $html );
			$this->assertStringNotContainsString( 'Brutus', $html );
			$this->assertStringNotContainsString( "isn't up for adoption", $html );
		}
	}

	public function test_only_administrators_see_why_the_source_failed(): void {
		$this->source->failure = 500;
		$id                    = $this->source->add_pet( [ 'listed' => false ] );

		$this->assertStringNotContainsString( 'HTTP 500', $this->card( $id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertStringContainsString( 'Could not load pets: Source returned HTTP 500.', $this->card( $id ) );
	}

	// ─── The editor ─────────────────────────────────────────────────────────

	public function test_an_empty_card_nudges_editors_and_shows_visitors_nothing(): void {
		$this->assertSame( '', trim( $this->render( 'pet-card' ) ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertStringContainsString( 'Pick a pet in the block sidebar.', $this->render( 'pet-card' ) );
		$this->assertSame( [], $this->source->requests );
	}

	// ─── Another site's data ────────────────────────────────────────────────

	/**
	 * The card prints another site's data. Whatever arrives must leave
	 * escaped, in every state, including what was cached in the block.
	 */
	public function test_hostile_source_data_is_escaped_in_every_state(): void {
		$evil = [
			'title'   => '<img src=x onerror=alert(1)>Bagel',
			'content' => '<p>Hi</p><script>alert(2)</script><a href="javascript:alert(3)">x</a>',
			'excerpt' => '<script>alert(4)</script>',
			'link'    => 'javascript:alert(5)',
			'image'   => '" onerror="alert(6)',
		];

		$listed   = $this->source->add_pet( $evil );
		$held     = $this->source->add_pet( $evil + [ 'status' => Fake_Source::HOLD ] );
		$adopted  = $this->source->add_pet( $evil + [ 'status' => Fake_Source::ADOPTED, 'listed' => false ] );
		$unlisted = $this->source->add_pet( $evil + [ 'listed' => false ] );

		$pages = [
			$this->card( $listed ),
			$this->card( $listed, [ 'showFullStory' => false ] ),
			$this->card( $held ),
			$this->card( $adopted ),
			$this->card( $unlisted ),
			$this->render( 'featured-pets', [ 'random' => false ] ),
		];

		foreach ( $pages as $html ) {
			$this->assertDoesNotMatchRegularExpression( '/<script|<img src=x|javascript:|onerror="alert/i', $html );
		}
	}
}
