<?php
/**
 * Plugin Name: Weather Shortcode
 * Description: Wordpress Weather plugin to create a shortcode to collect live weather data from a chosen city. [weather_widget city="London"].
 * Version: 1.0
 * Author: Jordan Bailey
 */

if (!defined('ABSPATH')) exit;

// Debugging for admin
add_action( 'init', function () {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    if ( ! isset( $_GET['debug'] ) || $_GET['debug'] !== '1' ) { return; }

    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_owm_%'
            OR option_name LIKE '_transient_timeout_owm_%'"
    );
} );


// Plugin setup page
add_action( 'admin_menu', function () {
    add_options_page(
        'Weather Shortcode',
        'Weather Shortcode',
        'manage_options',
        'weather-shortcode',
        'bbs_weather_shortcode_settings_page'
    );
});
add_action( 'admin_init', function () {
    register_setting(
        'bbs_weather_shortcode_settings',
        'bbs_weather_shortcode_api_key',
        [
            'sanitize_callback' => 'sanitize_text_field',
        ]
    );
});
function bbs_weather_shortcode_settings_page() {
    ?>
    <div class="wrap">
        <h1>Weather Shortcode</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'bbs_weather_shortcode_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bbs_weather_shortcode_api_key">
                            OpenWeatherMap API Key
                        </label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="bbs_weather_shortcode_api_key"
                            name="bbs_weather_shortcode_api_key"
                            value="<?php echo esc_attr( get_option( 'bbs_weather_shortcode_api_key', '' ) ); ?>"
                            class="regular-text"
                        />
                        <p class="description">
                            Enter your OpenWeatherMap API key.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}



// Weather Object
if ( ! class_exists( 'BBS_OpenWeather' ) ) {
	class BBS_OpenWeather {
	    
		public function __construct( array $args = [] ) {

			$defaults = [
				'api_key'    => get_option( 'bbs_weather_shortcode_api_key', '' ),
				'cache_ttl'  => HOUR_IN_SECONDS,
			];

			$args = wp_parse_args( $args, $defaults );
			$this->api_key   = trim( (string) $args['api_key'] );
			$this->cache_ttl = (int) $args['cache_ttl'];
		}
		
		public function get_lat_lon_from_city( $city ) {

			$city = trim( wp_strip_all_tags( (string) $city ) );
			if ( $city === '' ) {
				return new WP_Error( 'owm_missing_city', 'City is required.' );
			}

			$cache_key = 'owm_geo_' . md5( strtolower( $city ) );
			$cached = get_transient( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
			
			if ( $this->api_key === '' ) {
                return new WP_Error(
                    'owm_missing_key',
                    'OpenWeatherMap API key is not set. Please add it under Settings → Weather Shortcode.'
                );
            }

			$url = add_query_arg(
				[
					'q'     => $city,
					'limit' => 1,
					'appid' => $this->api_key,
				],
				'https://api.openweathermap.org/geo/1.0/direct'
			);
			$response = wp_remote_get( $url, [
				'timeout' => 12,
				'headers' => [ 'Accept' => 'application/json' ],
			] );

			if ( is_wp_error( $response ) ) return $response;

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'owm_geo_http_error', 'OpenWeatherMap geocoding request failed.', [
					'status_code' => $code,
					'body'        => $body,
					'city'        => $city,
				] );
			}

			$json = json_decode( $body, true );
			if ( ! is_array( $json ) ) {
				return new WP_Error( 'owm_geo_bad_json', 'Invalid JSON returned from the OpenWeatherMap geocoding.' );
			}
			
			if ( ! isset( $json[0]['lat'], $json[0]['lon'] ) || !is_numeric( $json[0]['lat']) || !is_numeric( $json[0]['lon']) ) {
				return new WP_Error( 'owm_geo_bad_data', 'Invalid data type sent from OpenWeatherMap.', [
					'city' => $city,
					'data' => $json,
				] );
			}

			$result = [
				'lat'  => (float) $json[0]['lat'],
				'lon'  => (float) $json[0]['lon'],
				'name' => isset( $json[0]['name'] ) ? (string) $json[0]['name'] : $city,
			];

			set_transient( $cache_key, $result, $this->cache_ttl );
			return $result;
		}

		public function get_current_weather( $lat, $lon ) {

			if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
				return new WP_Error( 'owm_missing_coords', 'Latitude and longitude are required.' );
			}

			$lat = (float) $lat;
			$lon = (float) $lon;

			$cache_key = 'owm_weather_' . md5( $lat . ',' . $lon );
			$cached    = get_transient( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
			
            if ( $this->api_key === '' ) {
                return new WP_Error(
                    'owm_missing_key',
                    'OpenWeatherMap API key is not set. Please add it under Settings → Weather Shortcode.'
                );
            }
			$url = add_query_arg(
				[
					'lat'   => $lat,
					'lon'   => $lon,
					'units'   => 'metric',
					'appid' => $this->api_key,
				],
				'https://api.openweathermap.org/data/2.5/weather'
			);

			$response = wp_remote_get( $url, [
				'timeout' => 12,
				'headers' => [ 'Accept' => 'application/json' ],
			] );

			if ( is_wp_error( $response ) ) return $response;

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'owm_http_error', 'OpenWeatherMap weather request failed.', [
					'status_code' => $code,
					'body'        => $body,
					'lat'         => $lat,
					'lon'         => $lon,
				] );
			}
			
			
			$data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                return new WP_Error( 'owm_bad_json', 'Invalid JSON returned from OpenWeatherMap weather.' );
            }

			if (
                ! isset( $data['main']['temp'], $data['weather'][0]['description'] ) ||
                ! is_numeric( $data['main']['temp'] ) ||
                ! is_string( $data['weather'][0]['description'] )
            ) {
                return new WP_Error( 'owm_bad_json_shape', 'Unexpected data returned from OpenWeatherMap.' );
            }
            
            $temp = (float) $data['main']['temp'];
            
            $desc = trim( $data['weather'][0]['description'] );
            $desc = wp_strip_all_tags( $desc );
            $desc = mb_substr( $desc, 0, 120 );
            
            $data = [
                'temp' => $temp,
                'desc' => $desc,
            ];

			set_transient( $cache_key, $data, $this->cache_ttl );
			return $data;
		}

		public function get_weather_for_city( $city ) {
			$geo = $this->get_lat_lon_from_city( $city );
			if ( is_wp_error( $geo ) ) return $geo;
			return $this->get_current_weather( $geo['lat'], $geo['lon'] );
		}
		
	}
}


add_shortcode( 'weather_widget', function( $atts ) {
	$atts = shortcode_atts(
		[
			'city' => '',
		],
		$atts,
		'weather_widget'
	);
	$city = (string) $atts['city'];
	$owm  = new BBS_OpenWeather();
	$data = $owm->get_weather_for_city( $city );
	
	if ( is_wp_error( $data ) ) {
		return '<p style="display:block; padding:10px;">' . esc_html__( 'Weather unavailable.', 'weather-shortcode' ) . '</p>';
	}

	$temp = isset( $data['temp'] ) ? round( (float) $data['temp'] ) : null;
	$desc = ! empty( $data['desc'] ) ? (string) $data['desc'] : '';

	ob_start(); ?>
		<p style="display:block; padding:10px;">
			<?php if ( $temp !== null && $desc !== '' ) : ?>
				<span><?php echo esc_html( $temp ); ?>°C</span>, <span><?php echo esc_html( ucwords( $desc ) ); ?></span>
			<?php endif; ?>
		</p>
	<?php
	return ob_get_clean();
	
});

