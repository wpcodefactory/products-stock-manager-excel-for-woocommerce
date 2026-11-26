<?php
/**
 * Stock Manager engine: the fields a sheet column can update or use to find a product.
 *
 * Shared by the free plugin and Pro (Pro adds fields through the `wpfactory_wc_sm_fields` filter).
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPF_SM_Fields' ) ) :

	/**
	 * Field registry and header matching.
	 */
	class WPF_SM_Fields {

		/**
		 * Cached list.
		 *
		 * @var array|null
		 */
		protected static $fields = null;

		/**
		 * All fields.
		 *
		 * Each field: label, kind (key, number, price, status, bool, text, info), aliases (normalised header words),
		 * applies (product types it can be written to; empty for keys and info).
		 *
		 * @return array
		 */
		public static function all() {
			if ( null !== self::$fields ) {
				return self::$fields;
			}
			$stocked = array( 'simple', 'variation', 'variable' );
			$priced  = array( 'simple', 'variation', 'external' );
			$fields  = array(
				'id'               => array(
					'label'   => __( 'Product ID', 'products-stock-manager-with-excel' ),
					'kind'    => 'key',
					'aliases' => array( 'id', 'product id', 'post id', 'variation id', 'wc id', 'woocommerce id' ),
				),
				'sku'              => array(
					'label'   => __( 'SKU', 'products-stock-manager-with-excel' ),
					'kind'    => 'key',
					'aliases' => array( 'sku', 'product sku', 'variation sku', 'item code', 'article number', 'art nr', 'art no', 'artikelnummer', 'reference', 'ref', 'part number', 'item number', 'product code', 'code' ),
				),
				'gtin'             => array(
					'label'   => __( 'GTIN, UPC, EAN or ISBN', 'products-stock-manager-with-excel' ),
					'kind'    => 'key',
					'aliases' => array( 'gtin', 'ean', 'upc', 'isbn', 'barcode', 'ean13', 'gtin upc ean isbn', 'gtin upc ean or isbn', 'global unique id' ),
				),
				'stock'            => array(
					'label'   => __( 'Stock', 'products-stock-manager-with-excel' ),
					'kind'    => 'number',
					'aliases' => array( 'stock', 'stock quantity', 'stock qty', 'quantity', 'qty', 'inventory', 'on hand', 'available', 'units', 'bestand', 'stock level', 'in stock qty' ),
					'applies' => $stocked,
				),
				'stock_status'     => array(
					'label'   => __( 'Stock status', 'products-stock-manager-with-excel' ),
					'kind'    => 'status',
					'aliases' => array( 'stock status', 'availability', 'status', 'in stock' ),
					'applies' => array( 'simple', 'variation', 'variable' ),
				),
				'regular_price'    => array(
					'label'   => __( 'Regular price', 'products-stock-manager-with-excel' ),
					'kind'    => 'price',
					'aliases' => array( 'regular price', 'price', 'retail price', 'rrp', 'list price', 'normal price' ),
					'applies' => $priced,
				),
				'sale_price'       => array(
					'label'   => __( 'Sale price', 'products-stock-manager-with-excel' ),
					'kind'    => 'price',
					'aliases' => array( 'sale price', 'special price', 'offer price', 'discount price', 'promo price', 'sale' ),
					'applies' => $priced,
				),
				'low_stock_amount' => array(
					'label'   => __( 'Low stock level', 'products-stock-manager-with-excel' ),
					'kind'    => 'number',
					'aliases' => array( 'low stock level', 'low stock', 'low stock threshold', 'low stock amount', 'reorder level', 'reorder point' ),
					'applies' => $stocked,
				),
				'manage_stock'     => array(
					'label'   => __( 'Track stock', 'products-stock-manager-with-excel' ),
					'kind'    => 'bool',
					'aliases' => array( 'track stock', 'manage stock', 'stock tracking', 'manage stock?' ),
					'applies' => $stocked,
				),
				'name'             => array(
					'label'   => __( 'Name (not changed)', 'products-stock-manager-with-excel' ),
					'kind'    => 'info',
					'aliases' => array( 'name', 'title', 'product title', 'product name', 'product', 'description' ),
				),
				'type'             => array(
					'label'   => __( 'Type (not changed)', 'products-stock-manager-with-excel' ),
					'kind'    => 'info',
					'aliases' => array( 'type', 'product type' ),
				),
				'parent'           => array(
					'label'   => __( 'Parent (not changed)', 'products-stock-manager-with-excel' ),
					'kind'    => 'info',
					'aliases' => array( 'parent', 'parent id', 'parent sku' ),
				),
			);

			/**
			 * Fields a sheet column can map to. Pro adds sale dates, backorders, weight, cost and custom meta.
			 *
			 * @param array $fields Field definitions keyed by field key.
			 */
			self::$fields = apply_filters( 'wpfactory_wc_sm_fields', $fields );
			return self::$fields;
		}

		/**
		 * One field or null.
		 *
		 * @param string $key Field key.
		 * @return array|null
		 */
		public static function get( $key ) {
			$all = self::all();
			if ( isset( $all[ $key ] ) ) {
				return $all[ $key ];
			}
			if ( 0 === strpos( (string) $key, 'meta:' ) ) {
				/** Custom meta columns (Pro). */
				return apply_filters( 'wpfactory_wc_sm_meta_field', null, substr( $key, 5 ) );
			}
			return null;
		}

		/**
		 * Fields that can be written (not keys, not info).
		 *
		 * @return array
		 */
		public static function writable() {
			return array_filter(
				self::all(),
				function ( $f ) {
					return ! in_array( $f['kind'], array( 'key', 'info' ), true );
				}
			);
		}

		/**
		 * Lower case, accents out, every run of other characters to one space.
		 *
		 * @param string $text Header text.
		 * @return string
		 */
		public static function normalise( $text ) {
			$text = remove_accents( wp_strip_all_tags( (string) $text ) );
			$text = strtolower( $text );
			$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
			return trim( $text );
		}

		/**
		 * Field key for a header, or '' when unknown.
		 *
		 * @param string $header Header cell.
		 * @return string
		 */
		public static function match_header( $header ) {
			$norm = self::normalise( $header );
			if ( '' === $norm ) {
				return '';
			}
			foreach ( self::all() as $key => $field ) {
				$words = $field['aliases'];
				$words[] = self::normalise( $field['label'] );
				$words[] = self::normalise( $key );
				foreach ( $words as $alias ) {
					if ( self::normalise( $alias ) === $norm ) {
						return $key;
					}
				}
			}
			/** Lets Pro match custom meta headers such as `meta:_supplier_code`. */
			return (string) apply_filters( 'wpfactory_wc_sm_match_header', '', $header, $norm );
		}

		/**
		 * Mapping for a header row: column index => field key ('' = not used). One column per field (first wins).
		 *
		 * @param array $header Header cells.
		 * @return array
		 */
		public static function map_headers( $header ) {
			$map  = array();
			$used = array();
			foreach ( array_values( $header ) as $i => $cell ) {
				$key = self::match_header( $cell );
				if ( '' !== $key && ! isset( $used[ $key ] ) ) {
					$map[ $i ]    = $key;
					$used[ $key ] = true;
				} else {
					$map[ $i ] = '';
				}
			}
			return $map;
		}

		/**
		 * The old fixed layout (free 3.0.4): A ID, B SKU, C regular price, D sale price, E stock, F stock status.
		 *
		 * @return array
		 */
		public static function legacy_map() {
			return array( 0 => 'id', 1 => 'sku', 2 => 'regular_price', 3 => 'sale_price', 4 => 'stock', 5 => 'stock_status' );
		}

		/**
		 * Whether a mapping can find products and change something.
		 *
		 * @param array $map Mapping.
		 * @return bool
		 */
		public static function usable( $map ) {
			$has_key   = false;
			$has_write = false;
			foreach ( $map as $key ) {
				$f = $key ? self::get( $key ) : null;
				if ( ! $f ) {
					continue;
				}
				if ( 'key' === $f['kind'] ) {
					$has_key = true;
				} elseif ( 'info' !== $f['kind'] ) {
					$has_write = true;
				}
			}
			return $has_key && $has_write;
		}

		/**
		 * Column letter for an index (0 = A).
		 *
		 * @param int $i Index.
		 * @return string
		 */
		public static function letter( $i ) {
			$s = '';
			$i = (int) $i + 1;
			while ( $i > 0 ) {
				$m = ( $i - 1 ) % 26;
				$s = chr( 65 + $m ) . $s;
				$i = (int) ( ( $i - $m ) / 26 );
			}
			return $s;
		}

		/**
		 * Index for a column letter (A = 0), or -1.
		 *
		 * @param string $letter Letter(s).
		 * @return int
		 */
		public static function index( $letter ) {
			$letter = strtoupper( trim( (string) $letter ) );
			if ( ! preg_match( '/^[A-Z]{1,3}$/', $letter ) ) {
				return -1;
			}
			$n = 0;
			foreach ( str_split( $letter ) as $c ) {
				$n = $n * 26 + ( ord( $c ) - 64 );
			}
			return $n - 1;
		}

		/**
		 * Stock status from what people type: instock, "In stock", yes, 1, outofstock, "Out of stock", no, 0, backorder.
		 *
		 * @param mixed $value Cell.
		 * @return string|null WooCommerce status key or null when not understood.
		 */
		public static function parse_status( $value ) {
			$norm = str_replace( ' ', '', self::normalise( $value ) );
			$map  = array(
				'instock'      => 'instock',
				'yes'          => 'instock',
				'y'            => 'instock',
				'1'            => 'instock',
				'true'         => 'instock',
				'available'    => 'instock',
				'outofstock'   => 'outofstock',
				'no'           => 'outofstock',
				'n'            => 'outofstock',
				'0'            => 'outofstock',
				'false'        => 'outofstock',
				'soldout'      => 'outofstock',
				'onbackorder'  => 'onbackorder',
				'backorder'    => 'onbackorder',
				'backorders'   => 'onbackorder',
				'onbackorders' => 'onbackorder',
			);
			$statuses = function_exists( 'wc_get_product_stock_status_options' ) ? wc_get_product_stock_status_options() : array();
			foreach ( $statuses as $key => $label ) {
				$map[ str_replace( ' ', '', self::normalise( $label ) ) ] = $key;
				$map[ str_replace( ' ', '', self::normalise( $key ) ) ]   = $key;
			}
			return isset( $map[ $norm ] ) ? $map[ $norm ] : null;
		}

		/**
		 * Yes or no from what people type.
		 *
		 * @param mixed $value Cell.
		 * @return bool|null
		 */
		public static function parse_bool( $value ) {
			$norm = self::normalise( $value );
			if ( in_array( $norm, array( '1', 'yes', 'y', 'true', 'on', self::normalise( __( 'Yes', 'products-stock-manager-with-excel' ) ) ), true ) ) {
				return true;
			}
			if ( in_array( $norm, array( '0', 'no', 'n', 'false', 'off', self::normalise( __( 'No', 'products-stock-manager-with-excel' ) ) ), true ) ) {
				return false;
			}
			return null;
		}

		/**
		 * Number from a cell: "1,234.50", "1.234,50", "12 €", "$12". Null when not a number.
		 *
		 * @param mixed $value Cell.
		 * @return float|null
		 */
		public static function parse_number( $value ) {
			if ( is_int( $value ) || is_float( $value ) ) {
				return (float) $value;
			}
			$s = trim( (string) $value );
			$s = preg_replace( '/[^\d,.\-]/u', '', $s );
			if ( '' === $s || '-' === $s ) {
				return null;
			}
			$last_comma = strrpos( $s, ',' );
			$last_dot   = strrpos( $s, '.' );
			if ( false !== $last_comma && false !== $last_dot ) {
				if ( $last_comma > $last_dot ) {
					$s = str_replace( '.', '', $s );
					$s = str_replace( ',', '.', $s );
				} else {
					$s = str_replace( ',', '', $s );
				}
			} elseif ( false !== $last_comma ) {
				// "12,5" is a decimal, "1,250" is a thousand.
				$s = ( strlen( $s ) - $last_comma - 1 === 3 && substr_count( $s, ',' ) >= 1 && strlen( $s ) > 4 ) ? str_replace( ',', '', $s ) : str_replace( ',', '.', $s );
			}
			return is_numeric( $s ) ? (float) $s : null;
		}
	}

endif;
