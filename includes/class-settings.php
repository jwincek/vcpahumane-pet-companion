<?php
/**
 * Settings page: configure source URL, cache TTL, and link domain.
 *
 * @package VCPA_Pet_Companion
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VCPA_Pet_Companion_Settings {

	public const OPTION_KEY = 'vcpa_pet_companion';

	public const DEFAULTS = [
		'source_url'  => 'https://vcpahumane.org',
		'link_domain' => 'https://vcpahumane.org',
		'cache_ttl'   => 21600, // 6 hours. Source plugin only re-syncs from Petstablished daily.
	];

	public static function get( string $key ) {
		$opts = get_option( self::OPTION_KEY, [] );
		$opts = is_array( $opts ) ? $opts : [];
		return $opts[ $key ] ?? ( self::DEFAULTS[ $key ] ?? null );
	}

	public static function register_menu(): void {
		add_options_page(
			__( 'VCPA Pet Companion', 'vcpahumane-pet-companion' ),
			__( 'VCPA Pets', 'vcpahumane-pet-companion' ),
			'manage_options',
			'vcpa-pet-companion',
			[ self::class, 'render_page' ]
		);
	}

	public static function register_settings(): void {
		register_setting(
			'vcpa_pet_companion_group',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => self::DEFAULTS,
			]
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		return [
			'source_url'  => esc_url_raw( $input['source_url'] ?? self::DEFAULTS['source_url'] ),
			'link_domain' => esc_url_raw( $input['link_domain'] ?? self::DEFAULTS['link_domain'] ),
			'cache_ttl'   => max( 60, (int) ( $input['cache_ttl'] ?? self::DEFAULTS['cache_ttl'] ) ),
		];
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Cache-flush handler.
		if ( isset( $_POST['vcpa_flush_cache'] ) && check_admin_referer( 'vcpa_flush_cache' ) ) {
			VCPA_Pet_Companion_Client::flush_cache();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Pet cache cleared.', 'vcpahumane-pet-companion' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VCPA Pet Companion', 'vcpahumane-pet-companion' ); ?></h1>
			<p><?php esc_html_e( 'Display adoptable pets from the Venango County Humane Society on this site.', 'vcpahumane-pet-companion' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'vcpa_pet_companion_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="vcpa_source_url"><?php esc_html_e( 'Source site URL', 'vcpahumane-pet-companion' ); ?></label></th>
						<td>
							<input type="url" id="vcpa_source_url" class="regular-text"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[source_url]"
								value="<?php echo esc_attr( (string) self::get( 'source_url' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'The WordPress site that hosts the pet data (REST API root).', 'vcpahumane-pet-companion' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="vcpa_link_domain"><?php esc_html_e( 'Adoption link domain', 'vcpahumane-pet-companion' ); ?></label></th>
						<td>
							<input type="url" id="vcpa_link_domain" class="regular-text"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[link_domain]"
								value="<?php echo esc_attr( (string) self::get( 'link_domain' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Where pet links point. Pet pages live at /adopt/pets/{slug}/.', 'vcpahumane-pet-companion' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="vcpa_cache_ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'vcpahumane-pet-companion' ); ?></label></th>
						<td>
							<input type="number" id="vcpa_cache_ttl" min="60" step="60"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cache_ttl]"
								value="<?php echo esc_attr( (string) self::get( 'cache_ttl' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'How long to cache pet data locally before refetching. Default 21600 (6 hours). The source site re-syncs from Petstablished once a day, so values below an hour just create extra traffic. Minimum 60.', 'vcpahumane-pet-companion' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Cache', 'vcpahumane-pet-companion' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'vcpa_flush_cache' ); ?>
				<p>
					<button type="submit" name="vcpa_flush_cache" value="1" class="button">
						<?php esc_html_e( 'Clear pet cache now', 'vcpahumane-pet-companion' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
