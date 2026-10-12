<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AZSH_Logger {
    private static $before = array();
    private static $seen = array();
    private static $inserted = array();

    public static function init() {
        // WooCommerce 11.1.1 installed on Azula exposes dedicated before/after
        // stock hooks around persistence. Capture persisted stock before the write.
        add_action( 'woocommerce_product_before_set_stock', array( __CLASS__, 'capture_before' ), 5 );
        add_action( 'woocommerce_variation_before_set_stock', array( __CLASS__, 'capture_before' ), 5 );

        add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'after_stock' ), 999 );
        add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'after_stock' ), 999 );

        // WooCommerce exposes the exact order, line item, and stock movement after
        // each order-driven reduction/restoration. Use these to enrich the row that
        // the generic stock hook just inserted, rather than creating a duplicate.
        add_action( 'woocommerce_reduce_order_item_stock', array( __CLASS__, 'annotate_order_reduction' ), 999, 3 );
        add_action( 'woocommerce_restore_order_item_stock', array( __CLASS__, 'annotate_order_restoration' ), 999, 4 );
    }

    public static function capture_before( $product ) {
        try {
            if ( ! is_a( $product, 'WC_Product' ) || ! $product->get_id() ) { return; }

            $id = (int) $product->get_id();

            // Read the currently persisted value directly. Do not modify the object.
            $old = get_post_meta( $id, '_stock', true );
            self::$before[ $id ] = ( '' === $old ? null : (float) $old );
        } catch ( Throwable $e ) {
            // Never interrupt the stock operation.
        }
    }

    public static function after_stock( $product ) {
        try {
            if ( ! is_a( $product, 'WC_Product' ) || ! $product->get_id() ) { return; }

            $id  = (int) $product->get_id();
            $new = $product->get_stock_quantity( 'edit' );
            $new = is_null( $new ) ? null : (float) $new;
            $old = array_key_exists( $id, self::$before ) ? self::$before[ $id ] : null;

            unset( self::$before[ $id ] );

            // Never invent a previous value.
            if ( is_null( $old ) || is_null( $new ) || (float) $old === (float) $new ) {
                return;
            }

            $context      = AZSH_Context::detect();
            $variation_id = $product->is_type( 'variation' ) ? $id : 0;
            $product_id   = $variation_id ? (int) $product->get_parent_id() : $id;

            $key = implode(
                '|',
                array(
                    $id,
                    (string) $old,
                    (string) $new,
                    $context['source'],
                    $context['reference'],
                )
            );

            if ( isset( self::$seen[ $key ] ) ) {
                return;
            }
            self::$seen[ $key ] = true;

            $row_id = AZSH_DB::insert(
                array(
                    'created_gmt'  => current_time( 'mysql', true ),
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'product_name' => wp_strip_all_tags( $product->get_name() ),
                    'sku'          => (string) $product->get_sku( 'edit' ),
                    'old_stock'    => $old,
                    'new_stock'    => $new,
                    'stock_delta'  => $new - $old,
                    'source'       => $context['source'],
                    'reference'    => $context['reference'],
                    'request_key'  => substr( hash( 'sha256', $key . '|' . microtime( true ) ), 0, 40 ),
                )
            );

            if ( $row_id ) {
                self::$inserted[ $id ][] = array(
                    'row_id' => $row_id,
                    'old'    => (float) $old,
                    'new'    => (float) $new,
                    'used'   => false,
                );
            }
        } catch ( Throwable $e ) {
            // Logging failure must never break WooCommerce stock handling.
        }
    }

    public static function annotate_order_reduction( $item, $change, $order ) {
        try {
            if ( ! is_a( $order, 'WC_Order' ) || ! is_array( $change ) || empty( $change['product'] ) ) { return; }

            $product = $change['product'];
            if ( ! is_a( $product, 'WC_Product' ) ) { return; }

            self::annotate_matching_row(
                (int) $product->get_id(),
                isset( $change['from'] ) ? (float) $change['from'] : null,
                isset( $change['to'] ) ? (float) $change['to'] : null,
                'Woo Order #' . $order->get_order_number(),
                'Order #' . $order->get_order_number()
            );
        } catch ( Throwable $e ) {}
    }

    public static function annotate_order_restoration( $item, $new_stock, $old_stock, $order ) {
        try {
            if ( ! is_a( $order, 'WC_Order' ) || ! is_a( $item, 'WC_Order_Item_Product' ) ) { return; }

            $product = $item->get_product();
            if ( ! is_a( $product, 'WC_Product' ) ) { return; }

            self::annotate_matching_row(
                (int) $product->get_id(),
                (float) $old_stock,
                (float) $new_stock,
                'Order restoration #' . $order->get_order_number(),
                'Order #' . $order->get_order_number()
            );
        } catch ( Throwable $e ) {}
    }

    private static function annotate_matching_row( $product_object_id, $old, $new, $source, $reference ) {
        if ( is_null( $old ) || is_null( $new ) || empty( self::$inserted[ $product_object_id ] ) ) {
            return;
        }

        // Walk newest-first in case the same SKU changes more than once in one request.
        for ( $i = count( self::$inserted[ $product_object_id ] ) - 1; $i >= 0; $i-- ) {
            $candidate = &self::$inserted[ $product_object_id ][ $i ];

            if (
                empty( $candidate['used'] ) &&
                (float) $candidate['old'] === (float) $old &&
                (float) $candidate['new'] === (float) $new
            ) {
                AZSH_DB::annotate( $candidate['row_id'], $source, $reference );
                $candidate['used'] = true;
                return;
            }
        }
    }
}