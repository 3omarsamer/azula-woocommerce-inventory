<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AZSH_DB {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'azula_stock_history';
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_gmt DATETIME NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_name TEXT NOT NULL,
            sku VARCHAR(191) NOT NULL DEFAULT '',
            old_stock DECIMAL(18,4) NULL,
            new_stock DECIMAL(18,4) NULL,
            stock_delta DECIMAL(18,4) NULL,
            source VARCHAR(191) NOT NULL DEFAULT 'Unknown',
            reference VARCHAR(191) NOT NULL DEFAULT '',
            request_key VARCHAR(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY sku_created (sku, created_gmt),
            KEY product_created (product_id, created_gmt),
            KEY variation_created (variation_id, created_gmt),
            KEY created_gmt (created_gmt),
            KEY source_created (source(80), created_gmt),
            KEY reference (reference)
        ) {$charset};";

        dbDelta( $sql );
        update_option( 'azsh_db_version', AZSH_VERSION, false );
    }

    public static function insert( array $row ) {
        global $wpdb;
        try {
            $ok = $wpdb->insert( self::table(), $row );
            return $ok ? (int) $wpdb->insert_id : 0;
        } catch ( Throwable $e ) {
            return 0;
        }
    }

    public static function annotate( $id, $source, $reference = '' ) {
        global $wpdb;
        try {
            $id = absint( $id );
            if ( ! $id ) { return; }

            $wpdb->update(
                self::table(),
                array(
                    'source'    => (string) $source,
                    'reference' => (string) $reference,
                ),
                array( 'id' => $id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } catch ( Throwable $e ) {
            // Audit enrichment must never affect WooCommerce.
        }
    }
}