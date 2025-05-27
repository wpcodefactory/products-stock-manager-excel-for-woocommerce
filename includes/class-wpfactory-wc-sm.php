<?php
/**
 * Products Stock Manager with Excel for WooCommerce Inventory - Main Class
 *
 * @version 3.0.0
 * @since   3.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFactory_WC_SM' ) ) :

final class WPFactory_WC_SM {

	/**
	 * Plugin version.
	 *
	 * @var   string
	 * @since 3.0.0
	 */
	public $version = WPFACTORY_WC_SM_VERSION;

	/**
	 * @var   WPFactory_WC_SM The single instance of the class
	 * @since 3.0.0
	 */
	protected static $_instance = null;

	/**
	 * Main WPFactory_WC_SM Instance.
	 *
	 * Ensures only one instance of WPFactory_WC_SM is loaded or can be loaded.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 *
	 * @static
	 * @return  WPFactory_WC_SM - Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * WPFactory_WC_SM Constructor.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 *
	 * @access  public
	 */
	function __construct() {

		// Check for active WooCommerce plugin
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// Set up localisation
		add_action( 'init', array( $this, 'localize' ) );

		// Declare compatibility with custom order tables for WooCommerce
		add_action( 'before_woocommerce_init', array( $this, 'wc_declare_compatibility' ) );

		// Admin
		if ( is_admin() ) {
			$this->admin();
		}

	}

	/**
	 * localize.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 *
	 * @todo    (v3.0.0) text domain should be `products-stock-manager-with-excel`
	 */
	function localize() {
		load_plugin_textdomain(
			'stockManagerWooCommerce',
			false,
			dirname( plugin_basename( WPFACTORY_WC_SM_FILE ) ) . '/langs/'
		);
	}

	/**
	 * wc_declare_compatibility.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 *
	 * @see     https://developer.woocommerce.com/docs/hpos-extension-recipe-book/
	 */
	function wc_declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WPFACTORY_WC_SM_FILE,
				true
			);
		}
	}

	/**
	 * admin.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 */
	function admin() {
		return true;
	}

	/**
	 * plugin_url.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 *
	 * @return  string
	 */
	function plugin_url() {
		return untrailingslashit( plugin_dir_url( WPFACTORY_WC_SM_FILE ) );
	}

	/**
	 * plugin_path.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 *
	 * @return  string
	 */
	function plugin_path() {
		return untrailingslashit( plugin_dir_path( WPFACTORY_WC_SM_FILE ) );
	}

}

endif;
