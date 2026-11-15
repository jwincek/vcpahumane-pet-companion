<?php
/**
 * Which pets the Featured Pets grid invites visitors to meet.
 *
 * Adoption Pending and On Hold pets stay listed on the shelter's site, but are
 * not taking applications. A grid of "Meet me →" cards should hold only pets
 * that are.
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

final class FeaturedPetsTest extends Companion_TestCase {

	/**
	 * @return string[] Card titles, in order.
	 */
	private function titles( string $html ): array {
		preg_match_all( '#<h3 class="vcpa-pet-card__title">(.*?)</h3>#s', $html, $m );
		return array_map( static fn( string $t ): string => html_entity_decode( $t, ENT_QUOTES ), $m[1] );
	}

	public function test_only_available_pets_are_shown(): void {
		$this->source->add_pet( [ 'title' => 'Available' ] );
		$this->source->add_pet( [ 'title' => 'Pending', 'status' => Fake_Source::PENDING ] );
		$this->source->add_pet( [ 'title' => 'Held', 'status' => Fake_Source::HOLD ] );
		$this->source->add_pet( [ 'title' => 'Hand-entered, returned', 'status' => Fake_Source::RETURNED ] );

		$html = $this->render( 'featured-pets', [ 'random' => false ] );

		$this->assertSame( [ 'Available' ], $this->titles( $html ) );
		$this->assertCount( 1, $this->source->requests_to( 'pet_status=' . Fake_Source::AVAILABLE ), 'the source does the filtering, so the count holds' );
	}

	public function test_a_source_without_statuses_shows_its_pets(): void {
		$this->source->has_statuses = false;
		$this->source->add_pet( [ 'title' => 'One' ] );
		$this->source->add_pet( [ 'title' => 'Two' ] );

		$this->assertSame( [ 'One', 'Two' ], $this->titles( $this->render( 'featured-pets', [ 'random' => false ] ) ) );
		$this->assertSame( [], $this->source->requests_to( 'pet_status=' ) );
	}

	public function test_animal_and_attribute_filters_reach_the_source(): void {
		$this->source->add_pet( [ 'title' => 'Dog', 'animal' => 5 ] );
		$this->source->add_pet( [ 'title' => 'Cat', 'animal' => 6 ] );
		$this->source->add_pet( [ 'title' => 'Calm dog', 'animal' => 5, 'attrs' => [ 9 ] ] );

		$this->assertSame( [ 'Dog', 'Calm dog' ], $this->titles( $this->render( 'featured-pets', [ 'random' => false, 'animals' => [ 5 ] ] ) ) );
		$this->assertSame( [ 'Calm dog' ], $this->titles( $this->render( 'featured-pets', [ 'random' => false, 'animals' => [ 5, 7 ], 'petAttributes' => [ 9 ] ] ) ) );
	}

	public function test_count_limits_the_grid(): void {
		foreach ( range( 1, 6 ) as $n ) {
			$this->source->add_pet( [ 'title' => "Pet $n" ] );
		}

		$this->assertCount( 2, $this->titles( $this->render( 'featured-pets', [ 'random' => false, 'count' => 2 ] ) ) );
		$this->assertCount( 4, $this->titles( $this->render( 'featured-pets', [ 'random' => true, 'count' => 4 ] ) ) );
	}

	public function test_cards_link_where_the_source_says_each_pet_is(): void {
		$this->source->add_pet( [ 'link' => Fake_Source::URL . '/animals/bagel-7/' ] );

		$this->assertStringContainsString( 'href="' . Fake_Source::URL . '/animals/bagel-7/"', $this->render( 'featured-pets', [ 'random' => false ] ) );
	}

	public function test_an_unreachable_source_shows_the_empty_state(): void {
		$this->source->add_pet();
		$this->source->failure = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		$html = $this->render( 'featured-pets' );

		$this->assertStringContainsString( 'No adoptable pets to show right now.', $html );
		$this->assertSame( [], $this->titles( $html ) );
	}
}
