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
			'products-stock-manager-excel',
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

		// Load libs
		require_once plugin_dir_path( WPFACTORY_WC_SM_FILE ) . 'vendor/autoload.php';

		// Action links
		add_filter(
			'plugin_action_links_' . plugin_basename( WPFACTORY_WC_SM_FILE ),
			array( $this, 'action_links' )
		);

		// "Recommendations" page
		add_action( 'init', array( $this, 'add_cross_selling_library' ) );

		// Settings
		add_filter( 'admin_menu', array( $this, 'add_settings' ), 11 );

	}

	/**
	 * action_links.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 *
	 * @param   mixed $links
	 * @return  array
	 */
	function action_links( $links ) {
		$custom_links = array();

		$custom_links[] = '<a href="' . admin_url( 'admin.php?page=stock-manager-woocommerce' ) . '">' .
			__( 'Settings', 'products-stock-manager-excel' ) .
		'</a>';

		return array_merge( $custom_links, $links );
	}

	/**
	 * add_cross_selling_library.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 */
	function add_cross_selling_library() {

		if ( ! class_exists( '\WPFactory\WPFactory_Cross_Selling\WPFactory_Cross_Selling' ) ) {
			return;
		}

		$cross_selling = new \WPFactory\WPFactory_Cross_Selling\WPFactory_Cross_Selling();
		$cross_selling->setup( array( 'plugin_file_path' => WPFACTORY_WC_SM_FILE ) );
		$cross_selling->init();

	}

	/**
	 * add_settings.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 */
	function add_settings() {

		if ( ! class_exists( 'WPFactory\WPFactory_Admin_Menu\WPFactory_Admin_Menu' ) ) {
			return;
		}

		$admin_menu = WPFactory\WPFactory_Admin_Menu\WPFactory_Admin_Menu::get_instance();

		add_submenu_page(
			$admin_menu->get_menu_slug(),
			__( 'Stock Manager', 'products-stock-manager-excel' ),
			__( 'Stock Manager', 'products-stock-manager-excel' ),
			'manage_woocommerce',
			'stock-manager-woocommerce',
			array( $this, 'output_settings' )
		);

	}

	/**
	 * output_settings.
	 *
	 * @version 3.0.0
	 * @since   3.0.0
	 */
	function output_settings() {
		do_action( 'wpfactory_wc_sm_output_settings' );
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
