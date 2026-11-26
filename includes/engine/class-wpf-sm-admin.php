<?php
/**
 * Stock Manager screens: Update stock, Backups, Export, Low stock, Settings.
 *
 * Shared by free and Pro. Pro adds tabs, columns and settings through the hooks documented in `hooks.md`.
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPF_SM_Admin' ) ) :

	/**
	 * Admin.
	 */
	class WPF_SM_Admin {

		/**
		 * Settings.
		 *
		 * @var array page, edition, file, version, output_action.
		 */
		public $args;

		/**
		 * Instance.
		 *
		 * @var WPF_SM_Admin|null
		 */
		public static $instance = null;

		/**
		 * Constructor.
		 *
		 * @param array $args Page slug, edition, main file, version, output action.
		 */
		public function __construct( $args ) {
			self::$instance = $this;
			$this->args     = $args;
			add_action( 'init', array( 'WPF_SM_Runs', 'register' ) );
			add_action( 'wpf_sm_daily', array( $this, 'daily' ) );
			add_action( 'init', array( $this, 'schedule' ) );
			if ( ! is_admin() && ! wp_doing_ajax() ) {
				return;
			}
			add_action( $args['output_action'], array( $this, 'render' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
			add_action( 'current_screen', array( $this, 'quiet_notices' ) );
			add_filter(
				'admin_body_class',
				function ( $c ) {
					return $this->is_screen() ? $c . ' wpf-sm-screen' : $c;
				}
			);
			foreach ( array( 'upload', 'columns', 'confirm', 'cancel', 'continue', 'restore', 'report', 'settings', 'export', 'export_download', 'lowstock_download' ) as $a ) {
				add_action( 'admin_post_wpf_sm_' . $a, array( $this, 'handle_' . $a ) );
			}
			add_action( 'wp_ajax_wpf_sm_step', array( $this, 'ajax_step' ) );
			add_action( 'wp_ajax_wpf_sm_export_start', array( $this, 'ajax_export_start' ) );
			add_action( 'wp_ajax_wpf_sm_export_step', array( $this, 'ajax_export_step' ) );
			// Old action names (3.0.4 / 4.0.3 screens) stay answered, for logged-in staff only.
			add_action( 'wp_ajax_update_products', array( $this, 'ajax_legacy_upload' ) );
			add_action( 'wp_ajax_smw_exportProducts', array( $this, 'ajax_legacy_export' ) );
			add_action( 'wp_ajax_smwpro_exportProducts', array( $this, 'ajax_legacy_export' ) );
		}

		/**
		 * Who can use the screens.
		 *
		 * @return string
		 */
		public static function cap() {
			return apply_filters( 'wpfactory_wc_sm_capability', 'manage_woocommerce' );
		}

		/**
		 * Daily clean up event.
		 */
		public function schedule() {
			if ( ! wp_next_scheduled( 'wpf_sm_daily' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpf_sm_daily' );
			}
		}

		/**
		 * Daily: old backups and temporary files.
		 */
		public function daily() {
			WPF_SM_Runs::purge();
			WPF_SM_Xlsx::cleanup();
		}

		/**
		 * Screen URL.
		 *
		 * @param array $args Query args.
		 * @return string
		 */
		public function url( $args = array() ) {
			return add_query_arg( array_merge( array( 'page' => $this->args['page'] ), $args ), admin_url( 'admin.php' ) );
		}

		/**
		 * admin-post URL with a nonce (raw: escape it when printing).
		 *
		 * @param string $action Action without prefix.
		 * @param array  $args   Args.
		 * @return string
		 */
		public function post_url( $action, $args = array() ) {
			return add_query_arg( array_merge( array( 'action' => 'wpf_sm_' . $action ), $args, array( '_wpnonce' => wp_create_nonce( 'wpf_sm_' . $action ) ) ), admin_url( 'admin-post.php' ) );
		}

		/**
		 * Whether this is our screen.
		 *
		 * @return bool
		 */
		protected function is_screen() {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			return $screen && false !== strpos( $screen->id, $this->args['page'] );
		}

		/**
		 * Our notices only on our screen.
		 */
		public function quiet_notices() {
			if ( $this->is_screen() ) {
				remove_all_actions( 'admin_notices' );
				remove_all_actions( 'all_admin_notices' );
			}
		}

		/**
		 * Styles and script, on our screen only.
		 */
		public function assets() {
			if ( ! $this->is_screen() ) {
				return;
			}
			$base = plugin_dir_url( $this->args['file'] ) . 'assets/';
			$dir  = plugin_dir_path( $this->args['file'] ) . 'assets/';
			$ver  = function ( $f ) use ( $dir ) {
				return $this->args['version'] . '.' . ( file_exists( $dir . $f ) ? filemtime( $dir . $f ) : 0 );
			};
			wp_enqueue_style( 'wpf-sm-admin', $base . 'admin.css', array(), $ver( 'admin.css' ) );
			wp_enqueue_script( 'wpf-sm-admin', $base . 'admin.js', array(), $ver( 'admin.js' ), true );
			wp_localize_script(
				'wpf-sm-admin',
				'wpfSm',
				array(
					'ajax'   => admin_url( 'admin-ajax.php' ),
					'nonces' => array(
						'step'   => wp_create_nonce( 'wpf_sm_step' ),
						'export' => wp_create_nonce( 'wpf_sm_export' ),
					),
					'text'   => array(
						'failed'  => __( 'Something went wrong. Reload the page to continue where it stopped.', 'products-stock-manager-with-excel' ),
						'rows'    => __( 'rows', 'products-stock-manager-with-excel' ),
						'working' => __( 'Working...', 'products-stock-manager-with-excel' ),
						'ready'   => __( 'Your file is ready. The download starts now.', 'products-stock-manager-with-excel' ),
					),
				)
			);
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* Layout                                                                                                  */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Tabs: key => label. Keys `import`, `export`, `report`, `settings` are the old ones.
		 *
		 * @return array
		 */
		public function tabs() {
			return apply_filters(
				'wpfactory_wc_sm_tabs',
				array(
					'import'   => __( 'Update stock', 'products-stock-manager-with-excel' ),
					'backups'  => __( 'Backups', 'products-stock-manager-with-excel' ),
					'export'   => __( 'Export', 'products-stock-manager-with-excel' ),
					'report'   => __( 'Low stock', 'products-stock-manager-with-excel' ),
					'settings' => __( 'Settings', 'products-stock-manager-with-excel' ),
				)
			);
		}

		/**
		 * Current tab.
		 *
		 * @return string
		 */
		protected function tab() {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return isset( $this->tabs()[ $tab ] ) ? $tab : 'import';
		}

		/**
		 * The screen.
		 */
		public function render() {
			if ( ! current_user_can( self::cap() ) ) {
				wp_die( esc_html__( 'You cannot use this screen.', 'products-stock-manager-with-excel' ) );
			}
			$tab = $this->tab();
			echo '<div class="wrap wpf-sm">';
			echo '<header class="wpf-sm-top">';
			echo '<div class="wpf-sm-brand"><img class="brand-mark" src="' . esc_url( plugin_dir_url( $this->args['file'] ) . 'assets/icon.svg' ) . '" width="32" height="32" alt="">';
			echo '<div><strong>' . esc_html__( 'Stock Manager', 'products-stock-manager-with-excel' ) . '</strong><span>' . esc_html( 'pro' === $this->args['edition'] ? __( 'Pro', 'products-stock-manager-with-excel' ) : __( 'for WooCommerce', 'products-stock-manager-with-excel' ) ) . '</span></div></div>';
			do_action( 'wpfactory_wc_sm_header_right' );
			echo '</header>';
			echo '<nav class="wpf-sm-tabs" aria-label="' . esc_attr__( 'Stock Manager sections', 'products-stock-manager-with-excel' ) . '">';
			foreach ( $this->tabs() as $key => $label ) {
				printf( '<a href="%s" class="%s"%s>%s</a>', esc_url( $this->url( array( 'tab' => $key ) ) ), $key === $tab ? 'on' : '', $key === $tab ? ' aria-current="page"' : '', esc_html( $label ) );
			}
			echo '</nav><hr class="wp-header-end">';
			$this->notice();
			echo '<main class="wpf-sm-main">';
			switch ( $tab ) {
				case 'backups':
					$this->view_backups();
					break;
				case 'export':
					$this->view_export();
					break;
				case 'report':
					$this->view_lowstock();
					break;
				case 'settings':
					$this->view_settings();
					break;
				case 'import':
					$this->view_import();
					break;
				default:
					do_action( 'wpfactory_wc_sm_tab_' . $tab, $this );
			}
			echo '</main></div>';
		}

		/**
		 * Flash notice for the user.
		 *
		 * @param string $type ok, warn, bad.
		 * @param string $text Text.
		 */
		public static function flash( $type, $text ) {
			set_transient( 'wpf_sm_notice_' . get_current_user_id(), array( $type, $text ), 120 );
		}

		/**
		 * Print the flash notice.
		 */
		protected function notice() {
			$key = 'wpf_sm_notice_' . get_current_user_id();
			$n   = get_transient( $key );
			if ( $n ) {
				delete_transient( $key );
				$this->banner( $n[0], $n[1] );
			}
		}

		/**
		 * One-line banner.
		 *
		 * @param string $type ok, warn, bad, info.
		 * @param string $html Text (escaped by caller or plain).
		 */
		public function banner( $type, $html ) {
			echo '<div class="wpf-sm-banner ' . esc_attr( $type ) . '" role="' . ( 'bad' === $type ? 'alert' : 'status' ) . '">' . wp_kses_post( $html ) . '</div>';
		}

		/**
		 * Page head.
		 *
		 * @param string $title Title.
		 * @param string $lead  Lead.
		 */
		public function head( $title, $lead = '' ) {
			echo '<div class="wpf-sm-head"><h1>' . esc_html( $title ) . '</h1>';
			if ( $lead ) {
				echo '<p>' . wp_kses_post( $lead ) . '</p>';
			}
			echo '</div>';
		}

		/**
		 * Redirect back with a notice.
		 *
		 * @param string $url  URL.
		 * @param string $type Notice type.
		 * @param string $text Text.
		 */
		protected function back( $url, $type = '', $text = '' ) {
			if ( $text ) {
				self::flash( $type, $text );
			}
			wp_safe_redirect( $url );
			exit;
		}

		/**
		 * Nonce and capability for admin-post handlers.
		 *
		 * @param string $action Action.
		 */
		protected function guard( $action ) {
			if ( ! current_user_can( self::cap() ) ) {
				wp_die( esc_html__( 'You cannot do this.', 'products-stock-manager-with-excel' ), 403 );
			}
			check_admin_referer( 'wpf_sm_' . $action );
		}

		/**
		 * Run id from the request.
		 *
		 * @return int
		 */
		protected function req_run() {
			return isset( $_REQUEST['run'] ) ? absint( $_REQUEST['run'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* Words                                                                                                   */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Field label.
		 *
		 * @param string $key Field.
		 * @return string
		 */
		public static function label( $key ) {
			$f = WPF_SM_Fields::get( $key );
			if ( $f ) {
				return $f['label'];
			}
			$extra = array(
				'date_on_sale_from' => __( 'Sale starts', 'products-stock-manager-with-excel' ),
				'date_on_sale_to'   => __( 'Sale ends', 'products-stock-manager-with-excel' ),
			);
			return isset( $extra[ $key ] ) ? $extra[ $key ] : $key;
		}

		/**
		 * Value for people.
		 *
		 * @param string $key Field.
		 * @param mixed  $v   Value.
		 * @return string
		 */
		public static function show( $key, $v ) {
			/**
			 * Show a value of a field the free plugin does not know (Pro: backorders, dates, custom fields).
			 *
			 * @param string|null $text Text, or null for the default.
			 * @param string      $key  Field.
			 * @param mixed       $v    Value.
			 */
			$custom = apply_filters( 'wpfactory_wc_sm_show_value', null, $key, $v );
			if ( null !== $custom ) {
				return (string) $custom;
			}
			if ( '' === (string) $v || null === $v ) {
				return __( '(empty)', 'products-stock-manager-with-excel' );
			}
			if ( 'stock_status' === $key ) {
				$o = wc_get_product_stock_status_options();
				return isset( $o[ $v ] ) ? $o[ $v ] : (string) $v;
			}
			if ( 'manage_stock' === $key ) {
				return 'yes' === $v ? __( 'Yes', 'products-stock-manager-with-excel' ) : __( 'No', 'products-stock-manager-with-excel' );
			}
			if ( in_array( $key, array( 'regular_price', 'sale_price' ), true ) && is_numeric( $v ) ) {
				return wc_format_localized_price( wc_format_decimal( $v, wc_get_price_decimals() ) );
			}
			return (string) $v;
		}

		/**
		 * What changes, as text: "Stock: 3 to 10; Regular price: 45 to 50".
		 *
		 * @param array $changes Changes.
		 * @return string
		 */
		public static function changes_text( $changes ) {
			$parts = array();
			foreach ( (array) $changes as $key => $c ) {
				if ( isset( $c[2] ) ) {
					/* translators: 1: field, 2: old value, 3: new value, 4: number added */
					$parts[] = sprintf( __( '%1$s: %2$s to %3$s (%4$s)', 'products-stock-manager-with-excel' ), self::label( $key ), self::show( $key, $c[0] ), self::show( $key, $c[1] ), ( $c[2] >= 0 ? '+' : '' ) . $c[2] );
				} else {
					/* translators: 1: field, 2: old value, 3: new value */
					$parts[] = sprintf( __( '%1$s: %2$s to %3$s', 'products-stock-manager-with-excel' ), self::label( $key ), self::show( $key, $c[0] ), self::show( $key, $c[1] ) );
				}
			}
			return implode( '; ', $parts );
		}

		/**
		 * One note as a sentence.
		 *
		 * @param array $n Code and params.
		 * @return string
		 */
		public static function note_text( $n ) {
			$c = array_shift( $n );
			$p = array_map( 'strval', $n );
			$f = isset( $p[0] ) ? self::label( $p[0] ) : '';
			switch ( $c ) {
				case 'sku_other':
					/* translators: %s: SKU */
					return sprintf( __( 'SKU "%s" belongs to another product; the ID was used.', 'products-stock-manager-with-excel' ), $p[0] );
				case 'id_fallback':
					/* translators: %s: ID */
					return sprintf( __( 'No product with ID %s, so the SKU or GTIN was used.', 'products-stock-manager-with-excel' ), $p[0] );
				case 'not_product':
					/* translators: %s: ID */
					return sprintf( __( 'ID %s is not a product.', 'products-stock-manager-with-excel' ), $p[0] );
				case 'no_id':
					/* translators: %s: ID */
					return sprintf( __( 'No product with ID %s.', 'products-stock-manager-with-excel' ), $p[0] );
				case 'no_sku':
					/* translators: %s: SKU */
					return sprintf( __( 'No product with SKU "%s".', 'products-stock-manager-with-excel' ), $p[0] );
				case 'no_gtin':
					/* translators: %s: GTIN */
					return sprintf( __( 'No product with GTIN "%s".', 'products-stock-manager-with-excel' ), $p[0] );
				case 'no_key':
					return __( 'No ID, SKU or GTIN in this row.', 'products-stock-manager-with-excel' );
				case 'not_number':
					/* translators: 1: field, 2: cell */
					return sprintf( __( '%1$s: "%2$s" is not a number.', 'products-stock-manager-with-excel' ), $f, $p[1] );
				case 'negative':
					/* translators: 1: field, 2: cell */
					return sprintf( __( '%1$s: %2$s is below 0.', 'products-stock-manager-with-excel' ), $f, $p[1] );
				case 'zero_regular':
					return __( 'A regular price of 0 is not saved (Settings can change this).', 'products-stock-manager-with-excel' );
				case 'not_status':
					/* translators: 1: field, 2: cell */
					return sprintf( __( '%1$s: "%2$s" is not a stock status. Use In stock, Out of stock or On backorder.', 'products-stock-manager-with-excel' ), $f, $p[1] );
				case 'not_bool':
					/* translators: 1: field, 2: cell */
					return sprintf( __( '%1$s: "%2$s" is not Yes or No.', 'products-stock-manager-with-excel' ), $f, $p[1] );
				case 'not_date':
					/* translators: 1: field, 2: cell */
					return sprintf( __( '%1$s: "%2$s" is not a date.', 'products-stock-manager-with-excel' ), $f, $p[1] );
				case 'dup_last':
					/* translators: %s: row numbers */
					return sprintf( __( 'Same product in rows %s: the last row is used.', 'products-stock-manager-with-excel' ), $p[0] );
				case 'dup_sum':
					/* translators: %s: row numbers */
					return sprintf( __( 'Same product in rows %s: the stock numbers are added up.', 'products-stock-manager-with-excel' ), $p[0] );
				case 'missing_zero':
					return __( 'Not in the file: stock set to 0.', 'products-stock-manager-with-excel' );
				case 'missing_outofstock':
					return __( 'Not in the file: marked out of stock.', 'products-stock-manager-with-excel' );
				case 'grouped':
					return __( 'Grouped products have no stock or price of their own.', 'products-stock-manager-with-excel' );
				case 'not_for_type':
					$types = wc_get_product_types();
					$t     = isset( $p[1] ) ? ( 'variation' === $p[1] ? __( 'variation', 'products-stock-manager-with-excel' ) : ( isset( $types[ $p[1] ] ) ? $types[ $p[1] ] : $p[1] ) ) : '';
					/* translators: 1: field, 2: product type */
					return sprintf( __( '%1$s cannot be set on this kind of product (%2$s).', 'products-stock-manager-with-excel' ), $f, $t );
				case 'track_on':
					return __( 'Stock tracking will be turned on for this product.', 'products-stock-manager-with-excel' );
				case 'untracked':
					/* translators: %s: stock number */
					return sprintf( __( 'This product does not track stock, so the stock number %s is not saved. Turn on "Track stock" for it, or choose "Turn tracking on" in Settings.', 'products-stock-manager-with-excel' ), $p[0] );
				case 'status_follows':
					return __( 'This product tracks stock, so its stock status follows the stock number. Change the stock instead.', 'products-stock-manager-with-excel' );
				case 'below_zero':
					/* translators: 1: stock now, 2: number added */
					return sprintf( __( 'Stock goes below 0 (%1$s and %2$s).', 'products-stock-manager-with-excel' ), $p[0], $p[1] );
				case 'sale_high':
					/* translators: 1: sale price, 2: regular price */
					return sprintf( __( 'Sale price %1$s is not below the regular price %2$s, so WooCommerce removes the sale price.', 'products-stock-manager-with-excel' ), $p[0], $p[1] );
				case 'price_jump':
					/* translators: 1: field, 2: percent */
					return sprintf( __( '%1$s changes by %2$s%%.', 'products-stock-manager-with-excel' ), $f, $p[1] );
				case 'stock_jump':
					/* translators: 1: old stock, 2: new stock */
					return sprintf( __( 'Stock jumps from %1$s to %2$s.', 'products-stock-manager-with-excel' ), $p[0], $p[1] );
				default:
					return (string) apply_filters( 'wpfactory_wc_sm_note_text', $c, $c, $p );
			}
		}

		/**
		 * Status chip.
		 *
		 * @param string $s Status.
		 * @return string HTML.
		 */
		public static function chip( $s ) {
			$l = array(
				'ready'   => array( 'ok', __( 'Ready', 'products-stock-manager-with-excel' ) ),
				'check'   => array( 'warn', __( 'Check', 'products-stock-manager-with-excel' ) ),
				'skip'    => array( 'bad', __( 'Skipped', 'products-stock-manager-with-excel' ) ),
				'same'    => array( 'mute', __( 'No change', 'products-stock-manager-with-excel' ) ),
				'written' => array( 'ok', __( 'Updated', 'products-stock-manager-with-excel' ) ),
				'failed'  => array( 'bad', __( 'Failed', 'products-stock-manager-with-excel' ) ),
			);
			$x = isset( $l[ $s ] ) ? $l[ $s ] : array( 'mute', $s );
			return '<span class="wpf-sm-chip ' . esc_attr( $x[0] ) . '">' . esc_html( $x[1] ) . '</span>';
		}

		/**
		 * Source label.
		 *
		 * @param array $run Run.
		 * @return string
		 */
		public static function source_label( $run ) {
			switch ( $run['source'] ) {
				case 'restore':
					return __( 'Restore', 'products-stock-manager-with-excel' );
				case 'schedule':
					return __( 'Scheduled', 'products-stock-manager-with-excel' );
				case 'link':
					return __( 'From a link', 'products-stock-manager-with-excel' );
				default:
					return __( 'Uploaded file', 'products-stock-manager-with-excel' );
			}
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* Update stock                                                                                            */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Update tab: start screen or a run.
		 */
		protected function view_import() {
			$id  = $this->req_run();
			$run = $id ? WPF_SM_Runs::get( $id ) : null;
			if ( ! $run ) {
				$this->view_start();
				return;
			}
			if ( 'planned' === $run['state'] && ! empty( $_GET['columns'] ) && 'restore' !== $run['source'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$run = WPF_SM_Runs::update( $run['id'], array( 'state' => 'new' ) );
			}
			switch ( $run['state'] ) {
				case 'new':
					$this->view_columns( $run );
					break;
				case 'planned':
					$this->view_preview( $run );
					break;
				case 'done':
				case 'failed':
					$this->view_results( $run );
					break;
				default:
					$this->view_progress( $run );
			}
		}

		/**
		 * Start: three steps and the drop zone.
		 */
		protected function view_start() {
			$this->head( __( 'Update stock and prices from a spreadsheet', 'products-stock-manager-with-excel' ), __( 'You see every change before it is saved, and you can undo it afterwards.', 'products-stock-manager-with-excel' ) );
			echo '<div class="wpf-sm-grid">';
			echo '<section class="wpf-sm-card"><div class="wpf-sm-card-body">';
			echo '<ol class="wpf-sm-steps">';
			printf( '<li><strong>%s</strong> <a href="%s">%s</a></li>', esc_html__( 'Get your products as a spreadsheet.', 'products-stock-manager-with-excel' ), esc_url( $this->url( array( 'tab' => 'export' ) ) ), esc_html__( 'Download my products', 'products-stock-manager-with-excel' ) );
			printf( '<li><strong>%s</strong> %s</li>', esc_html__( 'Change stock or prices', 'products-stock-manager-with-excel' ), esc_html__( 'in Excel, Google Sheets, Numbers or LibreOffice. Any column order works; columns you do not need can stay.', 'products-stock-manager-with-excel' ) );
			printf( '<li><strong>%s</strong> %s</li>', esc_html__( 'Upload the file here.', 'products-stock-manager-with-excel' ), esc_html__( 'Nothing changes until you confirm the preview.', 'products-stock-manager-with-excel' ) );
			echo '</ol>';
			echo '<form class="wpf-sm-upload" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wpf_sm_upload' );
			echo '<input type="hidden" name="action" value="wpf_sm_upload">';
			echo '<label class="wpf-sm-drop" data-drop><span class="wpf-sm-drop-title">' . esc_html__( 'Choose a file or drop it here', 'products-stock-manager-with-excel' ) . '</span><span class="wpf-sm-drop-help">' . esc_html__( '.xlsx, .xls or .csv', 'products-stock-manager-with-excel' ) . ' &middot; ' . esc_html(
				/* translators: %s: size like 64 MB */
				sprintf( __( 'up to %s', 'products-stock-manager-with-excel' ), size_format( wp_max_upload_size() ) )
			) . '</span>';
			echo '<input type="file" name="file" aria-label="' . esc_attr__( 'Spreadsheet file (.xlsx, .xls or .csv)', 'products-stock-manager-with-excel' ) . '" accept=".xlsx,.xls,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv" required><span class="wpf-sm-drop-file" data-file></span></label>';
			echo '<div class="wpf-sm-actions"><button type="submit" class="wpf-sm-btn primary">' . esc_html__( 'Check the file', 'products-stock-manager-with-excel' ) . '</button></div>';
			echo '</form></div></section>';
			do_action( 'wpfactory_wc_sm_import_side', $this );
			echo '</div>';
			$recent = WPF_SM_Runs::finished( 1, 5 );
			if ( $recent['runs'] ) {
				echo '<section class="wpf-sm-card"><div class="wpf-sm-card-head"><h2>' . esc_html__( 'Recent updates', 'products-stock-manager-with-excel' ) . '</h2><a href="' . esc_url( $this->url( array( 'tab' => 'backups' ) ) ) . '">' . esc_html__( 'All updates and backups', 'products-stock-manager-with-excel' ) . '</a></div>';
				$this->runs_table( $recent['runs'] );
				echo '</section>';
			}
		}

		/**
		 * Columns step.
		 *
		 * @param array $run Run.
		 */
		protected function view_columns( $run ) {
			/* translators: %s: number of rows */
			$this->head( __( 'Check the columns', 'products-stock-manager-with-excel' ), esc_html( $run['title'] ) . ' &middot; ' . esc_html( sprintf( _n( '%s row', '%s rows', (int) $run['lines'], 'products-stock-manager-with-excel' ), number_format_i18n( (int) $run['lines'] ) ) ) );
			$opts   = $run['options'];
			$map    = (array) $run['map'];
			$header = (array) $run['header'];
			$count  = max( count( $header ), count( $map ), 1 );
			if ( ! empty( $opts['legacy'] ) ) {
				$this->banner( 'info', esc_html__( 'We did not find column names we know, so the columns are read in the fixed order of earlier versions: A ID, B SKU, C regular price, D sale price, E stock, F stock status. Row 1 is skipped as a header row. Change any column below.', 'products-stock-manager-with-excel' ) );
			}
			if ( ! WPF_SM_Fields::usable( $map ) ) {
				$this->banner( 'warn', esc_html__( 'Choose at least one column that finds products (ID, SKU or GTIN) and one column to update.', 'products-stock-manager-with-excel' ) );
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpf-sm-card">';
			wp_nonce_field( 'wpf_sm_columns' );
			echo '<input type="hidden" name="action" value="wpf_sm_columns"><input type="hidden" name="run" value="' . (int) $run['id'] . '">';
			echo '<div class="wpf-sm-card-head"><h2>' . esc_html__( 'Columns we found', 'products-stock-manager-with-excel' ) . '</h2>';
			do_action( 'wpfactory_wc_sm_columns_tools', $run, $this );
			echo '</div><div class="wpf-sm-table-wrap"><table class="wpf-sm-table"><thead><tr><th>' . esc_html__( 'Column', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'First rows', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'Use it for', 'products-stock-manager-with-excel' ) . '</th></tr></thead><tbody>';
			$samples = (array) $run['samples'];
			$groups  = array(
				'key'   => __( 'Find the product by', 'products-stock-manager-with-excel' ),
				'write' => __( 'Update', 'products-stock-manager-with-excel' ),
				'info'  => __( 'Show only', 'products-stock-manager-with-excel' ),
			);
			for ( $i = 0; $i < $count; $i++ ) {
				$head = isset( $header[ $i ] ) && '' !== $header[ $i ] ? $header[ $i ] : '';
				$cur  = isset( $map[ $i ] ) ? $map[ $i ] : '';
				echo '<tr><td><span class="wpf-sm-col">' . esc_html( WPF_SM_Fields::letter( $i ) ) . '</span> ' . esc_html( $head ) . '</td><td class="wpf-sm-mute">';
				$vals = array();
				foreach ( $samples as $s ) {
					if ( isset( $s[ $i ] ) && '' !== $s[ $i ] ) {
						$vals[] = mb_strimwidth( (string) $s[ $i ], 0, 30, '...' );
					}
				}
				echo esc_html( implode( ', ', $vals ) );
				echo '</td><td><select name="map[' . (int) $i . ']" aria-label="' . esc_attr( sprintf( /* translators: %s: column letter */ __( 'Use column %s for', 'products-stock-manager-with-excel' ), WPF_SM_Fields::letter( $i ) ) ) . '">';
				echo '<option value="">' . esc_html__( 'Do not use', 'products-stock-manager-with-excel' ) . '</option>';
				foreach ( $groups as $g => $glabel ) {
					echo '<optgroup label="' . esc_attr( $glabel ) . '">';
					foreach ( WPF_SM_Fields::all() as $key => $f ) {
						$fg = 'key' === $f['kind'] ? 'key' : ( 'info' === $f['kind'] ? 'info' : 'write' );
						if ( $fg === $g ) {
							printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $cur, $key, false ), esc_html( $f['label'] ) );
						}
					}
					echo '</optgroup>';
				}
				if ( 0 === strpos( $cur, 'meta:' ) && ! isset( WPF_SM_Fields::all()[ $cur ] ) ) {
					printf( '<option value="%s" selected>%s</option>', esc_attr( $cur ), esc_html( self::label( $cur ) ) );
				}
				echo '</select></td></tr>';
			}
			echo '</tbody></table></div>';
			echo '<div class="wpf-sm-card-body wpf-sm-choices">';
			$this->choice(
				'options[stock_mode]',
				__( 'Stock numbers in the file', 'products-stock-manager-with-excel' ),
				$opts['stock_mode'],
				array(
					'set' => __( 'Replace the stock', 'products-stock-manager-with-excel' ),
					'add' => __( 'Add to the stock (goods received)', 'products-stock-manager-with-excel' ),
				)
			);
			$this->choice(
				'options[duplicates]',
				__( 'Same product in several rows', 'products-stock-manager-with-excel' ),
				$opts['duplicates'],
				array(
					'last' => __( 'Use the last row', 'products-stock-manager-with-excel' ),
					'sum'  => __( 'Add the stock numbers up', 'products-stock-manager-with-excel' ),
				)
			);
			do_action( 'wpfactory_wc_sm_columns_choices', $run, $this );
			echo '</div><div class="wpf-sm-card-foot"><button class="wpf-sm-btn primary" type="submit">' . esc_html__( 'Check the file', 'products-stock-manager-with-excel' ) . '</button>';
			echo '<a class="wpf-sm-btn text" href="' . esc_url( $this->post_url( 'cancel', array( 'run' => $run['id'] ) ) ) . '">' . esc_html__( 'Cancel', 'products-stock-manager-with-excel' ) . '</a></div>';
			echo '</form>';
		}

		/**
		 * Segmented choice.
		 *
		 * @param string $name    Field name.
		 * @param string $label   Label.
		 * @param string $current Current value.
		 * @param array  $options value => label.
		 * @param string $help    Help text.
		 */
		public function choice( $name, $label, $current, $options, $help = '' ) {
			echo '<fieldset class="wpf-sm-row"><legend>' . esc_html( $label ) . ( $help ? '<span>' . esc_html( $help ) . '</span>' : '' ) . '</legend><div class="wpf-sm-seg">';
			foreach ( $options as $v => $l ) {
				printf( '<label><input type="radio" name="%s" value="%s"%s><span>%s</span></label>', esc_attr( $name ), esc_attr( $v ), checked( (string) $current, (string) $v, false ), esc_html( $l ) );
			}
			echo '</div></fieldset>';
		}

		/**
		 * Progress (checking, writing).
		 *
		 * @param array $run Run.
		 */
		protected function view_progress( $run ) {
			$writing = 'writing' === $run['state'];
			$title   = 'restore' === $run['source'] ? __( 'Restoring the backup', 'products-stock-manager-with-excel' ) : ( $writing ? __( 'Updating your products', 'products-stock-manager-with-excel' ) : __( 'Checking the file', 'products-stock-manager-with-excel' ) );
			$this->head( $title, esc_html( $run['title'] ) );
			$pct = WPF_SM_Engine::progress( $run );
			echo '<section class="wpf-sm-card"><div class="wpf-sm-card-body">';
			echo '<div class="wpf-sm-progress" data-run="' . (int) $run['id'] . '" data-next="' . esc_url( $this->url( array( 'tab' => 'import', 'run' => $run['id'] ) ) ) . '">';
			echo '<div class="wpf-sm-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $pct . '"><span style="width:' . (int) $pct . '%"></span></div>';
			echo '<p class="wpf-sm-mute" data-progress-text>' . esc_html( $pct ) . '%</p>';
			echo '<p class="wpf-sm-mute">' . esc_html__( 'Keep this page open until it finishes.', 'products-stock-manager-with-excel' ) . '</p>';
			echo '</div>';
			echo '<noscript><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wpf_sm_continue' );
			echo '<input type="hidden" name="action" value="wpf_sm_continue"><input type="hidden" name="run" value="' . (int) $run['id'] . '"><button class="wpf-sm-btn primary">' . esc_html__( 'Continue', 'products-stock-manager-with-excel' ) . '</button></form></noscript>';
			echo '</div></section>';
		}

		/**
		 * Preview.
		 *
		 * @param array $run Run.
		 */
		protected function view_preview( $run ) {
			$c       = wp_parse_args( $run['counts'], array( 'ready' => 0, 'check' => 0, 'skip' => 0, 'same' => 0 ) );
			$changes = $c['ready'] + $c['check'];
			$this->head( 'restore' === $run['source'] ? __( 'Restore preview', 'products-stock-manager-with-excel' ) : __( 'Preview: nothing is saved yet', 'products-stock-manager-with-excel' ), esc_html( $run['title'] ) );
			$this->run_notes( $run );
			$filter = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : ( $c['check'] ? 'check' : 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->tiles(
				$run,
				array(
					'ready' => array( $c['ready'], __( 'Ready', 'products-stock-manager-with-excel' ), 'ok' ),
					'check' => array( $c['check'], __( 'To check', 'products-stock-manager-with-excel' ), 'warn' ),
					'skip'  => array( $c['skip'], __( 'Skipped', 'products-stock-manager-with-excel' ), 'bad' ),
					'same'  => array( $c['same'], __( 'No change', 'products-stock-manager-with-excel' ), 'mute' ),
				),
				$filter
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpf-sm-card">';
			wp_nonce_field( 'wpf_sm_confirm' );
			echo '<input type="hidden" name="action" value="wpf_sm_confirm"><input type="hidden" name="run" value="' . (int) $run['id'] . '">';
			echo '<div class="wpf-sm-card-head"><h2>' . esc_html( $this->filter_title( $filter ) ) . '</h2>';
			do_action( 'wpfactory_wc_sm_preview_tools', $run, $this );
			echo '</div>';
			$this->entries_table( $run, $filter, false );
			echo '<div class="wpf-sm-card-foot">';
			if ( $changes ) {
				/* translators: %s: number of products */
				echo '<button class="wpf-sm-btn primary" type="submit">' . esc_html( sprintf( _n( 'Update %s product', 'Update %s products', $changes, 'products-stock-manager-with-excel' ), number_format_i18n( $changes ) ) ) . '</button>';
			} else {
				echo '<p class="wpf-sm-mute">' . esc_html__( 'Nothing to update: no row changes a product.', 'products-stock-manager-with-excel' ) . '</p>';
			}
			echo '<a class="wpf-sm-btn secondary" href="' . esc_url( $this->post_url( 'report', array( 'run' => $run['id'] ) ) ) . '">' . esc_html__( 'Download the preview', 'products-stock-manager-with-excel' ) . '</a>';
			if ( 'restore' !== $run['source'] ) {
				echo '<a class="wpf-sm-btn text" href="' . esc_url( $this->url( array( 'tab' => 'import', 'run' => $run['id'], 'columns' => 1 ) ) ) . '" data-columns>' . esc_html__( 'Change columns', 'products-stock-manager-with-excel' ) . '</a>';
			}
			echo '<a class="wpf-sm-btn text" href="' . esc_url( $this->post_url( 'cancel', array( 'run' => $run['id'] ) ) ) . '">' . esc_html__( 'Cancel', 'products-stock-manager-with-excel' ) . '</a>';
			echo '</div></form>';
		}

		/**
		 * Notes about the whole run.
		 *
		 * @param array $run Run.
		 */
		protected function run_notes( $run ) {
			if ( ! empty( $run['options']['legacy'] ) && 'restore' !== $run['source'] ) {
				$this->banner( 'info', esc_html__( 'Columns read in the fixed order of earlier versions (A ID, B SKU, C regular price, D sale price, E stock, F stock status); row 1 skipped.', 'products-stock-manager-with-excel' ) );
			}
			if ( 'add' === $run['options']['stock_mode'] && 'restore' !== $run['source'] ) {
				$this->banner( 'info', esc_html__( 'Stock numbers in the file are added to the current stock.', 'products-stock-manager-with-excel' ) );
			}
			if ( ! empty( $run['missing_note'] ) ) {
				$this->banner(
					'warn',
					'missing_none' === $run['missing_note']
						? esc_html__( 'No row matched a product, so products that are not in the file were left as they are.', 'products-stock-manager-with-excel' )
						: esc_html__( 'More than half of the rows did not match a product, so products that are not in the file were left as they are.', 'products-stock-manager-with-excel' )
				);
			}
			do_action( 'wpfactory_wc_sm_run_notes', $run, $this );
		}

		/**
		 * Counter tiles that filter the table.
		 *
		 * @param array  $run    Run.
		 * @param array  $tiles  key => array( count, label, tone ).
		 * @param string $filter Current filter.
		 */
		protected function tiles( $run, $tiles, $filter ) {
			echo '<div class="wpf-sm-tiles">';
			foreach ( $tiles as $key => $t ) {
				printf(
					'<a class="wpf-sm-tile %s%s" href="%s"><strong>%s</strong><span>%s</span></a>',
					esc_attr( $t[2] ),
					$key === $filter ? ' on' : '',
					esc_url( $this->url( array( 'tab' => 'import', 'run' => $run['id'], 'show' => $key ) ) ),
					esc_html( number_format_i18n( $t[0] ) ),
					esc_html( $t[1] )
				);
			}
			echo '</div>';
		}

		/**
		 * Title for a filter.
		 *
		 * @param string $f Filter.
		 * @return string
		 */
		protected function filter_title( $f ) {
			$t = array(
				'ready'   => __( 'Ready to update', 'products-stock-manager-with-excel' ),
				'check'   => __( 'Please check these rows', 'products-stock-manager-with-excel' ),
				'skip'    => __( 'Skipped rows', 'products-stock-manager-with-excel' ),
				'same'    => __( 'Rows with no change', 'products-stock-manager-with-excel' ),
				'changed' => __( 'Updated products', 'products-stock-manager-with-excel' ),
				'failed'  => __( 'Products that failed', 'products-stock-manager-with-excel' ),
			);
			return isset( $t[ $f ] ) ? $t[ $f ] : __( 'All rows', 'products-stock-manager-with-excel' );
		}

		/**
		 * Table of plan entries (paged).
		 *
		 * @param array  $run     Run.
		 * @param string $filter  Filter.
		 * @param bool   $results Results column.
		 */
		protected function entries_table( $run, $filter, $results ) {
			$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$data = WPF_SM_Engine::entries( $run, $filter, $page, 100 );
			if ( ! $data['entries'] ) {
				echo '<div class="wpf-sm-empty">' . esc_html__( 'No rows here.', 'products-stock-manager-with-excel' ) . '</div>';
				return;
			}
			echo '<div class="wpf-sm-table-wrap"><table class="wpf-sm-table"><thead><tr><th>' . esc_html__( 'Row', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'Product', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'What changes', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'Status', 'products-stock-manager-with-excel' ) . '</th></tr></thead><tbody>';
			foreach ( $data['entries'] as $e ) {
				$chip = $results && $e['result'] ? $e['result'][0] : $e['status'];
				echo '<tr><td class="wpf-sm-mute">' . esc_html( $e['lines'] ? implode( ', ', array_slice( $e['lines'], 0, 4 ) ) : '' ) . '</td><td>';
				if ( $e['pid'] ) {
					$edit = get_edit_post_link( $e['parent'] ? $e['parent'] : $e['pid'] );
					echo '<a href="' . esc_url( (string) $edit ) . '">' . esc_html( $e['name'] ? $e['name'] : '#' . $e['pid'] ) . '</a>';
				} else {
					echo esc_html( $e['name'] );
				}
				echo '<span class="wpf-sm-sub">' . esc_html( $e['sku'] ) . '</span></td><td>';
				echo esc_html( self::changes_text( $e['changes'] ) );
				foreach ( $e['notes'] as $n ) {
					echo '<span class="wpf-sm-note">' . esc_html( self::note_text( $n ) ) . '</span>';
				}
				if ( $results && $e['result'] && 'failed' === $e['result'][0] ) {
					echo '<span class="wpf-sm-note bad">' . esc_html( $e['result'][1] ) . '</span>';
				}
				echo '</td><td>' . self::chip( $chip ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table></div>';
			$pages = (int) ceil( $data['total'] / 100 );
			if ( $pages > 1 ) {
				echo '<nav class="wpf-sm-pages">';
				for ( $i = 1; $i <= $pages; $i++ ) {
					if ( $i > 3 && $i < $pages - 2 && abs( $i - $page ) > 2 ) {
						if ( 4 === $i || $pages - 3 === $i ) {
							echo '<span>...</span>';
						}
						continue;
					}
					printf( '<a class="%s" href="%s">%d</a>', $i === $page ? 'on' : '', esc_url( add_query_arg( 'paged', $i ) ), (int) $i );
				}
				echo '</nav>';
			}
		}

		/**
		 * Results.
		 *
		 * @param array $run Run.
		 */
		protected function view_results( $run ) {
			$written = (int) ( isset( $run['written'] ) ? $run['written'] : 0 );
			$failed  = (int) ( isset( $run['failed'] ) ? $run['failed'] : 0 );
			$c       = wp_parse_args( $run['counts'], array( 'ready' => 0, 'check' => 0, 'skip' => 0, 'same' => 0 ) );
			$this->head( 'restore' === $run['source'] ? __( 'Backup restored', 'products-stock-manager-with-excel' ) : __( 'Update finished', 'products-stock-manager-with-excel' ), esc_html( $run['title'] ) . ' &middot; ' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $run['date'] ) ) );
			if ( 'failed' === $run['state'] ) {
				$this->banner( 'bad', esc_html( isset( $run['error'] ) ? $run['error'] : __( 'This update failed.', 'products-stock-manager-with-excel' ) ) );
			} else {
				/* translators: %s: number */
				$text = sprintf( _n( '%s product updated.', '%s products updated.', $written, 'products-stock-manager-with-excel' ), number_format_i18n( $written ) );
				if ( $c['skip'] ) {
					/* translators: %s: number */
					$text .= ' ' . sprintf( _n( '%s row skipped.', '%s rows skipped.', $c['skip'], 'products-stock-manager-with-excel' ), number_format_i18n( $c['skip'] ) );
				}
				if ( $failed ) {
					/* translators: %s: number */
					$text .= ' ' . sprintf( _n( '%s product failed.', '%s products failed.', $failed, 'products-stock-manager-with-excel' ), number_format_i18n( $failed ) );
				}
				$this->banner( $failed ? 'warn' : 'ok', esc_html( $text ) );
			}
			if ( ! empty( $run['restored_by'] ) ) {
				$this->banner( 'info', esc_html__( 'This update was undone.', 'products-stock-manager-with-excel' ) . ' <a href="' . esc_url( $this->url( array( 'tab' => 'import', 'run' => $run['restored_by'] ) ) ) . '">' . esc_html__( 'See the restore', 'products-stock-manager-with-excel' ) . '</a>' );
			}
			$filter = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'changed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->tiles(
				$run,
				array(
					'changed' => array( $written, __( 'Updated', 'products-stock-manager-with-excel' ), 'ok' ),
					'skip'    => array( $c['skip'], __( 'Skipped', 'products-stock-manager-with-excel' ), 'bad' ),
					'failed'  => array( $failed, __( 'Failed', 'products-stock-manager-with-excel' ), 'bad' ),
					'same'    => array( $c['same'], __( 'No change', 'products-stock-manager-with-excel' ), 'mute' ),
				),
				$filter
			);
			echo '<section class="wpf-sm-card"><div class="wpf-sm-card-head"><h2>' . esc_html( $this->filter_title( $filter ) ) . '</h2></div>';
			$this->entries_table( $run, $filter, true );
			echo '<div class="wpf-sm-card-foot">';
			echo '<a class="wpf-sm-btn primary" href="' . esc_url( $this->url( array( 'tab' => 'import' ) ) ) . '">' . esc_html__( 'Update another file', 'products-stock-manager-with-excel' ) . '</a>';
			echo '<a class="wpf-sm-btn secondary" href="' . esc_url( $this->post_url( 'report', array( 'run' => $run['id'] ) ) ) . '">' . esc_html__( 'Download the report', 'products-stock-manager-with-excel' ) . '</a>';
			$this->restore_button( $run );
			echo '</div></section>';
		}

		/**
		 * "Undo this update" button when the backup is kept.
		 *
		 * @param array $run Run.
		 */
		protected function restore_button( $run ) {
			if ( 'done' !== $run['state'] || ! empty( $run['purged'] ) || ! empty( $run['restored_by'] ) || empty( $run['written'] ) ) {
				return;
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpf-sm-inline">';
			wp_nonce_field( 'wpf_sm_restore' );
			echo '<input type="hidden" name="action" value="wpf_sm_restore"><input type="hidden" name="run" value="' . (int) $run['id'] . '">';
			/* translators: %s: number of products */
			echo '<button class="wpf-sm-btn danger" type="submit" data-confirm="' . esc_attr( sprintf( _n( 'Put %s product back the way it was before this update?', 'Put %s products back the way they were before this update?', (int) $run['written'], 'products-stock-manager-with-excel' ), number_format_i18n( (int) $run['written'] ) ) ) . '">' . esc_html( 'restore' === $run['source'] ? __( 'Undo this restore', 'products-stock-manager-with-excel' ) : __( 'Undo this update', 'products-stock-manager-with-excel' ) ) . '</button></form>';
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* Backups                                                                                                 */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Backups tab.
		 */
		protected function view_backups() {
			$keep = (int) WPF_SM_Engine::site_options_value( 'keep_days', 30 );
			/* translators: %s: number of days */
			$this->head( __( 'Updates and backups', 'products-stock-manager-with-excel' ), esc_html( sprintf( _n( 'Every update saves the old values first. Backups are kept for %s day (Settings).', 'Every update saves the old values first. Backups are kept for %s days (Settings).', $keep, 'products-stock-manager-with-excel' ), number_format_i18n( $keep ) ) ) );
			$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$list = WPF_SM_Runs::finished( $page, 20 );
			echo '<section class="wpf-sm-card">';
			if ( ! $list['runs'] ) {
				echo '<div class="wpf-sm-empty">' . esc_html__( 'No updates yet. Your first update and its backup will show here.', 'products-stock-manager-with-excel' ) . ' <a href="' . esc_url( $this->url( array( 'tab' => 'import' ) ) ) . '">' . esc_html__( 'Update stock', 'products-stock-manager-with-excel' ) . '</a></div>';
			} else {
				$this->runs_table( $list['runs'] );
			}
			$pages = (int) ceil( $list['total'] / 20 );
			if ( $pages > 1 ) {
				echo '<nav class="wpf-sm-pages">';
				for ( $i = 1; $i <= $pages; $i++ ) {
					printf( '<a class="%s" href="%s">%d</a>', $i === $page ? 'on' : '', esc_url( $this->url( array( 'tab' => 'backups', 'paged' => $i ) ) ), (int) $i );
				}
				echo '</nav>';
			}
			echo '</section>';
		}

		/**
		 * Table of runs.
		 *
		 * @param array $runs Runs.
		 */
		protected function runs_table( $runs ) {
			echo '<div class="wpf-sm-table-wrap"><table class="wpf-sm-table"><thead><tr><th>' . esc_html__( 'When', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'File', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'Result', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'Backup', 'products-stock-manager-with-excel' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'products-stock-manager-with-excel' ) . '</span></th></tr></thead><tbody>';
			foreach ( $runs as $run ) {
				$user = get_userdata( $run['user'] );
				echo '<tr><td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $run['date'] ) ) . '<span class="wpf-sm-sub">' . esc_html( $user ? $user->display_name : __( 'Schedule', 'products-stock-manager-with-excel' ) ) . '</span></td>';
				echo '<td><a href="' . esc_url( $this->url( array( 'tab' => 'import', 'run' => $run['id'] ) ) ) . '">' . esc_html( $run['title'] ) . '</a><span class="wpf-sm-sub">' . esc_html( self::source_label( $run ) ) . '</span></td><td>';
				if ( 'failed' === $run['state'] ) {
					echo self::chip( 'failed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					$c = wp_parse_args( $run['counts'], array( 'skip' => 0 ) );
					/* translators: %s: number */
					echo esc_html( sprintf( _n( '%s updated', '%s updated', (int) $run['written'], 'products-stock-manager-with-excel' ), number_format_i18n( (int) $run['written'] ) ) );
					if ( $c['skip'] ) {
						/* translators: %s: number */
						echo '<span class="wpf-sm-sub">' . esc_html( sprintf( _n( '%s skipped', '%s skipped', $c['skip'], 'products-stock-manager-with-excel' ), number_format_i18n( $c['skip'] ) ) ) . '</span>';
					}
				}
				echo '</td><td>';
				if ( ! empty( $run['restored_by'] ) ) {
					echo esc_html__( 'Undone', 'products-stock-manager-with-excel' );
				} elseif ( ! empty( $run['purged'] ) || 'done' !== $run['state'] || empty( $run['written'] ) ) {
					echo '<span class="wpf-sm-mute">' . esc_html__( 'None', 'products-stock-manager-with-excel' ) . '</span>';
				} else {
					/* translators: %s: date */
					echo esc_html( sprintf( __( 'Kept until %s', 'products-stock-manager-with-excel' ), date_i18n( get_option( 'date_format' ), (int) $run['expires'] ) ) );
				}
				echo '</td><td class="wpf-sm-row-actions"><a class="wpf-sm-btn text" href="' . esc_url( $this->post_url( 'report', array( 'run' => $run['id'] ) ) ) . '">' . esc_html__( 'Report', 'products-stock-manager-with-excel' ) . '</a>';
				$this->restore_button( $run );
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* Export                                                                                                  */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Export tab.
		 */
		protected function view_export() {
			$this->head( __( 'Download your products as a spreadsheet', 'products-stock-manager-with-excel' ), __( 'Variations come as their own rows under their product. Low stock rows are amber and out of stock rows are red. Change stock or prices, then upload the file on Update stock.', 'products-stock-manager-with-excel' ) );
			echo '<form class="wpf-sm-card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-export>';
			wp_nonce_field( 'wpf_sm_export' );
			echo '<input type="hidden" name="action" value="wpf_sm_export">';
			echo '<div class="wpf-sm-card-head"><h2>' . esc_html__( 'Which products', 'products-stock-manager-with-excel' ) . '</h2></div><div class="wpf-sm-card-body wpf-sm-fields">';
			echo '<label>' . esc_html__( 'Category', 'products-stock-manager-with-excel' );
			wp_dropdown_categories(
				array(
					'taxonomy'        => 'product_cat',
					'name'            => 'f[category]',
					'show_option_all' => __( 'All categories', 'products-stock-manager-with-excel' ),
					'hierarchical'    => true,
					'hide_empty'      => false,
					'value_field'     => 'term_id',
				)
			);
			echo '</label><label>' . esc_html__( 'Stock', 'products-stock-manager-with-excel' ) . '<select name="f[status]"><option value="">' . esc_html__( 'Any stock', 'products-stock-manager-with-excel' ) . '</option><option value="low">' . esc_html__( 'Low or out of stock', 'products-stock-manager-with-excel' ) . '</option>';
			foreach ( wc_get_product_stock_status_options() as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
			}
			echo '</select></label><label>' . esc_html__( 'Type', 'products-stock-manager-with-excel' ) . '<select name="f[type]"><option value="">' . esc_html__( 'All types', 'products-stock-manager-with-excel' ) . '</option>';
			foreach ( wc_get_product_types() as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
			}
			echo '</select></label><label>' . esc_html__( 'Name or SKU contains', 'products-stock-manager-with-excel' ) . '<input type="search" name="f[search]"></label>';
			echo '</div><div class="wpf-sm-card-head"><h2>' . esc_html__( 'Columns', 'products-stock-manager-with-excel' ) . '</h2></div><div class="wpf-sm-card-body wpf-sm-checks">';
			foreach ( WPF_SM_Export::columns() as $k => $c ) {
				printf( '<label><input type="checkbox" name="cols[]" value="%s"%s> %s</label>', esc_attr( $k ), checked( ! empty( $c[2] ), true, false ), esc_html( $c[0] ) );
			}
			echo '</div><div class="wpf-sm-card-foot"><button class="wpf-sm-btn primary" type="submit">' . esc_html__( 'Download .xlsx', 'products-stock-manager-with-excel' ) . '</button>';
			echo '<div class="wpf-sm-progress small" data-export-progress hidden><div class="wpf-sm-bar"><span style="width:0"></span></div><p class="wpf-sm-mute" data-progress-text></p></div></div></form>';
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* Low stock                                                                                               */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Low stock filters from the request.
		 *
		 * @return array
		 */
		protected function lowstock_args() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$show  = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'low';
			$days  = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : (int) WPF_SM_Engine::site_options_value( 'sales_days', 30 );
			$cover = isset( $_GET['cover'] ) ? absint( $_GET['cover'] ) : (int) WPF_SM_Engine::site_options_value( 'cover_days', 30 );
			// phpcs:enable
			return array(
				'show'  => in_array( $show, array( 'low', 'out', 'all' ), true ) ? $show : 'low',
				'days'  => min( 365, max( 1, $days ) ),
				'cover' => min( 365, max( 1, $cover ) ),
			);
		}

		/**
		 * Low stock tab.
		 */
		protected function view_lowstock() {
			$a      = $this->lowstock_args();
			$global = get_option( 'stockManagerWooCommercelowStockValue', '' );
			$store  = '' !== $global && is_numeric( $global ) ? (int) $global : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
			/* translators: %s: number */
			$this->head( __( 'Low stock', 'products-stock-manager-with-excel' ), esc_html( sprintf( __( 'Products that track stock and are at or under their low stock level (%s for products without their own level). Sales come from WooCommerce orders.', 'products-stock-manager-with-excel' ), number_format_i18n( $store ) ) ) );
			echo '<form class="wpf-sm-filters" method="get"><input type="hidden" name="page" value="' . esc_attr( $this->args['page'] ) . '"><input type="hidden" name="tab" value="report">';
			echo '<label>' . esc_html__( 'Show', 'products-stock-manager-with-excel' ) . '<select name="show">';
			foreach ( array( 'low' => __( 'Low and out of stock', 'products-stock-manager-with-excel' ), 'out' => __( 'Out of stock', 'products-stock-manager-with-excel' ), 'all' => __( 'Everything that tracks stock', 'products-stock-manager-with-excel' ) ) as $k => $l ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $a['show'], $k, false ), esc_html( $l ) );
			}
			echo '</select></label><label>' . esc_html__( 'Sales of the last (days)', 'products-stock-manager-with-excel' ) . '<input type="number" min="1" max="365" name="days" value="' . (int) $a['days'] . '"></label>';
			echo '<label>' . esc_html__( 'Reorder enough for (days)', 'products-stock-manager-with-excel' ) . '<input type="number" min="1" max="365" name="cover" value="' . (int) $a['cover'] . '"></label>';
			echo '<button class="wpf-sm-btn secondary" type="submit">' . esc_html__( 'Show', 'products-stock-manager-with-excel' ) . '</button>';
			echo '<a class="wpf-sm-btn text" href="' . esc_url( $this->post_url( 'lowstock_download', $a ) ) . '">' . esc_html__( 'Download .xlsx', 'products-stock-manager-with-excel' ) . '</a></form>';
			$rows = WPF_SM_Export::low_stock( $a['show'], $a['days'], $a['cover'] );
			do_action( 'wpfactory_wc_sm_lowstock_tools', $rows, $a, $this );
			echo '<section class="wpf-sm-card">';
			if ( ! $rows ) {
				echo '<div class="wpf-sm-empty">' . esc_html__( 'Nothing is low on stock. Products show here when their stock reaches their low stock level.', 'products-stock-manager-with-excel' ) . '</div></section>';
				return;
			}
			$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slice = array_slice( $rows, ( $page - 1 ) * 50, 50 );
			echo '<div class="wpf-sm-table-wrap"><table class="wpf-sm-table"><thead><tr><th>' . esc_html__( 'Product', 'products-stock-manager-with-excel' ) . '</th><th class="num">' . esc_html__( 'Stock', 'products-stock-manager-with-excel' ) . '</th><th class="num">' . esc_html__( 'Low stock level', 'products-stock-manager-with-excel' ) . '</th><th class="num">' . esc_html__( 'Sold', 'products-stock-manager-with-excel' ) . '</th><th class="num">' . esc_html__( 'Days left', 'products-stock-manager-with-excel' ) . '</th><th class="num">' . esc_html__( 'Reorder', 'products-stock-manager-with-excel' ) . '</th></tr></thead><tbody>';
			foreach ( $slice as $r ) {
				echo '<tr class="' . esc_attr( $r['status'] ) . '"><td><a href="' . esc_url( (string) $r['edit'] ) . '">' . esc_html( $r['name'] ) . '</a><span class="wpf-sm-sub">' . esc_html( $r['sku'] ) . '</span></td>';
				echo '<td class="num">' . esc_html( $r['stock'] ) . ( 'out' === $r['status'] ? ' <span class="wpf-sm-chip bad">' . esc_html__( 'Out of stock', 'products-stock-manager-with-excel' ) . '</span>' : '' ) . '</td>';
				echo '<td class="num">' . esc_html( $r['level'] ) . '</td><td class="num">' . esc_html( null === $r['sold'] ? '-' : wc_stock_amount( $r['sold'] ) ) . '</td>';
				echo '<td class="num">' . esc_html( null === $r['left'] ? '-' : number_format_i18n( $r['left'] ) ) . '</td><td class="num"><strong>' . esc_html( null === $r['reorder'] ? '-' : number_format_i18n( $r['reorder'] ) ) . '</strong></td></tr>';
			}
			echo '</tbody></table></div>';
			$pages = (int) ceil( count( $rows ) / 50 );
			if ( $pages > 1 ) {
				echo '<nav class="wpf-sm-pages">';
				for ( $i = 1; $i <= $pages; $i++ ) {
					printf( '<a class="%s" href="%s">%d</a>', $i === $page ? 'on' : '', esc_url( add_query_arg( 'paged', $i ) ), (int) $i );
				}
				echo '</nav>';
			}
			echo '<p class="wpf-sm-foot-note">' . esc_html__( 'Days left = stock divided by daily sales. Reorder = what the sales of the chosen period need for the days you aim for, minus the stock.', 'products-stock-manager-with-excel' ) . '</p>';
			echo '</section>';
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* Settings                                                                                                */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Settings tab.
		 */
		protected function view_settings() {
			$o = WPF_SM_Engine::site_options();
			$this->head( __( 'Settings', 'products-stock-manager-with-excel' ), __( 'Defaults for every update. You can change the stock choices for one file on its column step.', 'products-stock-manager-with-excel' ) );
			echo '<form class="wpf-sm-card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wpf_sm_settings' );
			echo '<input type="hidden" name="action" value="wpf_sm_settings"><div class="wpf-sm-card-body wpf-sm-choices">';
			$this->choice( 's[stock_mode]', __( 'Stock numbers in a file', 'products-stock-manager-with-excel' ), $o['stock_mode'], array( 'set' => __( 'Replace the stock', 'products-stock-manager-with-excel' ), 'add' => __( 'Add to the stock', 'products-stock-manager-with-excel' ) ) );
			$this->choice( 's[duplicates]', __( 'Same product in several rows', 'products-stock-manager-with-excel' ), $o['duplicates'], array( 'last' => __( 'Use the last row', 'products-stock-manager-with-excel' ), 'sum' => __( 'Add the stock numbers up', 'products-stock-manager-with-excel' ) ) );
			$this->choice( 's[untracked]', __( 'A stock number for a product that does not track stock', 'products-stock-manager-with-excel' ), $o['untracked'], array( 'skip' => __( 'Skip the number', 'products-stock-manager-with-excel' ), 'track' => __( 'Turn tracking on', 'products-stock-manager-with-excel' ) ), __( 'WooCommerce ignores stock numbers on products that do not track stock.', 'products-stock-manager-with-excel' ) );
			$this->choice( 's[zero_regular]', __( 'A regular price of 0', 'products-stock-manager-with-excel' ), $o['zero_regular'], array( 'skip' => __( 'Keep the current price', 'products-stock-manager-with-excel' ), 'save' => __( 'Save 0', 'products-stock-manager-with-excel' ) ) );
			$this->number( 's[price_warn]', __( 'Ask me to check prices that change by more than (percent)', 'products-stock-manager-with-excel' ), $o['price_warn'], 1, 1000 );
			$this->number( 's[keep_days]', __( 'Keep backups for (days)', 'products-stock-manager-with-excel' ), WPF_SM_Engine::site_options_value( 'keep_days', 30 ), 1, 365 );
			$global = get_option( 'stockManagerWooCommercelowStockValue', '' );
			/* translators: %s: WooCommerce's low stock number */
			$this->number( 'low_global', __( 'Low stock level for products without their own', 'products-stock-manager-with-excel' ), $global, 0, 100000, sprintf( __( 'Leave empty to use WooCommerce\'s setting (%s). Each product can have its own level in its Inventory tab or in the "Low stock level" column.', 'products-stock-manager-with-excel' ), (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) ) );
			$this->number( 's[sales_days]', __( 'Low stock: sales of the last (days)', 'products-stock-manager-with-excel' ), WPF_SM_Engine::site_options_value( 'sales_days', 30 ), 1, 365 );
			$this->number( 's[cover_days]', __( 'Low stock: reorder enough for (days)', 'products-stock-manager-with-excel' ), WPF_SM_Engine::site_options_value( 'cover_days', 30 ), 1, 365 );
			do_action( 'wpfactory_wc_sm_settings_fields', $o, $this );
			echo '</div><div class="wpf-sm-card-foot"><button class="wpf-sm-btn primary" type="submit">' . esc_html__( 'Save', 'products-stock-manager-with-excel' ) . '</button></div></form>';
		}

		/**
		 * Number setting row.
		 *
		 * @param string $name  Name.
		 * @param string $label Label.
		 * @param mixed  $value Value.
		 * @param int    $min   Min.
		 * @param int    $max   Max.
		 * @param string $help  Help.
		 */
		public function number( $name, $label, $value, $min, $max, $help = '' ) {
			$id = 'wpf-sm-' . sanitize_title( $name );
			echo '<div class="wpf-sm-row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $help ? '<span>' . esc_html( $help ) . '</span>' : '' ) . '</label>';
			echo '<input id="' . esc_attr( $id ) . '" type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" min="' . (int) $min . '" max="' . (int) $max . '"></div>';
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* Handlers                                                                                                */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Upload: read the file and show the columns.
		 */
		public function handle_upload() {
			$this->guard( 'upload' );
			$back = $this->url( array( 'tab' => 'import' ) );
			$id   = $this->start_from_upload();
			if ( is_wp_error( $id ) ) {
				$this->back( $back, 'bad', $id->get_error_message() );
			}
			$this->back( $this->url( array( 'tab' => 'import', 'run' => $id ) ) );
		}

		/**
		 * Read the uploaded file into a run.
		 *
		 * @return int|WP_Error
		 */
		protected function start_from_upload() {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by the caller.
			if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'], $_FILES['file']['name'], $_FILES['file']['error'] ) ) {
				return new WP_Error( 'wpf_sm_nofile', __( 'Choose a file first.', 'products-stock-manager-with-excel' ) );
			}
			$err = (int) $_FILES['file']['error'];
			if ( UPLOAD_ERR_OK !== $err ) {
				return new WP_Error( 'wpf_sm_upload', in_array( $err, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? sprintf( /* translators: %s: size */ __( 'The file is larger than this site accepts (%s). Save it as .csv, or split it.', 'products-stock-manager-with-excel' ), size_format( wp_max_upload_size() ) ) : __( 'The file did not arrive. Try again.', 'products-stock-manager-with-excel' ) );
			}
			$name = sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) );
			$tmp  = $_FILES['file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			// phpcs:enable
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, WPF_SM_Reader::extensions(), true ) || ! is_uploaded_file( $tmp ) ) {
				return new WP_Error( 'wpf_sm_type', __( 'Choose an .xlsx, .xls or .csv file.', 'products-stock-manager-with-excel' ) );
			}
			return WPF_SM_Engine::start( $tmp, $name, array( 'source' => 'upload' ) );
		}

		/**
		 * Columns confirmed.
		 */
		public function handle_columns() {
			$this->guard( 'columns' );
			$id  = $this->req_run();
			$run = WPF_SM_Runs::get( $id );
			if ( ! $run ) {
				$this->back( $this->url( array( 'tab' => 'import' ) ), 'bad', __( 'This update no longer exists. Upload the file again.', 'products-stock-manager-with-excel' ) );
			}
			$map = array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
			foreach ( (array) ( isset( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array() ) as $i => $key ) {
				$key = sanitize_text_field( $key );
				if ( '' !== $key && ! WPF_SM_Fields::get( $key ) ) {
					$key = '';
				}
				if ( '' !== $key && in_array( $key, $map, true ) ) {
					$key = ''; // One column per field.
				}
				$map[ absint( $i ) ] = $key;
			}
			ksort( $map );
			$opts = $this->posted_options();
			if ( ! WPF_SM_Fields::usable( $map ) ) {
				WPF_SM_Runs::update( $id, array( 'map' => $map ) );
				$this->back( $this->url( array( 'tab' => 'import', 'run' => $id ) ), 'warn', __( 'Choose at least one column that finds products (ID, SKU or GTIN) and one column to update.', 'products-stock-manager-with-excel' ) );
			}
			WPF_SM_Engine::confirm_columns( $id, $map, $opts );
			$this->back( $this->url( array( 'tab' => 'import', 'run' => $id ) ) );
		}

		/**
		 * Run choices posted on the columns step.
		 *
		 * @return array
		 */
		protected function posted_options() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$in   = isset( $_POST['options'] ) ? (array) wp_unslash( $_POST['options'] ) : array();
			$out  = array();
			$allowed = apply_filters(
				'wpfactory_wc_sm_run_choices',
				array(
					'stock_mode' => array( 'set', 'add' ),
					'duplicates' => array( 'last', 'sum' ),
				)
			);
			foreach ( $allowed as $k => $values ) {
				if ( isset( $in[ $k ] ) && in_array( $in[ $k ], $values, true ) ) {
					$out[ $k ] = $in[ $k ];
				}
			}
			return $out;
		}

		/**
		 * Confirm the preview.
		 */
		public function handle_confirm() {
			$this->guard( 'confirm' );
			$id  = $this->req_run();
			$run = WPF_SM_Runs::get( $id );
			if ( ! $run || 'planned' !== $run['state'] ) {
				$this->back( $this->url( array( 'tab' => 'import' ) ), 'bad', __( 'This preview is no longer open.', 'products-stock-manager-with-excel' ) );
			}
			WPF_SM_Engine::confirm( $id );
			$this->back( $this->url( array( 'tab' => 'import', 'run' => $id ) ) );
		}

		/**
		 * Cancel a run that was not written.
		 */
		public function handle_cancel() {
			$this->guard( 'cancel' );
			$id  = $this->req_run();
			$run = WPF_SM_Runs::get( $id );
			if ( $run && ! in_array( $run['state'], array( 'writing', 'done', 'failed' ), true ) ) {
				WPF_SM_Runs::delete( $id );
			}
			$this->back( $this->url( array( 'tab' => 'import' ) ), 'ok', __( 'Cancelled. Nothing was changed.', 'products-stock-manager-with-excel' ) );
		}

		/**
		 * One step without JavaScript.
		 */
		public function handle_continue() {
			$this->guard( 'continue' );
			$id = $this->req_run();
			$this->advance( $id );
			$this->back( $this->url( array( 'tab' => 'import', 'run' => $id ) ) );
		}

		/**
		 * Step a run; restores confirm themselves once checked.
		 *
		 * @param int $id Run id.
		 * @return array
		 */
		protected function advance( $id ) {
			$run = WPF_SM_Engine::step( $id, 6.0 );
			if ( 'planned' === $run['state'] && ! empty( $run['auto_write'] ) ) {
				WPF_SM_Engine::confirm( $id );
				$run = WPF_SM_Engine::step( $id, 3.0 );
			}
			return $run;
		}

		/**
		 * AJAX step.
		 */
		public function ajax_step() {
			check_ajax_referer( 'wpf_sm_step' );
			if ( ! current_user_can( self::cap() ) ) {
				wp_send_json_error( array( 'message' => __( 'You cannot do this.', 'products-stock-manager-with-excel' ) ), 403 );
			}
			$run = $this->advance( $this->req_run() );
			wp_send_json_success(
				array(
					'state'    => $run['state'],
					'progress' => isset( $run['progress'] ) ? (int) $run['progress'] : WPF_SM_Engine::progress( $run ),
					'busy'     => in_array( $run['state'], array( 'resolving', 'merging', 'comparing', 'writing' ), true ),
				)
			);
		}

		/**
		 * Undo an update (one click, then it runs).
		 */
		public function handle_restore() {
			$this->guard( 'restore' );
			$new = WPF_SM_Engine::make_restore( $this->req_run() );
			if ( is_wp_error( $new ) ) {
				$this->back( $this->url( array( 'tab' => 'backups' ) ), 'bad', $new->get_error_message() );
			}
			WPF_SM_Runs::update( $new, array( 'auto_write' => 1 ) );
			$this->back( $this->url( array( 'tab' => 'import', 'run' => $new ) ) );
		}

		/**
		 * Report as CSV: every row, what changed, why skipped.
		 */
		public function handle_report() {
			$this->guard( 'report' );
			$run = WPF_SM_Runs::get( $this->req_run() );
			if ( ! $run ) {
				wp_die( esc_html__( 'This update no longer exists.', 'products-stock-manager-with-excel' ) );
			}
			$data = WPF_SM_Engine::entries( $run, 'all', 1, PHP_INT_MAX );
			$file = sanitize_file_name( pathinfo( $run['title'], PATHINFO_FILENAME ) . '-report-' . gmdate( 'Y-m-d', strtotime( $run['date'] ) ) . '.csv' );
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $file . '"' );
			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fputcsv( $out, array( __( 'Row', 'products-stock-manager-with-excel' ), __( 'Product ID', 'products-stock-manager-with-excel' ), __( 'SKU', 'products-stock-manager-with-excel' ), __( 'Product', 'products-stock-manager-with-excel' ), __( 'Result', 'products-stock-manager-with-excel' ), __( 'What changes', 'products-stock-manager-with-excel' ), __( 'Notes', 'products-stock-manager-with-excel' ) ), ',', '"', '\\' );
			$words = array(
				'ready'   => __( 'Ready', 'products-stock-manager-with-excel' ),
				'check'   => __( 'Check', 'products-stock-manager-with-excel' ),
				'skip'    => __( 'Skipped', 'products-stock-manager-with-excel' ),
				'same'    => __( 'No change', 'products-stock-manager-with-excel' ),
				'written' => __( 'Updated', 'products-stock-manager-with-excel' ),
				'failed'  => __( 'Failed', 'products-stock-manager-with-excel' ),
			);
			foreach ( $data['entries'] as $e ) {
				$result = $e['result'] ? $e['result'][0] : $e['status'];
				$notes  = array_map( array( __CLASS__, 'note_text' ), $e['notes'] );
				if ( $e['result'] && 'failed' === $e['result'][0] ) {
					$notes[] = $e['result'][1];
				}
				$row = array( implode( ' ', $e['lines'] ), $e['pid'] ? $e['pid'] : '', $e['sku'], $e['name'], isset( $words[ $result ] ) ? $words[ $result ] : $result, self::changes_text( $e['changes'] ), implode( ' ', $notes ) );
				fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $row ), ',', '"', '\\' );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			exit;
		}

		/**
		 * Stop spreadsheet formulas in text cells.
		 *
		 * @param string $v Cell.
		 * @return string
		 */
		public static function csv_safe( $v ) {
			$v = (string) $v;
			return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $v ) ) ? "'" . $v : $v;
		}

		/**
		 * Save settings.
		 */
		public function handle_settings() {
			$this->guard( 'settings' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
			$in  = isset( $_POST['s'] ) ? (array) wp_unslash( $_POST['s'] ) : array();
			$old = get_option( 'wpfactory_wc_sm_settings', array() );
			$old = is_array( $old ) ? $old : array();
			$new = $old;
			$enums = array(
				'stock_mode'   => array( 'set', 'add' ),
				'duplicates'   => array( 'last', 'sum' ),
				'untracked'    => array( 'skip', 'track' ),
				'zero_regular' => array( 'skip', 'save' ),
			);
			foreach ( $enums as $k => $values ) {
				if ( isset( $in[ $k ] ) && in_array( $in[ $k ], $values, true ) ) {
					$new[ $k ] = $in[ $k ];
				}
			}
			foreach ( array( 'price_warn' => array( 1, 1000 ), 'keep_days' => array( 1, 365 ), 'sales_days' => array( 1, 365 ), 'cover_days' => array( 1, 365 ) ) as $k => $r ) {
				if ( isset( $in[ $k ] ) && '' !== $in[ $k ] ) {
					$new[ $k ] = min( $r[1], max( $r[0], absint( $in[ $k ] ) ) );
				}
			}
			$new = apply_filters( 'wpfactory_wc_sm_save_settings', $new, $in );
			update_option( 'wpfactory_wc_sm_settings', $new, false );
			// Keep the old options in step for anyone who goes back to an older version.
			$old_sum = get_option( 'stockManagerWooCommerceaddStockNumber' ) ? 'sum' : 'last';
			if ( isset( $new['duplicates'] ) && $new['duplicates'] !== $old_sum ) {
				update_option( 'stockManagerWooCommerceaddStockNumber', 'sum' === $new['duplicates'] ? 'yes' : '' );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$low = isset( $_POST['low_global'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['low_global'] ) ) ) : null;
			if ( null !== $low && (string) get_option( 'stockManagerWooCommercelowStockValue', '' ) !== $low && ( '' === $low || ctype_digit( $low ) ) ) {
				update_option( 'stockManagerWooCommercelowStockValue', $low );
			}
			$this->back( $this->url( array( 'tab' => 'settings' ) ), 'ok', __( 'Settings saved.', 'products-stock-manager-with-excel' ) );
		}

		/**
		 * Export filters from the request.
		 *
		 * @return array
		 */
		protected function export_request() {
			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$f    = isset( $_POST['f'] ) ? (array) wp_unslash( $_POST['f'] ) : array();
			$cols = isset( $_POST['cols'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cols'] ) ) : array();
			// phpcs:enable
			return array(
				array(
					'category' => isset( $f['category'] ) ? absint( $f['category'] ) : 0,
					'status'   => isset( $f['status'] ) ? sanitize_key( $f['status'] ) : '',
					'type'     => isset( $f['type'] ) && isset( wc_get_product_types()[ $f['type'] ] ) ? sanitize_key( $f['type'] ) : '',
					'search'   => isset( $f['search'] ) ? sanitize_text_field( $f['search'] ) : '',
				),
				$cols,
			);
		}

		/**
		 * Export without JavaScript: all in one request.
		 */
		public function handle_export() {
			$this->guard( 'export' );
			list( $f, $cols ) = $this->export_request();
			$token            = WPF_SM_Export::begin( $f, $cols );
			if ( is_wp_error( $token ) ) {
				$this->back( $this->url( array( 'tab' => 'export' ) ), 'bad', $token->get_error_message() );
			}
			wc_set_time_limit( 0 );
			do {
				$r = WPF_SM_Export::step( $token );
			} while ( ! is_wp_error( $r ) && ! $r['done'] );
			$this->send_export( $token );
		}

		/**
		 * AJAX: start an export.
		 */
		public function ajax_export_start() {
			check_ajax_referer( 'wpf_sm_export' );
			if ( ! current_user_can( self::cap() ) ) {
				wp_send_json_error( array( 'message' => __( 'You cannot do this.', 'products-stock-manager-with-excel' ) ), 403 );
			}
			list( $f, $cols ) = $this->export_request();
			$token            = WPF_SM_Export::begin( $f, $cols );
			if ( is_wp_error( $token ) ) {
				wp_send_json_error( array( 'message' => $token->get_error_message() ) );
			}
			wp_send_json_success( array( 'token' => $token ) );
		}

		/**
		 * AJAX: one export batch.
		 */
		public function ajax_export_step() {
			check_ajax_referer( 'wpf_sm_export' );
			if ( ! current_user_can( self::cap() ) ) {
				wp_send_json_error( array( 'message' => __( 'You cannot do this.', 'products-stock-manager-with-excel' ) ), 403 );
			}
			$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
			$r     = WPF_SM_Export::step( $token );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			if ( $r['done'] ) {
				$r['url'] = $this->post_url( 'export_download', array( 'token' => $token ) );
			}
			wp_send_json_success( $r );
		}

		/**
		 * Download a finished export.
		 */
		public function handle_export_download() {
			$this->guard( 'export_download' );
			$token = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$state = get_transient( 'wpf_sm_export_' . $token );
			if ( ! $state || (int) $state['user'] !== get_current_user_id() ) {
				$this->back( $this->url( array( 'tab' => 'export' ) ), 'bad', __( 'The export expired. Start it again.', 'products-stock-manager-with-excel' ) );
			}
			$this->send_export( $token );
		}

		/**
		 * Build and send the file, then delete it.
		 *
		 * @param string $token Token.
		 */
		protected function send_export( $token ) {
			$path = WPF_SM_Xlsx::finish( $token );
			delete_transient( 'wpf_sm_export_' . $token );
			if ( is_wp_error( $path ) ) {
				$this->back( $this->url( array( 'tab' => 'export' ) ), 'bad', $path->get_error_message() );
			}
			$this->send_file( $path, 'products-' . gmdate( 'Y-m-d' ) );
			wp_delete_file( WPF_SM_Xlsx::path( $token, 'json' ) );
			exit;
		}

		/**
		 * Stream a file and delete it.
		 *
		 * @param string $path File.
		 * @param string $name Download name without extension.
		 */
		protected function send_file( $path, $name ) {
			$ext = pathinfo( $path, PATHINFO_EXTENSION );
			nocache_headers();
			header( 'Content-Type: ' . ( 'xlsx' === $ext ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8' ) );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name . '.' . $ext ) . '"' );
			header( 'Content-Length: ' . filesize( $path ) );
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			wp_delete_file( $path );
		}

		/**
		 * Low stock as .xlsx.
		 */
		public function handle_lowstock_download() {
			$this->guard( 'lowstock_download' );
			$a     = $this->lowstock_args();
			$rows  = WPF_SM_Export::low_stock( $a['show'], $a['days'], $a['cover'] );
			$token = strtolower( wp_generate_password( 32, false, false ) );
			$ok    = WPF_SM_Xlsx::begin(
				$token,
				array( __( 'ID', 'products-stock-manager-with-excel' ), __( 'SKU', 'products-stock-manager-with-excel' ), __( 'Name', 'products-stock-manager-with-excel' ), __( 'Stock', 'products-stock-manager-with-excel' ), __( 'Low stock level', 'products-stock-manager-with-excel' ), __( 'Sold', 'products-stock-manager-with-excel' ), __( 'Days left', 'products-stock-manager-with-excel' ), __( 'Reorder', 'products-stock-manager-with-excel' ) ),
				array( 'n', 's', 's', 'n', 'n', 'n', 'n', 'n' )
			);
			if ( is_wp_error( $ok ) ) {
				$this->back( $this->url( array( 'tab' => 'report' ) ), 'bad', $ok->get_error_message() );
			}
			$out = array();
			foreach ( $rows as $r ) {
				$out[] = array(
					'cells' => array( $r['id'], $r['sku'], $r['name'], $r['stock'], $r['level'], null === $r['sold'] ? '' : $r['sold'], null === $r['left'] ? '' : $r['left'], null === $r['reorder'] ? '' : $r['reorder'] ),
					'style' => 'out' === $r['status'] ? WPF_SM_Xlsx::STYLE_OUT : ( 'low' === $r['status'] ? WPF_SM_Xlsx::STYLE_LOW : 0 ),
				);
			}
			WPF_SM_Xlsx::add( $token, $out );
			$path = WPF_SM_Xlsx::finish( $token );
			if ( is_wp_error( $path ) ) {
				$this->back( $this->url( array( 'tab' => 'report' ) ), 'bad', $path->get_error_message() );
			}
			$this->send_file( $path, 'low-stock-' . gmdate( 'Y-m-d' ) );
			wp_delete_file( WPF_SM_Xlsx::path( $token, 'json' ) );
			exit;
		}

		/**
		 * Old upload action: answers with where to go next.
		 */
		public function ajax_legacy_upload() {
			check_ajax_referer( 'update_products' );
			if ( ! current_user_can( self::cap() ) ) {
				wp_send_json_error( array( 'message' => __( 'You cannot do this.', 'products-stock-manager-with-excel' ) ), 403 );
			}
			$id = $this->start_from_upload();
			if ( is_wp_error( $id ) ) {
				wp_send_json_error( array( 'message' => $id->get_error_message() ) );
			}
			wp_send_json_success( array( 'url' => $this->url( array( 'tab' => 'import', 'run' => $id ) ) ) );
		}

		/**
		 * Old export action: the export moved to the Export screen.
		 */
		public function ajax_legacy_export() {
			if ( ! current_user_can( self::cap() ) ) {
				wp_send_json_error( array(), 403 );
			}
			wp_send_json_error( array( 'url' => $this->url( array( 'tab' => 'export' ) ) ) );
		}
	}

endif;
