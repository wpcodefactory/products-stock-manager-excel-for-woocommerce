<?php
/*
 * Plugin Name: Stock Manager with Excel for WooCommerce
 * Description: Update stock and prices from an Excel or CSV file. See every change before it is saved, undo any update, export products with variations, and see what is running low.
 * Version: 3.0.4
 * Author: WPFactory
 * Author URI: https://wpfactory.com
 * WC requires at least: 7.0
 * WC tested up to: 10.7
 * Requires at least: 6.2
 * Requires Plugins: woocommerce
 * Requires PHP: 8.1
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Created On: 07-07-2020
 * Text Domain: products-stock-manager-with-excel
 * Domain Path: /langs
 */

defined( 'ABSPATH' ) || exit;

defined( 'WPFACTORY_WC_SM_VERSION' ) || define( 'WPFACTORY_WC_SM_VERSION', '3.0.4' );

defined( 'WPFACTORY_WC_SM_FILE' ) || define( 'WPFACTORY_WC_SM_FILE', __FILE__ );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpfactory-wc-sm.php';

if ( ! function_exists( 'wpfactory_wc_sm' ) ) {
	/**
	 * Returns the main instance of WPFactory_WC_SM to prevent the need to use globals.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 */
	function wpfactory_wc_sm() {
		return WPFactory_WC_SM::instance();
	}
}

add_action( 'plugins_loaded', 'wpfactory_wc_sm' );

if ( ! class_exists( 'StockManagerWooCommerceInit' ) ) {
	/**
	 * Kept so code that checks for the 3.0.x class names keeps working. The screens live in WPF_SM_Admin.
	 */
	class StockManagerWooCommerceInit {
		/**
		 * Option prefix of 3.0.x.
		 *
		 * @var string
		 */
		public $plugin = 'stockManagerWooCommerce';
	}
}

if ( ! class_exists( 'StockManagerWooCommerce' ) ) {
	/**
	 * Kept for 3.0.x compatibility.
	 */
	class StockManagerWooCommerce extends StockManagerWooCommerceInit {
		/**
		 * Name.
		 *
		 * @var string
		 */
		public $name = 'Products Stock Manager with Excel for WooCommerce';
		/**
		 * Screen slug.
		 *
		 * @var string
		 */
		public $slug = 'stock-manager-woocommerce';
	}
}
