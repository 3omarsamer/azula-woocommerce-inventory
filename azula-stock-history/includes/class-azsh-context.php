<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AZSH_Context {
    public static function detect() {
        try {
            // REST requests: attribute Khazenly only when the authenticated WP user
            // belongs to a WooCommerce API key explicitly described as Khazenly.
            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                $user_id = get_current_user_id();

                if ( $user_id && self::user_has_khazenly_api_key( $user_id ) ) {
                    return array(
                        'source'    => 'Khazenly API',
                        'reference' => '',
                    );
                }

                return array(
                    'source'    => 'WooCommerce REST API',
                    'reference' => $user_id ? 'WP user #' . $user_id : '',
                );
            }

            // A logged-in WooCommerce manager changing stock in wp-admin, including
            // admin AJAX variation saves, is a reliable manual/admin attribution.
            $user_id = get_current_user_id();
            if ( $user_id && current_user_can( 'manage_woocommerce' ) && ( is_admin() || wp_doing_ajax() ) ) {
                $user = wp_get_current_user();
                $name = $user && $user->exists() ? $user->display_name : ( 'User #' . $user_id );

                return array(
                    'source'    => 'Admin: ' . $name,
                    'reference' => 'WP user #' . $user_id,
                );
            }
        } catch ( Throwable $e ) {
            // Fall through to Unknown. Attribution must never affect stock handling.
        }

        return array( 'source' => 'Unknown', 'reference' => '' );
    }

    private static function user_has_khazenly_api_key( $user_id ) {
        static $cache = array();

        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        if ( array_key_exists( $user_id, $cache ) ) {
            return $cache[ $user_id ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_api_keys';

        // Do not read/store consumer keys or secrets. We only check the key description
        // and associated authenticated WordPress user.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND LOWER(description) LIKE %s",
                $user_id,
                '%khazenly%'
            )
        );

        $cache[ $user_id ] = $count > 0;
        return $cache[ $user_id ];
    }
}