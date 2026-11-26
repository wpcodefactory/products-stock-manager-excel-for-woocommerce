<?php
/**
 * Stock Manager engine: export to .xlsx in batches, and the low stock list with sales.
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPF_SM_Export' ) ) :

	/**
	 * Export and low stock.
	 */
	class WPF_SM_Export {

		const BATCH = 150;

		/**
		 * Columns an export can have. The first six keep the old layout (ID, SKU, regular, sale, stock, status), so a
		 * file exported here also works with the old fixed columns.
		 *
		 * @return array key => array( label, type, default )
		 */
		public static function columns() {
			$cols = array(
				'id'               => array( __( 'ID', 'products-stock-manager-with-excel' ), 'n', true ),
				'sku'              => array( __( 'SKU', 'products-stock-manager-with-excel' ), 's', true ),
				'regular_price'    => array( __( 'Regular price', 'products-stock-manager-with-excel' ), 'n', true ),
				'sale_price'       => array( __( 'Sale price', 'products-stock-manager-with-excel' ), 'n', true ),
				'stock'            => array( __( 'Stock', 'products-stock-manager-with-excel' ), 'n', true ),
				'stock_status'     => array( __( 'Stock status', 'products-stock-manager-with-excel' ), 's', true ),
				'name'             => array( __( 'Name', 'products-stock-manager-with-excel' ), 's', true ),
				'type'             => array( __( 'Type', 'products-stock-manager-with-excel' ), 's', true ),
				'parent'           => array( __( 'Parent', 'products-stock-manager-with-excel' ), 's', true ),
				'low_stock_amount' => array( __( 'Low stock level', 'products-stock-manager-with-excel' ), 'n', true ),
				'manage_stock'     => array( __( 'Track stock', 'products-stock-manager-with-excel' ), 's', true ),
				'gtin'             => array( __( 'GTIN, UPC, EAN or ISBN', 'products-stock-manager-with-excel' ), 's', false ),
				'categories'       => array( __( 'Categories', 'products-stock-manager-with-excel' ), 's', false ),
			);
			/**
			 * Export columns. Pro adds sale dates, backorders, weight, dimensions, cost, attributes and custom meta.
			 *
			 * @param array $cols key => array( label, 's' or 'n', on by default ).
			 */
			return apply_filters( 'wpfactory_wc_sm_export_columns', $cols );
		}

		/**
		 * Parent product ids for the filters, in store order (menu order, then name).
		 *
		 * @param array $f Filters: category, type, status, search.
		 * @return int[]
		 */
		public static function parent_ids( $f ) {
			$args = array(
				'status'  => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
					'ID'         => 'ASC',
				),
				'type'    => ! empty( $f['type'] ) ? array( $f['type'] ) : array_keys( wc_get_product_types() ),
			);
			if ( ! empty( $f['category'] ) ) {
				$term = get_term( (int) $f['category'], 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$args['category'] = array( $term->slug );
				}
			}
			if ( ! empty( $f['search'] ) ) {
				$args['s'] = sanitize_text_field( $f['search'] );
			}
			$ids = wc_get_products( $args );
			if ( ! empty( $f['search'] ) ) {
				// Also find products whose SKU (or a variation's SKU) contains the text.
				global $wpdb;
				$like = '%' . $wpdb->esc_like( sanitize_text_field( $f['search'] ) ) . '%';
				$more = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT IF(p.post_type='product_variation', p.post_parent, p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE m.meta_value LIKE %s AND p.post_type IN ('product','product_variation')", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$ids  = array_values( array_unique( array_merge( $ids, array_map( 'intval', $more ) ) ) );
			}
			return array_map( 'intval', $ids );
		}

		/**
		 * Start an export. Returns a token.
		 *
		 * @param array $filters Filters.
		 * @param array $cols    Column keys.
		 * @return string|WP_Error
		 */
		public static function begin( $filters, $cols ) {
			$all  = self::columns();
			$cols = array_values( array_filter( (array) $cols, function ( $k ) use ( $all ) { return isset( $all[ $k ] ); } ) ); // phpcs:ignore
			if ( ! $cols ) {
				$cols = array_keys( array_filter( $all, function ( $c ) { return ! empty( $c[2] ); } ) ); // phpcs:ignore
			}
			$token = strtolower( wp_generate_password( 32, false, false ) );
			$ok    = WPF_SM_Xlsx::begin(
				$token,
				array_map( function ( $k ) use ( $all ) { return $all[ $k ][0]; }, $cols ), // phpcs:ignore
				array_map( function ( $k ) use ( $all ) { return $all[ $k ][1]; }, $cols ) // phpcs:ignore
			);
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$state = array(
				'ids'     => self::parent_ids( $filters ),
				'cursor'  => 0,
				'cols'    => $cols,
				'filters' => $filters,
				'user'    => get_current_user_id(),
			);
			set_transient( 'wpf_sm_export_' . $token, $state, DAY_IN_SECONDS );
			return $token;
		}

		/**
		 * Export one batch. Returns array( done, progress, rows ).
		 *
		 * @param string $token Token.
		 * @return array|WP_Error
		 */
		public static function step( $token ) {
			$state = get_transient( 'wpf_sm_export_' . $token );
			if ( ! $state || (int) $state['user'] !== get_current_user_id() ) {
				return new WP_Error( 'wpf_sm_export', __( 'The export expired. Start it again.', 'products-stock-manager-with-excel' ) );
			}
			$slice = array_slice( $state['ids'], $state['cursor'], self::BATCH );
			$rows  = array();
			foreach ( $slice as $pid ) {
				$p = wc_get_product( $pid );
				if ( ! $p ) {
					continue;
				}
				$children = array();
				if ( $p->is_type( 'variable' ) ) {
					foreach ( $p->get_children() as $vid ) {
						$v = wc_get_product( $vid );
						if ( $v && self::keep( $v, $state['filters'] ) ) {
							$children[] = self::row( $v, $state['cols'], $p );
						}
					}
				}
				if ( $children || self::keep( $p, $state['filters'] ) ) {
					$rows[] = self::row( $p, $state['cols'], null );
					$rows   = array_merge( $rows, $children );
				}
			}
			WPF_SM_Xlsx::add( $token, $rows );
			$state['cursor'] += count( $slice );
			set_transient( 'wpf_sm_export_' . $token, $state, DAY_IN_SECONDS );
			$total = count( $state['ids'] );
			return array(
				'done'     => $state['cursor'] >= $total,
				'progress' => $total ? (int) floor( 100 * $state['cursor'] / $total ) : 100,
				'rows'     => WPF_SM_Xlsx::count( $token ),
			);
		}

		/**
		 * Whether a product passes the stock filter.
		 *
		 * @param WC_Product $p Product.
		 * @param array      $f Filters.
		 * @return bool
		 */
		protected static function keep( $p, $f ) {
			$want = isset( $f['status'] ) ? $f['status'] : '';
			if ( '' === $want ) {
				return true;
			}
			if ( 'low' === $want ) {
				return $p->managing_stock() && ( 'outofstock' === $p->get_stock_status() || (float) $p->get_stock_quantity() <= self::low_level( $p ) );
			}
			return $p->get_stock_status() === $want;
		}

		/**
		 * Low stock level: the product's own (or its parent's), else the plugin's store-wide number (old setting), else
		 * WooCommerce's store setting.
		 *
		 * @param WC_Product $p Product.
		 * @return int
		 */
		public static function low_level( $p ) {
			$own = $p->get_low_stock_amount( 'edit' );
			if ( '' === $own && $p->get_parent_id() ) {
				$parent = wc_get_product( $p->get_parent_id() );
				$own    = $parent ? $parent->get_low_stock_amount( 'edit' ) : '';
			}
			if ( '' !== $own && null !== $own ) {
				return (int) $own;
			}
			$global = get_option( 'stockManagerWooCommercelowStockValue', '' );
			if ( '' !== $global && is_numeric( $global ) ) {
				return (int) $global;
			}
			return (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		}

		/**
		 * Row for one product.
		 *
		 * @param WC_Product      $p      Product.
		 * @param array           $cols   Columns.
		 * @param WC_Product|null $parent Parent of a variation.
		 * @return array
		 */
		protected static function row( $p, $cols, $parent ) {
			$cells    = array();
			$statuses = wc_get_product_stock_status_options();
			foreach ( $cols as $key ) {
				switch ( $key ) {
					case 'id':
						$v = $p->get_id();
						break;
					case 'sku':
						$v = $p->get_sku( 'edit' );
						break;
					case 'regular_price':
						$v = $p->is_type( array( 'variable', 'grouped' ) ) ? '' : $p->get_regular_price( 'edit' );
						break;
					case 'sale_price':
						$v = $p->is_type( array( 'variable', 'grouped' ) ) ? '' : $p->get_sale_price( 'edit' );
						break;
					case 'stock':
						$v = $p->get_manage_stock( 'edit' ) ? wc_stock_amount( $p->get_stock_quantity( 'edit' ) ) : '';
						break;
					case 'stock_status':
						$v = $p->is_type( array( 'grouped', 'external' ) ) ? '' : ( isset( $statuses[ $p->get_stock_status( 'edit' ) ] ) ? $statuses[ $p->get_stock_status( 'edit' ) ] : $p->get_stock_status( 'edit' ) );
						break;
					case 'name':
						$v = $p->get_name( 'edit' );
						if ( $parent ) {
							$v = wp_strip_all_tags( $p->get_name() );
						}
						break;
					case 'type':
						$types = wc_get_product_types();
						$v     = $p->is_type( 'variation' ) ? __( 'Variation', 'products-stock-manager-with-excel' ) : ( isset( $types[ $p->get_type() ] ) ? $types[ $p->get_type() ] : $p->get_type() );
						break;
					case 'parent':
						$v = $parent ? ( $parent->get_sku() ? $parent->get_sku() : $parent->get_id() ) : '';
						break;
					case 'low_stock_amount':
						$v = $p->get_manage_stock( 'edit' ) ? $p->get_low_stock_amount( 'edit' ) : '';
						break;
					case 'manage_stock':
						$v = $p->is_type( array( 'grouped', 'external' ) ) ? '' : ( $p->get_manage_stock( 'edit' ) ? __( 'Yes', 'products-stock-manager-with-excel' ) : __( 'No', 'products-stock-manager-with-excel' ) );
						break;
					case 'gtin':
						$v = method_exists( $p, 'get_global_unique_id' ) ? $p->get_global_unique_id( 'edit' ) : '';
						break;
					case 'categories':
						$v = wp_strip_all_tags( wc_get_product_category_list( $parent ? $parent->get_id() : $p->get_id(), ', ' ) );
						break;
					default:
						/**
						 * Value of a custom export column.
						 *
						 * @param string          $v      Value.
						 * @param string          $key    Column.
						 * @param WC_Product      $p      Product.
						 * @param WC_Product|null $parent Parent.
						 */
						$v = apply_filters( 'wpfactory_wc_sm_export_value', '', $key, $p, $parent );
				}
				$cells[] = is_scalar( $v ) ? (string) $v : '';
			}
			$style = WPF_SM_Xlsx::STYLE_NONE;
			if ( ! $p->is_type( array( 'grouped', 'external', 'variable' ) ) || $p->get_manage_stock( 'edit' ) ) {
				if ( 'outofstock' === $p->get_stock_status( 'edit' ) && ! $p->is_type( array( 'grouped', 'external' ) ) ) {
					$style = WPF_SM_Xlsx::STYLE_OUT;
				} elseif ( $p->managing_stock() && null !== $p->get_stock_quantity() && (float) $p->get_stock_quantity() <= self::low_level( $p ) ) {
					$style = WPF_SM_Xlsx::STYLE_LOW;
				}
			}
			return array(
				'cells' => $cells,
				'style' => $style,
			);
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* Low stock                                                                                               */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Units sold per product or variation in the last $days days, from WooCommerce's order lookup table (works
		 * with HPOS and with posts storage). Refunded, cancelled, failed and pending orders do not count.
		 *
		 * @param int $days Days.
		 * @return array|null id => qty, or null when the table is not there.
		 */
		public static function sales( $days ) {
			global $wpdb;
			$t = $wpdb->prefix . 'wc_order_product_lookup';
			$s = $wpdb->prefix . 'wc_order_stats';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return null;
			}
			$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT l.product_id, l.variation_id, SUM(l.product_qty) qty FROM {$t} l JOIN {$s} o ON o.order_id = l.order_id WHERE l.date_created >= %s AND o.status NOT IN ('wc-refunded','wc-cancelled','wc-failed','wc-pending','wc-checkout-draft','trash') GROUP BY l.product_id, l.variation_id", $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from $wpdb->prefix.
			$out   = array();
			foreach ( $rows as $r ) {
				$id         = (int) $r->variation_id ? (int) $r->variation_id : (int) $r->product_id;
				$out[ $id ] = ( isset( $out[ $id ] ) ? $out[ $id ] : 0 ) + (float) $r->qty;
			}
			return $out;
		}

		/**
		 * Low stock rows: products and variations that track stock, with stock, level, sold, days left, reorder.
		 *
		 * @param string $show  low (at or under the level, and out of stock), out, all.
		 * @param int    $days  Sales period.
		 * @param int    $cover Days of stock to aim for.
		 * @return array
		 */
		public static function low_stock( $show = 'low', $days = 30, $cover = 30 ) {
			global $wpdb;
			$ids   = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_manage_stock' AND m.meta_value = 'yes' WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private') ORDER BY p.ID" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$sales = self::sales( $days );
			$rows  = array();
			foreach ( $ids as $id ) {
				$p = wc_get_product( (int) $id );
				if ( ! $p || $p->is_type( array( 'grouped', 'external' ) ) ) {
					continue;
				}
				if ( $p->is_type( 'variation' ) && ! $p->get_manage_stock( 'edit' ) ) {
					continue;
				}
				$qty   = (float) $p->get_stock_quantity();
				$level = self::low_level( $p );
				$out   = 'outofstock' === $p->get_stock_status() || $qty <= 0;
				$low   = $qty <= $level;
				if ( ( 'low' === $show && ! $low && ! $out ) || ( 'out' === $show && ! $out ) ) {
					continue;
				}
				$sold    = null === $sales ? null : ( isset( $sales[ (int) $id ] ) ? $sales[ (int) $id ] : 0 );
				$rate    = $sold ? $sold / max( 1, $days ) : 0;
				$left    = $rate > 0 ? max( 0, (int) floor( $qty / $rate ) ) : null;
				$reorder = $rate > 0 ? max( 0, (int) ceil( $rate * $cover - $qty ) ) : null;
				$rows[]  = array(
					'id'      => (int) $id,
					'name'    => wp_strip_all_tags( $p->get_name() ),
					'sku'     => $p->get_sku(),
					'stock'   => wc_stock_amount( $qty ),
					'level'   => $level,
					'status'  => $out ? 'out' : ( $low ? 'low' : 'ok' ),
					'sold'    => $sold,
					'left'    => $left,
					'reorder' => $reorder,
					'edit'    => get_edit_post_link( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id(), 'raw' ),
				);
			}
			usort(
				$rows,
				function ( $a, $b ) {
					$la = null === $a['left'] ? PHP_INT_MAX : $a['left'];
					$lb = null === $b['left'] ? PHP_INT_MAX : $b['left'];
					if ( $la !== $lb ) {
						return $la - $lb;
					}
					return $a['stock'] <=> $b['stock'];
				}
			);
			return $rows;
		}
	}

endif;
