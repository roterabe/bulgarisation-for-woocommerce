<?php
namespace Woo_BG\Shipping\Econt;
use Woo_BG\Container\Client;
use Woo_BG\Transliteration;

defined( 'ABSPATH' ) || exit;

class Office {
	private static $container = null;
	
	public function __construct() {
		add_filter( 'woocommerce_after_shipping_rate', array( __CLASS__, 'delivery_with_econt_render_form_button' ), 20, 2 );

		add_action( 'wp_ajax_woo_bg_econt_load_offices', array( __CLASS__, 'load_offices' ) );
		add_action( 'wp_ajax_nopriv_woo_bg_econt_load_offices', array( __CLASS__, 'load_offices' ) );
	}

	public static function delivery_with_econt_render_form_button( $method, $index ) {
		if ( $method->get_method_id() === Method::METHOD_ID ) {
			if ( $method->meta_data['delivery_type'] === 'office' ) {
				echo '<div id="woo-bg-econt-shipping-to--office" class="woo-bg-additional-fields" data-type="office"></div>';
			}
		}
	}

	public static function get_i18n() {
		return array(
			'selected' => __( 'Selected', 'woo-bg' ),
			'choose' => __( 'Choose', 'woo-bg' ),
			'searchOffice' => __( 'Search office', 'woo-bg' ), 
			'select' => __( 'Select', 'woo-bg' ), 
			'noResult' => __( 'No results was found for this city', 'woo-bg' ),
			'noOptions' => __( 'Start typing office', 'woo-bg' ), 
			'officeLocator' => __( 'Office locator', 'woo-bg' ),
		);
	}

	public static function load_offices() {
		self::$container = woo_bg()->container();
		$args = [];
		$raw_state = sanitize_text_field( $_POST['state'] );
		$states = woo_bg_return_bg_states();
		$state = $states[ $raw_state ];
		$raw_city = sanitize_text_field( $_POST['city'] );
		$city = mb_strtolower( Transliteration::latin2cyrillic( $raw_city ) );
		$cities = self::$container[ Client::ECONT_CITIES ]->get_cities_by_region( $state );
		$cities_only_names = [];
		
		if ( !empty( $cities ) ) {
			$cities_only_names = array_map( function( $city ) {
				return mb_strtolower( $city['name'] );
			}, $cities );
		}

		if ( !in_array( mb_strtolower( $city ), $cities_only_names ) ) {
			$args[ 'status' ] = 'invalid-city';
			$args[ 'error' ] = sprintf( __( '%s is not found in %s region.', 'woo-bg' ), $city, $state );
		} else {
			$city_key = array_search( $city, array_column( $cities, 'name' ) );

			$args[ 'offices' ] = self::$container[ Client::ECONT_OFFICES ]->get_offices( $cities[ $city_key ]['id'] )['offices'];
			$args[ 'status' ] = 'valid-city';
		}

		wp_send_json_success( $args );

		wp_die();
	}
}
