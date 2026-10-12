<?php
/**
 * Plugin Name: Azula Stock History
 * Description: Audit-only WooCommerce stock history for simple products and variations. Never modifies stock.
 * Version: 1.4.2
 * Author: Azula Sportswear
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AZSH_VERSION', '1.4.2' );
define( 'AZSH_FILE', __FILE__ );
define( 'AZSH_DIR', plugin_dir_path( __FILE__ ) );

require_once AZSH_DIR . 'includes/class-azsh-db.php';
require_once AZSH_DIR . 'includes/class-azsh-context.php';
require_once AZSH_DIR . 'includes/class-azsh-logger.php';
require_once AZSH_DIR . 'includes/class-azsh-admin.php';

register_activation_hook( __FILE__, array( 'AZSH_DB', 'activate' ) );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) { return; }
    AZSH_Logger::init();
    if ( is_admin() ) { AZSH_Admin::init(); }
}, 20 );