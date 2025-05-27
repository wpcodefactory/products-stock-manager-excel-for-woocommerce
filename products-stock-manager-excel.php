<?php
/*
 * Plugin Name: Products Stock Manager with Excel for WooCommerce Inventory
 * Description: Update your WooCommerce Products Stock and Prices with the power of Excel, get stock reports - go pro & automate.
 * Version: 3.0.0-dev
 * Author: WPFactory
 * Author URI: https://wpfactory.com
 * WC requires at least: 2.2
 * WC tested up to: 9.8
 * Requires Plugins: woocommerce
 * Requires PHP: 8.1
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Created On: 07-07-2020
 * Updated On: 27-05-2025
 * Text Domain: products-stock-manager-excel
 * Domain Path: /langs
 */

defined( 'ABSPATH' ) || exit;

defined( 'WPFACTORY_WC_SM_VERSION' ) || define( 'WPFACTORY_WC_SM_VERSION', '3.0.0-dev-20250527-1101' );

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

/**
 * class-main.php.
 */
require_once plugin_dir_path( __FILE__ ) . '/class-main.php';

/**
 * StockManagerWooCommerce class.
 *
 * @version 3.0.0
 */
class StockManagerWooCommerce extends StockManagerWooCommerceInit {

	public $plugin       = 'stockManagerWooCommerce';
	public $name         = 'Products Stock Manager with Excel for WooCommerce';
	public $shortName    = 'Stock Manager';
	public $slug         = 'stock-manager-woocommerce';
	public $dashicon     = 'dashicons-cart';
	public $proUrl       = 'https://extend-wp.com/product/products-stock-manager-excel-woocommerce';
	public $menuPosition = '50';
	public $description  = 'Update your WooCommerce Products Stock and Prices with the power of Excel, get stock reports - go pro & automate';

	/**
	 * Constructor.
	 *
	 * @version 3.0.0
	 */
	public function __construct() {

		add_action( 'admin_init', array( $this, 'adminPanels' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'BackEndScripts' ) );

		add_action( 'wpfactory_wc_sm_output_settings', array( $this, 'init' ) );

		add_action( 'wp_ajax_nopriv_update_products', array( $this, 'update_products' ) );
		add_action( 'wp_ajax_update_products',        array( $this, 'update_products' ) );

		add_action( 'wp_ajax_smw_exportProducts',        array( $this, 'smw_exportProducts' ) );
		add_action( 'wp_ajax_nopriv_smw_exportProducts', array( $this, 'smw_exportProducts' ) );

		add_action( 'wp_ajax_nopriv_extensions', array( $this, 'extensions' ) );
		add_action( 'wp_ajax_extensions',        array( $this, 'extensions' ) );

		add_action( 'admin_footer', array( $this, 'proModal' ) );

		register_activation_hook( __FILE__, array( $this, 'onActivation' ) );

		// Deactivation survey
		include plugin_dir_path( __FILE__ ) . '/lib/codecabin/plugin-deactivation-survey/deactivate-feedback-form.php';
		add_filter(
			'codecabin_deactivate_feedback_form_plugins',
			function ( $plugins ) {
				$plugins[] = (object) array(
					'slug'    => 'products-stock-manager-excel',
					'version' => '2.1',
				);
				return $plugins;
			}
		);

		register_activation_hook( __FILE__, array( $this, 'notification_hook' ) );

		add_action( 'admin_notices', array( $this, 'notification' ) );
		add_action( 'wp_ajax_nopriv_push_not', array( $this, 'push_not' ) );
		add_action( 'wp_ajax_push_not',        array( $this, 'push_not' ) );
	}

	/**
	 * onActivation.
	 */
	public function onActivation() {
		require_once ABSPATH . '/wp-admin/includes/plugin.php';
		$pro = '/stock-manager-woocommerce-pro/stock-manager-woocommerce-pro.php';
		deactivate_plugins( $pro );
	}

	/**
	 * BackEndScripts.
	 *
	 * @version 3.0.0
	 */
	public function BackEndScripts( $hook ) {

		$screen = get_current_screen();
		if ( 'wpfactory_page_stock-manager-woocommerce' !== $screen->base ) {
			return;
		}

		wp_enqueue_style( esc_html( $this->plugin ) . 'adminCss', plugins_url( '/css/backend.css?v=adj', __FILE__ ) );
		wp_enqueue_style( esc_html( $this->plugin ) . 'adminCss' );

		wp_enqueue_script( 'jquery' );
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'jquery-ui-tabs' );
		wp_enqueue_script( esc_html( $this->plugin ) . 'xlsx', plugins_url( '/js/xlsx.js', __FILE__ ), array( 'jquery' ), null, true );
		wp_enqueue_script( esc_html( $this->plugin ) . 'xlsx' );

		wp_enqueue_script( esc_html( $this->plugin ) . 'filesaver', plugins_url( '/js/filesaver.js', __FILE__ ), array( 'jquery' ), null, true );
		wp_enqueue_script( esc_html( $this->plugin ) . 'filesaver' );

		wp_enqueue_script( esc_html( $this->plugin ) . 'tableexport', plugins_url( '/js/tableexport.js', __FILE__ ), array( 'jquery' ), null, true );
		wp_enqueue_script( esc_html( $this->plugin ) . 'tableexport' );

		if ( ! wp_script_is( esc_html( $this->plugin ) . '_fa', 'enqueued' ) ) {
			wp_enqueue_style( esc_html( $this->plugin ) . '_fa', plugins_url( '/css/font-awesome.min.css', __FILE__ ) );
		}

		wp_enqueue_script( $this->plugin . 'adminJs', plugins_url( '/js/backend.js?v=1fss', __FILE__ ), array( 'jquery', 'wp-color-picker', 'jquery-ui-tabs' ), null, true );

		wp_localize_script( esc_html( $this->plugin ) . 'adminJs', $this->plugin, array(
			'RestRoot'       => esc_url_raw( rest_url() ),
			'plugin_url'     => plugins_url( '', __FILE__ ),
			'siteUrl'        => site_url(),
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'plugin_wrapper' => esc_html( $this->plugin ),
			'exportfile'     => plugins_url( '/js/tableexport.js', __FILE__ ),
		) );
		wp_enqueue_script( esc_html( $this->plugin ) . 'adminJs' );

	}

	/**
	 * init.
	 */
	public function init() {
		print "<div class='" . esc_html( $this->plugin ) . "'>";
			$this->adminHeader();
			$this->adminSettings();
			$this->adminFooter();
		print '</div>';
	}

	/**
	 * proModal.
	 */
	public function proModal() {
		?>
			<div style='display:none' id="<?php print esc_html( $this->plugin ) . 'Modal'; ?>">
				<!-- Modal content -->
				<div class="modal-content">
				<div class='<?php print esc_html( $this->plugin ); ?>clearfix'><span class="close">&times;</span></div>
				<div class='<?php print esc_html( $this->plugin ); ?>clearfix'>
					<div class='<?php print esc_html( $this->plugin ); ?>columns2'>
						<center>
							<img style='width:90%' src='<?php echo esc_url( plugins_url( 'images/' . esc_html( $this->slug ) . '-pro.png', __FILE__ ) ); ?>' style='width:100%' />
						</center>
					</div>

					<div class='<?php print esc_html( $this->plugin ); ?>columns2'>
						<h3><?php esc_html_e( 'Go PRO and get more important features!', 'products-stock-manager-excel' ); ?></h3>
						<p><i class='fa fa-check'></i> <?php esc_html_e( 'Update stock & prices with excel for product variations with Excel', 'products-stock-manager-excel' ); ?></p>
						<p><i class='fa fa-check'></i> <?php esc_html_e( 'Export to Excel extra product fields', 'products-stock-manager-excel' ); ?></p>
						<p><i class='fa fa-check'></i> <?php esc_html_e( 'Automatically update with Cron from remote location', 'products-stock-manager-excel' ); ?></strong></p>
						<p><i class='fa fa-check'></i> <?php esc_html_e( 'Automatically update from Google Spreadsheet', 'products-stock-manager-excel' ); ?></strong></p>
						<p><i class='fa fa-check'></i> <?php esc_html_e( 'Predefine Update Mapping fields in settings', 'products-stock-manager-excel' ); ?></strong></p>
						<p><i class='fa fa-check'></i> <?php esc_html_e( '.. and a lot more!', 'products-stock-manager-excel' ); ?></p>
						<p class='bottomToUp'><center><a target='_blank' class='proUrl' href='<?php print esc_url( $this->proUrl ); ?>'><?php esc_html_e( 'GET IT HERE', 'products-stock-manager-excel' ); ?></a></center></p>
					</div>
				</div>
				</div>
			</div>
			<?php
	}

	/**
	 * notification_hook.
	 *
	 * Email notification form.
	 */
	public function notification_hook() {
		set_transient( 'stock_manager_notification', true );
	}

	/**
	 * notification.
	 */
	public function notification() {
		/* Check transient, if available display notice */
		if ( get_transient( 'stock_manager_notification' ) ) {
			?>
				<div class="updated notice  stock_manager_notification">
				<a href="#" class='dismiss' style='float:right;padding:4px' >close</a>
					<h3>Products Stock Manager | <?php esc_html_e( 'Add your Email below & get ', 'products-stock-manager-excel' ); ?><strong>10%</strong><?php esc_html_e( ' in our pro plugins! ', 'products-stock-manager-excel' ); ?></h3>
					<p><i><?php esc_html_e( 'By adding your email you will be able to use your email as coupon to a future purchase at ', 'products-stock-manager-excel' ); ?><a href='https://extend-wp.com' target='_blank' >extend-wp.com</a></i></p>
					<form method='post' id='stock_manager_signup'>
						<input required type='email' name='woopei_email' />
					<?php submit_button( __( 'Sign up!', 'products-stock-manager-excel' ), 'primary', 'Sign up!' ); ?>
					</form>
				</div>
				<?php

		}
	}

	/**
	 * push_not.
	 */
	public function push_not() {
		delete_transient( 'stock_manager_notification' );
	}

}

$instantiate = new StockManagerWooCommerce();
