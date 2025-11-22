<?php
/**
 * Plugin Name: AirMeat Distance Shipping Logic
 * Description: Custom WooCommerce shipping method using Google Maps Distance Matrix API
 * Author: AirMeat Developers
 * Version: 1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// --------------------------------------------------------------------------------------------------
// PART 1: REGISTER THE CUSTOM SHIPPING METHOD
// --------------------------------------------------------------------------------------------------

add_action( 'woocommerce_shipping_init', 'airmeat_shipping_method_init' );

function airmeat_shipping_method_init() {
    // Check if the WooCommerce shipping class exists before proceeding
    if ( ! class_exists( 'WC_Shipping_Method' ) ) {
        return;
    }
    
    // Define the custom shipping class
    class WC_AirMeat_Distance_Shipping extends WC_Shipping_Method {

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'airmeat_local_delivery'; // Unique ID for your method
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = __( 'AirMeat Local Delivery', 'airmeat-shipping' );
            $this->method_description = __( 'Tiered delivery rate based on Google Maps road distance (0-50km).', 'airmeat-shipping' );
            $this->supports           = array( 'shipping', 'instance-settings' );
            $this->title              = __( 'AirMeat Local Delivery', 'airmeat-shipping' ); // Displayed to customer
            
            // Call the init function to load settings
            $this->init();
        }

        private function init() {
            // Placeholder for any settings initialization (not needed for this code)
        }
        
        // ------------------------------------------------------------------------------------------
        // PART 2: THE CORE CALCULATION LOGIC (Runs on Checkout)
        // ------------------------------------------------------------------------------------------
        
       public function calculate_shipping( $package = array() ) {
            global $WCFMmp;
            $api_key = 'AIzaSyAs3cZiz-vQXLn7zUQsDlChV6rsO--R9J8'; // !!! REPLACE WITH YOUR ACTUAL KEY !!!
            $distance_km = 0;
            $vendor_lat = null;
            $vendor_lon = null;
            $customer_lat = null;
            $customer_lon = null;

            // --- A. GET VENDOR LOCATION (ORIGIN) ---
            
            // 1. Get the Vendor ID (This is the most crucial WCFM integration step)
            $vendor_id = 0;
            if ( function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
                foreach ( $package['contents'] as $cart_item_key => $cart_item ) {
                    $product_id = $cart_item['product_id'];
                    $vendor_id = wcfm_get_vendor_id_by_post( $product_id );
                    if ( $vendor_id ) break; 
                }
            }
            
            // 2. Pull the Vendor's stored Lat/Lng from user meta
            if ( $vendor_id ) {
                $vendor_lat = get_user_meta( $vendor_id, '_wcfm_store_lat', true );
                $vendor_lon = get_user_meta( $vendor_id, '_wcfm_store_lng', true );
            }
            
            // --- B. GET CUSTOMER LOCATION (DESTINATION) ---
            
            // 1. Build the customer address string
            $customer_address = $package['destination']['address'] . ', ' . $package['destination']['city'] . ', ' . $package['destination']['postcode'];

            // 2. Geocode the customer address (convert string address to Lat/Lng)
            $customer_coords = airmeat_geocode_address( $customer_address, $api_key );
            
            if ( ! is_wp_error( $customer_coords ) && $customer_coords['lat'] ) {
                $customer_lat = $customer_coords['lat'];
                $customer_lon = $customer_coords['lng'];
            }

            // --- C. CALCULATE ROAD DISTANCE & APPLY BOUNDARY ---

            if ( $vendor_lat && $vendor_lon && $customer_lat && $customer_lon ) {
                
                // 1. Call Google Maps Distance Matrix API for accurate road distance
                $distance_response = airmeat_get_google_distance(
                    $vendor_lat, 
                    $vendor_lon, 
                    $customer_lat, 
                    $customer_lon, 
                    $api_key
                );

                if ( ! is_wp_error( $distance_response ) && $distance_response > 0 ) {
                    $distance_km = $distance_response; // distance in kilometers
                    
                    // 2. Apply the 50km Boundary Check
                    if ( $distance_km > 50 ) {
                        // Too far: Do NOT add a rate, effectively disabling the method
                        return;
                    }
                    
                    // 3. Apply the Tiered Fixed Rate Logic
                    $delivery_cost = airmeat_calculate_tiered_rate( $distance_km );
                    
                    // 4. Create the final shipping rate for WooCommerce
                    $rate = array(
                        'id'    => $this->id . ':' . $this->instance_id,
                        'label' => $this->title . ' (' . round( $distance_km, 1 ) . ' km)',
                        'cost'  => $delivery_cost,
                        'meta_data' => array('Distance (km)' => round( $distance_km, 1 ))
                    );
                    
                    $this->add_rate( $rate );

                } else {
                     // Add a debug note if the API call failed
                     $this->add_rate( array(
                        'id'    => $this->id . ':api_error',
                        'label' => 'Delivery Unavailable (API Error)',
                        'cost'  => 0.00,
                     ));
                }
            }
        } // end calculate_shipping
    } // end class WC_AirMeat_Distance_Shipping
    
    // Register the class with WooCommerce
    function add_airmeat_shipping_method( $methods ) {
        $methods['airmeat_local_delivery'] = 'WC_AirMeat_Distance_Shipping';
        return $methods;
    }
    add_filter( 'woocommerce_shipping_methods', 'add_airmeat_shipping_method' );
}

// --------------------------------------------------------------------------------------------------
// PART 3: HELPER FUNCTIONS (API Calls and Pricing Tiers)
// --------------------------------------------------------------------------------------------------

/**
 * Helper: Calculates the delivery fee based on distance tiers (0km - 50km boundary).
 */
/**
 * Helper: Calculates the delivery fee based on distance tiers (0km - 50km boundary).
 */
function airmeat_calculate_tiered_rate( $distance_km ) {
    
    // Tier 1: 0 - 5 km (Hyper-Local)
    if ( $distance_km <= 5 ) {
        return 30.00; 

    // Tier 2: 5.1 - 15 km (Inner Ring)
    } elseif ( $distance_km > 5 && $distance_km <= 15 ) {
        return 55.00; 

    // Tier 3: 15.1 - 25 km (Mid-Range)
    } elseif ( $distance_km > 15 && $distance_km <= 25 ) {
        return 75.00; 

    // Tier 4: 25.1 - 50 km (Outer Boundary)
    } elseif ( $distance_km > 25 && $distance_km <= 50 ) {
        return 95.00; 
    }
    
    // Fallback: This should only be hit if distance > 50km,
    // as the main function should have already removed the rate.
    return 0.00; 
}


/**
 * Helper: Converts a street address string to Lat/Lng coordinates using Geocoding API.
 */
function airmeat_geocode_address( $address, $api_key ) {
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode( $address ) . '&key=' . $api_key;
    $response = wp_remote_get( $url );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'geocode_error', 'Geocoding API request failed.' );
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $data['status'] !== 'OK' || empty( $data['results'] ) ) {
        return new WP_Error( 'geocode_fail', 'Could not find coordinates for address.' );
    }

    $location = $data['results'][0]['geometry']['location'];
    return array( 'lat' => $location['lat'], 'lng' => $location['lng'] );
}

/**
 * Helper: Calls the Google Maps Distance Matrix API to get accurate road distance (in km).
 */
function airmeat_get_google_distance( $lat1, $lon1, $lat2, $lon2, $api_key ) {
    $origins = $lat1 . ',' . $lon1;
    $destinations = $lat2 . ',' . $lon2;
    
    // Requesting distance in Kilometers (units=metric) and using the Routes API (travel mode driving)
    $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?units=metric&origins=' . $origins . '&destinations=' . $destinations . '&key=' . $api_key;
    $response = wp_remote_get( $url );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'distance_error', 'Distance API request failed.' );
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $data['status'] === 'OK' && isset( $data['rows'][0]['elements'][0]['distance']['value'] ) ) {
        // Value is returned in meters; convert to kilometers
        $distance_meters = $data['rows'][0]['elements'][0]['distance']['value'];
        return $distance_meters / 1000;
    }

    return new WP_Error( 'distance_fail', 'Could not retrieve distance from API.' );
}

?>