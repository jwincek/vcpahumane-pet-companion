<?php
/**
 * Base case: a fake shelter site as the source, and no real requests.
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

abstract class Companion_TestCase extends WP_UnitTestCase {

	protected Fake_Source $source;

	public function set_up(): void {
		parent::set_up();

		$this->source = new Fake_Source();
		add_filter( 'pre_http_request', [ $this->source, 'respond' ], 10, 3 );

		update_option(
			VCPA_Pet_Companion_Settings::OPTION_KEY,
			[
				'source_url'  => Fake_Source::URL,
				'link_domain' => '',
				'cache_ttl'   => 3600,
			]
		);

		$GLOBALS['vcpa_tests_unanswered'] = [];
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$unanswered                       = $GLOBALS['vcpa_tests_unanswered'];
		$GLOBALS['vcpa_tests_unanswered'] = [];

		parent::tear_down();

		$this->assertSame( [], $unanswered, 'a request went somewhere other than the fake source' );
	}

	/**
	 * Render a block as the front end does.
	 *
	 * @param string               $name  Block name without the namespace.
	 * @param array<string, mixed> $attrs Attributes.
	 */
	protected function render( string $name, array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => 'vcpa-pet-companion/' . $name,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * A Pet Card for a pet as the editor saves it: ID, slug and cached name.
	 *
	 * @param int                  $id    Pet ID on the source.
	 * @param array<string, mixed> $attrs More attributes.
	 */
	protected function card( int $id, array $attrs = [] ): string {
		$pet = $this->source->pets[ $id ] ?? [];

		return $this->render(
			'pet-card',
			$attrs + [
				'petId'    => $id,
				'petSlug'  => $pet['slug'] ?? '',
				'petName'  => $pet['title'] ?? '',
				'petImage' => $pet['image'] ?? '',
			]
		);
	}

	protected function heading( string $html ): string {
		return preg_match( '#<h2[^>]*>(.*?)</h2>#s', $html, $m ) ? html_entity_decode( trim( $m[1] ), ENT_QUOTES ) : '';
	}

	/**
	 * @return array{0: string, 1: string} The call to action's href and text.
	 */
	protected function cta( string $html ): array {
		return preg_match( '#<a class="vcpa-pet-profile__cta" href="([^"]*)"[^>]*>(.*?)</a>#s', $html, $m )
			? [ html_entity_decode( $m[1] ), html_entity_decode( trim( $m[2] ), ENT_QUOTES ) ]
			: [ '', '' ];
	}

	/**
	 * The transient the client stored for a URL, with its expiry.
	 *
	 * @return array{value: mixed, expires_in: int}
	 */
	protected function cached( string $url ): array {
		$key = 'vcpa_pets_' . md5( $url );

		return [
			'value'      => get_transient( $key ),
			'expires_in' => (int) get_option( '_transient_timeout_' . $key ) - time(),
		];
	}
}
