<?php
/**
 * Stock Manager engine: turns a file into a checked plan, writes the plan through WooCommerce, restores backups.
 *
 * Steps of a run (state):
 *   new        file read, rows stored, columns not confirmed yet
 *   resolving  rows matched to products, values read (cursor over row chunks)
 *   merging    rows of the same product joined, items made
 *   comparing  every item compared with the product as it is now (cursor over item chunks)
 *   planned    preview ready, nothing written
 *   writing    confirmed: backup then write, chunk by chunk
 *   done       finished (results and backup kept), or failed
 * Each call to step() does as much as fits in a few seconds, so 10,000 rows never hit a PHP time limit.
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPF_SM_Engine' ) ) :

	/**
	 * Engine.
	 */
	class WPF_SM_Engine {

		const ITEMS = 100;

		/**
		 * Default choices for a run (the Settings screen stores the site's defaults).
		 *
		 * @return array
		 */
		public static function default_options() {
			return array(
				'stock_mode'     => 'set',   // set or add.
				'duplicates'     => 'last',  // last or sum.
				'untracked'      => 'skip',  // skip or track.
				'missing'        => 'leave', // leave, outofstock or zero (Pro offers the choice).
				'price_warn'     => 50,      // Percent.
				'zero_regular'   => 'skip',  // skip (as before) or save.
				'header_line'    => 1,
				'legacy'         => false,
			);
		}

		/**
		 * Create a run from a file on disk.
		 *
		 * @param string $path  File.
		 * @param string $name  Original name.
		 * @param array  $extra Run data (source, feed, options, map).
		 * @return int|WP_Error Run id.
		 */
		public static function start( $path, $name, $extra = array() ) {
			$read = WPF_SM_Reader::read( $path, $name );
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$rows        = $read['rows'];
			$header_line = (int) key( $rows );
			$header      = reset( $rows );
			$map         = isset( $extra['map'] ) && is_array( $extra['map'] ) ? $extra['map'] : WPF_SM_Fields::map_headers( $header );
			/**
			 * Column mapping a new run starts with (Pro: saved column letters of 4.0.x, supplier feed columns).
			 *
			 * @param array $map    Column index => field key, from the header names.
			 * @param array $header Header cells.
			 * @param array $extra  Run data (source, feed).
			 */
			$map         = apply_filters( 'wpfactory_wc_sm_start_map', $map, (array) $header, $extra );
			$legacy      = false;
			if ( empty( $extra['map'] ) && ! WPF_SM_Fields::usable( $map ) ) {
				// No headers we know: the old fixed layout, and row 1 is skipped as the old versions did.
				$map         = apply_filters( 'wpfactory_wc_sm_legacy_map', WPF_SM_Fields::legacy_map() );
				$legacy      = true;
				$header_line = 1;
			}
			$options = wp_parse_args( isset( $extra['options'] ) ? $extra['options'] : array(), self::site_options() );
			$options['header_line'] = $header_line;
			$options['legacy']      = $legacy;
			unset( $extra['options'], $extra['map'] );
			$id = WPF_SM_Runs::create(
				$name,
				array_merge(
					array(
						'state'   => 'new',
						'source'  => 'upload',
						'kind'    => $read['kind'],
						'header'  => array_values( (array) $header ),
						'map'     => $map,
						'options' => $options,
						'lines'   => count( $rows ) - ( isset( $rows[ $header_line ] ) ? 1 : 0 ),
						'samples' => array_values( array_slice( $rows, 1, 3 ) ),
					),
					$extra
				)
			);
			if ( ! $id ) {
				return new WP_Error( 'wpf_sm_store', __( 'The update could not be saved in the database.', 'products-stock-manager-with-excel' ) );
			}
			$n = 0;
			foreach ( array_chunk( $rows, WPF_SM_Runs::CHUNK, true ) as $chunk ) {
				$pack = array();
				foreach ( $chunk as $line => $cells ) {
					$pack[] = array( (int) $line, array_values( $cells ) );
				}
				WPF_SM_Runs::put( $id, 'rows', $n++, $pack );
			}
			WPF_SM_Runs::update( $id, array( 'row_chunks' => $n ) );
			return $id;
		}

		/**
		 * The site's default choices (Settings screen, old options kept).
		 *
		 * @return array
		 */
		public static function site_options() {
			$o = get_option( 'wpfactory_wc_sm_settings', array() );
			$o = is_array( $o ) ? $o : array();
			$d = self::default_options();
			if ( ! isset( $o['duplicates'] ) ) {
				// Old free option "Add stock for same product from multiple Excel rows".
				$d['duplicates'] = get_option( 'stockManagerWooCommerceaddStockNumber' ) ? 'sum' : 'last';
			}
			return apply_filters( 'wpfactory_wc_sm_site_options', wp_parse_args( $o, $d ) );
		}

		/**
		 * Confirm columns and choices, then start checking.
		 *
		 * @param int   $id      Run id.
		 * @param array $map     Column index => field key.
		 * @param array $options Choices.
		 * @return array Run.
		 */
		public static function confirm_columns( $id, $map, $options = array() ) {
			$run  = WPF_SM_Runs::get( $id );
			$opts = wp_parse_args( $options, $run['options'] );
			foreach ( array( 'resolve', 'items', 'plan', 'result', 'backup' ) as $kind ) {
				WPF_SM_Runs::drop( $id, $kind );
			}
			return WPF_SM_Runs::update(
				$id,
				array(
					'state'   => 'resolving',
					'map'     => $map,
					'options' => $opts,
					'cursor'  => 0,
					'counts'  => array(),
				)
			);
		}

		/**
		 * Do work for a few seconds. Returns the run with 'progress' (0 to 100).
		 *
		 * @param int   $id     Run id.
		 * @param float $budget Seconds.
		 * @return array
		 */
		public static function step( $id, $budget = 8.0 ) {
			$start = microtime( true );
			wc_set_time_limit( 120 );
			do {
				$run = WPF_SM_Runs::get( $id );
				if ( ! $run ) {
					return array( 'state' => 'missing', 'progress' => 100 );
				}
				switch ( $run['state'] ) {
					case 'resolving':
						$run = self::resolve_chunk( $run );
						break;
					case 'merging':
						$run = self::merge( $run );
						break;
					case 'comparing':
						$run = self::compare_chunk( $run );
						break;
					case 'writing':
						$run = self::write_chunk( $run );
						break;
					default:
						break 2;
				}
			} while ( microtime( true ) - $start < $budget );
			$run['progress'] = self::progress( $run );
			return $run;
		}

		/**
		 * Progress in percent for the bar.
		 *
		 * @param array $run Run.
		 * @return int
		 */
		public static function progress( $run ) {
			$c = isset( $run['cursor'] ) ? (int) $run['cursor'] : 0;
			switch ( $run['state'] ) {
				case 'resolving':
					return (int) min( 40, 40 * $c / max( 1, (int) $run['row_chunks'] ) );
				case 'merging':
					return 45;
				case 'comparing':
					return (int) min( 99, 50 + 50 * $c / max( 1, (int) $run['item_chunks'] ) );
				case 'writing':
					return (int) min( 99, 100 * $c / max( 1, (int) $run['item_chunks'] ) );
				default:
					return 100;
			}
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* 1. Resolve: read values, find products.                                                                 */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * One chunk of rows.
		 *
		 * @param array $run Run.
		 * @return array
		 */
		protected static function resolve_chunk( $run ) {
			$id     = $run['id'];
			$n      = (int) $run['cursor'];
			$rows   = WPF_SM_Runs::chunk( $id, 'rows', $n );
			$map    = array_map( 'strval', (array) $run['map'] );
			$opts   = $run['options'];
			$out    = array();
			$skus   = array();
			$gtins  = array();
			$parsed = array();
			foreach ( $rows as $pack ) {
				list( $line, $cells ) = $pack;
				if ( (int) $line === (int) $opts['header_line'] ) {
					continue;
				}
				$entry = array(
					'line'   => (int) $line,
					'keys'   => array(),
					'values' => array(),
					'bad'    => array(),
					'info'   => array(),
				);
				foreach ( $map as $col => $key ) {
					if ( '' === $key ) {
						continue;
					}
					$field = WPF_SM_Fields::get( $key );
					$raw   = isset( $cells[ $col ] ) ? trim( (string) $cells[ $col ] ) : '';
					if ( ! $field || '' === $raw ) {
						continue;
					}
					if ( 'key' === $field['kind'] ) {
						$entry['keys'][ $key ] = $raw;
					} elseif ( 'info' === $field['kind'] ) {
						$entry['info'][ $key ] = $raw;
					} else {
						$v = self::parse_value( $key, $field, $raw, $opts );
						if ( is_array( $v ) && isset( $v['error'] ) ) {
							$entry['bad'][ $key ] = array( $v['error'], $raw );
						} elseif ( null !== $v ) {
							$entry['values'][ $key ] = $v;
						}
					}
				}
				if ( isset( $entry['keys']['sku'] ) ) {
					$skus[] = $entry['keys']['sku'];
				}
				if ( isset( $entry['keys']['gtin'] ) ) {
					$gtins[] = $entry['keys']['gtin'];
				}
				$parsed[] = $entry;
			}
			$by_sku  = self::lookup( '_sku', $skus );
			$by_gtin = self::lookup( '_global_unique_id', $gtins );
			foreach ( $parsed as $entry ) {
				$entry['pid']   = 0;
				$entry['via']   = '';
				$entry['notes'] = array();
				$id_raw         = isset( $entry['keys']['id'] ) ? $entry['keys']['id'] : '';
				$sku            = isset( $entry['keys']['sku'] ) ? $entry['keys']['sku'] : '';
				$gtin           = isset( $entry['keys']['gtin'] ) ? $entry['keys']['gtin'] : '';
				if ( '' !== $id_raw ) {
					$pid = ctype_digit( (string) $id_raw ) ? (int) $id_raw : (int) WPF_SM_Fields::parse_number( $id_raw );
					if ( $pid > 0 && self::is_product( $pid ) ) {
						$entry['pid'] = $pid;
						$entry['via'] = 'id';
						$other        = '' !== $sku && isset( $by_sku[ strtolower( $sku ) ] ) ? $by_sku[ strtolower( $sku ) ] : 0;
						if ( $other && $other !== $pid ) {
							$entry['notes'][] = array( 'sku_other', $sku );
						}
					} elseif ( '' === $sku && '' === $gtin ) {
						$entry['notes'][] = array( $pid > 0 && get_post( $pid ) ? 'not_product' : 'no_id', $id_raw );
					} else {
						$entry['notes'][] = array( 'id_fallback', $id_raw );
					}
				}
				if ( ! $entry['pid'] && '' !== $sku ) {
					if ( isset( $by_sku[ strtolower( $sku ) ] ) ) {
						$entry['pid'] = $by_sku[ strtolower( $sku ) ];
						$entry['via'] = 'sku';
					} elseif ( '' === $gtin ) {
						$entry['notes'][] = array( 'no_sku', $sku );
					}
				}
				if ( ! $entry['pid'] && '' !== $gtin ) {
					if ( isset( $by_gtin[ strtolower( $gtin ) ] ) ) {
						$entry['pid'] = $by_gtin[ strtolower( $gtin ) ];
						$entry['via'] = 'gtin';
					} else {
						$entry['notes'][] = array( 'no_gtin', $gtin );
					}
				}
				if ( '' === $id_raw && '' === $sku && '' === $gtin ) {
					$entry['notes'][] = array( 'no_key' );
				}
				/**
				 * Lets an add-on match a row another way (aliases, supplier codes). Return a product id or 0.
				 *
				 * @param int   $pid   Product id found (0 = none).
				 * @param array $entry Row: line, keys, values, info.
				 * @param array $run   Run.
				 */
				$pid = (int) apply_filters( 'wpfactory_wc_sm_match_row', $entry['pid'], $entry, $run );
				if ( $pid !== $entry['pid'] && $pid > 0 && self::is_product( $pid ) ) {
					$entry['pid']   = $pid;
					$entry['via']   = 'alias';
					$entry['notes'] = array_values(
						array_filter(
							$entry['notes'],
							function ( $n ) {
								return ! in_array( $n[0], array( 'no_sku', 'no_gtin', 'no_id', 'id_fallback' ), true );
							}
						)
					);
				}
				unset( $entry['keys']['_'] );
				$out[] = $entry;
			}
			WPF_SM_Runs::put( $run['id'], 'resolve', $n, $out );
			++$n;
			$data = array( 'cursor' => $n );
			if ( $n >= (int) $run['row_chunks'] ) {
				$data = array(
					'state'  => 'merging',
					'cursor' => 0,
				);
			}
			return WPF_SM_Runs::update( $run['id'], $data );
		}

		/**
		 * A value from a cell, or array( 'error' => code ), or null for "no change".
		 *
		 * @param string $key   Field key.
		 * @param array  $field Field.
		 * @param string $raw   Cell.
		 * @param array  $opts  Run choices.
		 * @return mixed
		 */
		protected static function parse_value( $key, $field, $raw, $opts ) {
			$custom = apply_filters( 'wpfactory_wc_sm_parse_value', null, $key, $field, $raw, $opts );
			if ( null !== $custom ) {
				return $custom;
			}
			switch ( $field['kind'] ) {
				case 'number':
					$n = WPF_SM_Fields::parse_number( $raw );
					if ( null === $n ) {
						return array( 'error' => 'not_number' );
					}
					if ( $n < 0 && ( 'stock' !== $key || 'add' !== $opts['stock_mode'] ) ) {
						return array( 'error' => 'negative' );
					}
					return 'stock' === $key ? wc_stock_amount( $n ) : (int) round( $n );
				case 'price':
					$n = WPF_SM_Fields::parse_number( $raw );
					if ( null === $n ) {
						return array( 'error' => 'not_number' );
					}
					if ( $n < 0 ) {
						return array( 'error' => 'negative' );
					}
					if ( 'regular_price' === $key && 0.0 === (float) $n && 'skip' === $opts['zero_regular'] ) {
						return array( 'error' => 'zero_regular' );
					}
					return wc_format_decimal( $n );
				case 'status':
					$s = WPF_SM_Fields::parse_status( $raw );
					return null === $s ? array( 'error' => 'not_status' ) : $s;
				case 'bool':
					$b = WPF_SM_Fields::parse_bool( $raw );
					return null === $b ? array( 'error' => 'not_bool' ) : ( $b ? 'yes' : 'no' );
				case 'date':
					$t = strtotime( $raw );
					if ( is_numeric( $raw ) && (float) $raw > 20000 && (float) $raw < 80000 ) {
						$t = (int) round( ( (float) $raw - 25569 ) * DAY_IN_SECONDS ); // Excel serial date.
					}
					return $t ? gmdate( 'Y-m-d', $t ) : array( 'error' => 'not_date' );
				default:
					return sanitize_text_field( $raw );
			}
		}

		/**
		 * Product ids by meta value (SKU or GTIN), lower-cased keys. Products and variations only, not in the trash.
		 *
		 * @param string $meta   Meta key.
		 * @param array  $values Values.
		 * @return array
		 */
		protected static function lookup( $meta, $values ) {
			global $wpdb;
			$values = array_values( array_unique( array_filter( array_map( 'strval', $values ), 'strlen' ) ) );
			$out    = array();
			foreach ( array_chunk( $values, 400 ) as $part ) {
				$in   = implode( ',', array_fill( 0, count( $part ), '%s' ) );
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value IN ($in) AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') ORDER BY pm.post_id", array_merge( array( $meta ), $part ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $rows as $r ) {
					$k = strtolower( trim( $r->meta_value ) );
					if ( ! isset( $out[ $k ] ) ) {
						$out[ $k ] = (int) $r->post_id;
					}
				}
			}
			return $out;
		}

		/**
		 * Whether an id is a product or variation not in the trash.
		 *
		 * @param int $pid Id.
		 * @return bool
		 */
		protected static function is_product( $pid ) {
			$type   = get_post_type( $pid );
			$status = get_post_status( $pid );
			return in_array( $type, array( 'product', 'product_variation' ), true ) && ! in_array( $status, array( 'trash', 'auto-draft' ), true );
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* 2. Merge rows of the same product, add "not in the file" items.                                         */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Merge.
		 *
		 * @param array $run Run.
		 * @return array
		 */
		protected static function merge( $run ) {
			$id      = $run['id'];
			$opts    = $run['options'];
			$entries = WPF_SM_Runs::all( $id, 'resolve' );
			$items   = array();
			$index   = array();
			$rows    = 0;
			$matched = 0;
			foreach ( $entries as $e ) {
				++$rows;
				if ( ! $e['pid'] ) {
					$items[] = array(
						'lines'  => array( $e['line'] ),
						'pid'    => 0,
						'values' => array(),
						'bad'    => $e['bad'],
						'notes'  => $e['notes'],
						'info'   => $e['info'],
						'keys'   => $e['keys'],
					);
					continue;
				}
				++$matched;
				$pid = (int) $e['pid'];
				if ( ! isset( $index[ $pid ] ) ) {
					$index[ $pid ] = count( $items );
					$items[]       = array(
						'lines'  => array( $e['line'] ),
						'pid'    => $pid,
						'via'    => $e['via'],
						'values' => $e['values'],
						'bad'    => $e['bad'],
						'notes'  => $e['notes'],
						'info'   => $e['info'],
						'keys'   => $e['keys'],
					);
					continue;
				}
				$i                     = $index[ $pid ];
				$items[ $i ]['lines'][] = $e['line'];
				foreach ( $e['values'] as $k => $v ) {
					if ( 'stock' === $k && 'sum' === $opts['duplicates'] && isset( $items[ $i ]['values']['stock'] ) ) {
						$items[ $i ]['values']['stock'] = wc_stock_amount( $items[ $i ]['values']['stock'] + $v );
					} else {
						$items[ $i ]['values'][ $k ] = $v; // The last row wins, as before.
					}
					unset( $items[ $i ]['bad'][ $k ] );
				}
				$items[ $i ]['bad']   = array_merge( $items[ $i ]['bad'], $e['bad'] );
				$items[ $i ]['notes'] = array_merge( $items[ $i ]['notes'], $e['notes'] );
			}
			foreach ( $items as $i => $item ) {
				if ( count( $item['lines'] ) > 1 ) {
					$items[ $i ]['notes'][] = array( 'sum' === $opts['duplicates'] ? 'dup_sum' : 'dup_last', implode( ', ', $item['lines'] ) );
				}
			}
			$missing_note = '';
			if ( 'leave' !== $opts['missing'] ) {
				if ( 0 === $matched ) {
					$missing_note = 'missing_none';
				} elseif ( $rows > 0 && ( $rows - $matched ) / $rows > 0.5 ) {
					$missing_note = 'missing_many';
				} else {
					foreach ( self::missing_ids( array_keys( $index ), $opts['missing'] ) as $pid ) {
						$items[] = array(
							'lines'   => array(),
							'pid'     => $pid,
							'via'     => 'missing',
							'values'  => 'zero' === $opts['missing'] || self::manages_stock( $pid ) ? array( 'stock' => 0 ) : array( 'stock_status' => 'outofstock' ),
							'bad'     => array(),
							'notes'   => array( array( 'missing_' . $opts['missing'] ) ),
							'info'    => array(),
							'keys'    => array(),
							'missing' => 1,
						);
					}
				}
			}
			WPF_SM_Runs::drop( $id, 'items' );
			$n = 0;
			foreach ( array_chunk( $items, self::ITEMS ) as $chunk ) {
				WPF_SM_Runs::put( $id, 'items', $n++, $chunk );
			}
			WPF_SM_Runs::drop( $id, 'resolve' );
			return WPF_SM_Runs::update(
				$id,
				array(
					'state'        => $n ? 'comparing' : 'planned',
					'cursor'       => 0,
					'item_chunks'  => $n,
					'rows_read'    => $rows,
					'rows_matched' => $matched,
					'missing_note' => $missing_note,
					'counts'       => array(
						'ready' => 0,
						'check' => 0,
						'skip'  => 0,
						'same'  => 0,
					),
				)
			);
		}

		/**
		 * Products and variations not in the file that the "items not in the file" rule may change: they track stock
		 * (for "zero"), or are simple products and variations (for "out of stock"). Never variable parents, grouped,
		 * external products or services that do not track stock.
		 *
		 * @param array  $seen Product ids in the file.
		 * @param string $rule outofstock or zero.
		 * @return array
		 */
		protected static function missing_ids( $seen, $rule ) {
			global $wpdb;
			$sql  = "SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock'
				WHERE p.post_status IN ('publish','private','draft','pending','future')
				AND ( p.post_type = 'product_variation' OR ( p.post_type = 'product' AND EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug = 'simple' ) ) )";
			if ( 'zero' === $rule ) {
				$sql .= " AND ms.meta_value = 'yes'";
			} else {
				$sql .= " AND ( ms.meta_value = 'yes' OR NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} v WHERE v.post_id = p.ID AND v.meta_key = '_virtual' AND v.meta_value = 'yes' ) )";
			}
			$ids  = array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$seen = array_flip( array_map( 'intval', $seen ) );
			return array_values(
				array_filter(
					$ids,
					function ( $pid ) use ( $seen ) {
						return ! isset( $seen[ $pid ] );
					}
				)
			);
		}

		/**
		 * Whether a product tracks stock.
		 *
		 * @param int $pid Id.
		 * @return bool
		 */
		protected static function manages_stock( $pid ) {
			return 'yes' === get_post_meta( $pid, '_manage_stock', true );
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* 3. Compare with the store: the preview.                                                                 */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * One chunk of items.
		 *
		 * @param array $run Run.
		 * @return array
		 */
		protected static function compare_chunk( $run ) {
			$n      = (int) $run['cursor'];
			$items  = WPF_SM_Runs::chunk( $run['id'], 'items', $n );
			$plan   = array();
			$counts = $run['counts'];
			foreach ( $items as $item ) {
				$entry = self::compare_item( $item, $run );
				++$counts[ $entry['status'] ];
				$plan[] = $entry;
			}
			WPF_SM_Runs::put( $run['id'], 'plan', $n, $plan );
			++$n;
			$data = array(
				'cursor' => $n,
				'counts' => $counts,
			);
			if ( $n >= (int) $run['item_chunks'] ) {
				$data['state']  = 'planned';
				$data['cursor'] = 0;
			}
			return WPF_SM_Runs::update( $run['id'], $data );
		}

		/**
		 * Current values of the fields a plan may change.
		 *
		 * @param WC_Product $p      Product.
		 * @param array      $fields Field keys.
		 * @return array
		 */
		public static function current( $p, $fields ) {
			$out = array();
			foreach ( $fields as $key ) {
				switch ( $key ) {
					case 'stock':
						$q           = $p->get_stock_quantity( 'edit' );
						$out[ $key ] = null === $q ? '' : wc_stock_amount( $q );
						break;
					case 'stock_status':
						$out[ $key ] = $p->get_stock_status( 'edit' );
						break;
					case 'regular_price':
						$out[ $key ] = (string) $p->get_regular_price( 'edit' );
						break;
					case 'sale_price':
						$out[ $key ] = (string) $p->get_sale_price( 'edit' );
						break;
					case 'low_stock_amount':
						$out[ $key ] = (string) $p->get_low_stock_amount( 'edit' );
						break;
					case 'manage_stock':
						$out[ $key ] = $p->get_manage_stock( 'edit' ) ? 'yes' : 'no';
						break;
					case 'date_on_sale_from':
					case 'date_on_sale_to':
						$d           = 'date_on_sale_from' === $key ? $p->get_date_on_sale_from( 'edit' ) : $p->get_date_on_sale_to( 'edit' );
						$out[ $key ] = $d ? $d->date( 'Y-m-d H:i:s' ) : '';
						break;
					default:
						$out[ $key ] = apply_filters( 'wpfactory_wc_sm_current_value', '', $key, $p );
				}
			}
			return $out;
		}

		/**
		 * Plan entry for one item.
		 *
		 * @param array $item Item.
		 * @param array $run  Run.
		 * @return array
		 */
		protected static function compare_item( $item, $run ) {
			$opts  = $run['options'];
			$entry = array(
				'lines'   => $item['lines'],
				'pid'     => (int) $item['pid'],
				'parent'  => 0,
				'name'    => isset( $item['info']['name'] ) ? $item['info']['name'] : '',
				'sku'     => isset( $item['keys']['sku'] ) ? $item['keys']['sku'] : ( isset( $item['keys']['id'] ) ? '#' . $item['keys']['id'] : ( isset( $item['keys']['gtin'] ) ? $item['keys']['gtin'] : '' ) ),
				'type'    => '',
				'status'  => 'skip',
				'changes' => array(),
				'notes'   => $item['notes'],
			);
			if ( ! empty( $item['missing'] ) ) {
				$entry['missing'] = 1;
			}
			foreach ( $item['bad'] as $key => $bad ) {
				$entry['notes'][] = array( $bad[0], $key, $bad[1] );
			}
			if ( ! $entry['pid'] ) {
				return $entry;
			}
			$p = wc_get_product( $entry['pid'] );
			if ( ! $p ) {
				$entry['notes'][] = array( 'not_product', $entry['pid'] );
				return $entry;
			}
			$type            = $p->get_type();
			$entry['type']   = $type;
			$entry['name']   = $p->get_name();
			$entry['sku']    = $p->get_sku() ? $p->get_sku() : '#' . $p->get_id();
			$entry['parent'] = $p->get_parent_id();
			if ( 'grouped' === $type ) {
				$entry['notes'][] = array( 'grouped' );
				return $entry;
			}
			$values = $item['values'];
			if ( ! empty( $item['restore'] ) ) {
				return self::compare_restore( $entry, $p, $values );
			}
			$base_type = $p->is_type( 'variation' ) ? 'variation' : ( $p->is_type( 'variable' ) ? 'variable' : ( $p->is_type( 'external' ) ? 'external' : 'simple' ) );
			foreach ( array_keys( $values ) as $key ) {
				$field = WPF_SM_Fields::get( $key );
				if ( ! $field ) {
					unset( $values[ $key ] );
					continue;
				}
				$applies = isset( $field['applies'] ) ? $field['applies'] : array();
				if ( $applies && ! in_array( $base_type, $applies, true ) ) {
					$entry['notes'][] = array( 'not_for_type', $key, $type );
					unset( $values[ $key ] );
				}
			}
			$manage = isset( $values['manage_stock'] ) ? 'yes' === $values['manage_stock'] : $p->get_manage_stock();
			if ( isset( $values['stock'] ) && ! $manage ) {
				if ( 'track' === $opts['untracked'] ) {
					$values['manage_stock'] = 'yes';
					$manage                 = true;
					$entry['notes'][]       = array( 'track_on' );
				} else {
					$entry['notes'][] = array( 'untracked', $values['stock'] );
					unset( $values['stock'] );
				}
			}
			if ( isset( $values['stock_status'] ) && $manage ) {
				if ( ! isset( $values['stock'] ) || $values['stock_status'] !== $p->get_stock_status( 'edit' ) ) {
					$entry['notes'][] = array( 'status_follows', $values['stock_status'] );
				}
				unset( $values['stock_status'] );
			}
			if ( isset( $values['low_stock_amount'] ) && ! $manage ) {
				unset( $values['low_stock_amount'] );
			}
			$now     = self::current( $p, array_keys( $values ) );
			$changes = array();
			foreach ( $values as $key => $new ) {
				$old = isset( $now[ $key ] ) ? $now[ $key ] : '';
				if ( 'stock' === $key && 'add' === $opts['stock_mode'] && empty( $item['missing'] ) ) {
					$add = wc_stock_amount( $new );
					if ( 0.0 === (float) $add ) {
						continue;
					}
					$new = wc_stock_amount( ( '' === $old ? 0 : $old ) + $add );
					if ( $new < 0 ) {
						$entry['notes'][] = array( 'below_zero', $old, $add );
					}
					$changes[ $key ] = array( $old, $new, $add );
					continue;
				}
				if ( 'sale_price' === $key && 0.0 === (float) $new ) {
					$new = ''; // A sale price of 0 removes the sale, as before.
				}
				if ( self::same( $key, $old, $new ) ) {
					continue;
				}
				$changes[ $key ] = array( $old, $new );
			}
			/**
			 * Change what a row will write. Return the changes array: field key => array( old, new ).
			 *
			 * @param array      $changes Planned changes.
			 * @param WC_Product $p       Product.
			 * @param array      $item    Row values.
			 * @param array      $run     Run.
			 */
			$changes = apply_filters( 'wpfactory_wc_sm_row_changes', $changes, $p, $item, $run );
			// Warnings ("Check").
			$warn = false;
			$reg  = isset( $changes['regular_price'] ) ? $changes['regular_price'][1] : $p->get_regular_price( 'edit' );
			$sale = isset( $changes['sale_price'] ) ? $changes['sale_price'][1] : $p->get_sale_price( 'edit' );
			if ( ( isset( $changes['regular_price'] ) || isset( $changes['sale_price'] ) ) && '' !== (string) $sale && '' !== (string) $reg && (float) $sale >= (float) $reg ) {
				$entry['notes'][] = array( 'sale_high', $sale, $reg );
				$warn             = true;
			}
			foreach ( array( 'regular_price', 'sale_price' ) as $key ) {
				if ( isset( $changes[ $key ] ) && '' !== (string) $changes[ $key ][0] && (float) $changes[ $key ][0] > 0 && '' !== (string) $changes[ $key ][1] ) {
					$pct = abs( (float) $changes[ $key ][1] - (float) $changes[ $key ][0] ) / (float) $changes[ $key ][0] * 100;
					if ( $pct > (float) $opts['price_warn'] ) {
						$entry['notes'][] = array( 'price_jump', $key, round( $pct ) );
						$warn             = true;
					}
				}
			}
			if ( isset( $changes['stock'] ) && empty( $item['missing'] ) ) {
				$o = (float) ( '' === $changes['stock'][0] ? 0 : $changes['stock'][0] );
				$v = (float) $changes['stock'][1];
				if ( $v - $o >= 100 && $v > 10 * max( 1, $o ) ) {
					$entry['notes'][] = array( 'stock_jump', $o, $v );
					$warn             = true;
				}
				if ( $v < 0 ) {
					$warn = true;
				}
			}
			foreach ( $entry['notes'] as $note ) {
				if ( in_array( $note[0], array( 'sku_other', 'id_fallback', 'dup_last', 'untracked', 'status_follows' ), true ) ) {
					$warn = true;
				}
			}
			$entry['changes'] = $changes;
			if ( $changes ) {
				$entry['status'] = $warn ? 'check' : 'ready';
			} else {
				$entry['status'] = $item['bad'] || self::has_skip_note( $entry['notes'] ) ? 'skip' : 'same';
			}
			return $entry;
		}

		/**
		 * Notes that mean the row did nothing because of a problem.
		 *
		 * @param array $notes Notes.
		 * @return bool
		 */
		protected static function has_skip_note( $notes ) {
			foreach ( $notes as $n ) {
				if ( in_array( $n[0], array( 'not_for_type', 'untracked', 'status_follows', 'grouped', 'not_product' ), true ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether two values are the same for a field.
		 *
		 * @param string $key Field.
		 * @param mixed  $a   Old.
		 * @param mixed  $b   New.
		 * @return bool
		 */
		protected static function same( $key, $a, $b ) {
			if ( '' === (string) $a || '' === (string) $b ) {
				return (string) $a === (string) $b;
			}
			if ( is_numeric( $a ) && is_numeric( $b ) ) {
				return abs( (float) $a - (float) $b ) < 0.00001;
			}
			return (string) $a === (string) $b;
		}

		/**
		 * Restore entry: every field that differs from the backup goes back, no warnings.
		 *
		 * @param array      $entry  Entry.
		 * @param WC_Product $p      Product.
		 * @param array      $values Backup values.
		 * @return array
		 */
		protected static function compare_restore( $entry, $p, $values ) {
			$now = self::current( $p, array_keys( $values ) );
			foreach ( $values as $key => $old_value ) {
				$cur = isset( $now[ $key ] ) ? $now[ $key ] : '';
				if ( ! self::same( $key, $cur, $old_value ) ) {
					$entry['changes'][ $key ] = array( $cur, $old_value );
				}
			}
			$entry['restore'] = 1;
			$entry['status']  = $entry['changes'] ? 'ready' : 'same';
			return $entry;
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* 4. Write: backup, then save through WooCommerce.                                                        */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Start writing a planned run.
		 *
		 * @param int   $id      Run id.
		 * @param array $exclude Product ids the merchant unticked.
		 * @return array
		 */
		public static function confirm( $id, $exclude = array() ) {
			$run  = WPF_SM_Runs::get( $id );
			$keep = (int) self::site_options_value( 'keep_days', 30 );
			return WPF_SM_Runs::update(
				$id,
				array(
					'state'     => 'writing',
					'cursor'    => 0,
					'skip_ids'  => array_map( 'intval', (array) $exclude ),
					'started'   => time(),
					'by'        => get_current_user_id(),
					'expires'   => time() + max( 1, $keep ) * DAY_IN_SECONDS,
					'written'   => 0,
					'failed'    => 0,
				)
			);
		}

		/**
		 * One site setting.
		 *
		 * @param string $key     Key.
		 * @param mixed  $default Default.
		 * @return mixed
		 */
		public static function site_options_value( $key, $default ) {
			$o = get_option( 'wpfactory_wc_sm_settings', array() );
			return is_array( $o ) && isset( $o[ $key ] ) ? $o[ $key ] : $default;
		}

		/**
		 * Write one chunk of the plan.
		 *
		 * @param array $run Run.
		 * @return array
		 */
		protected static function write_chunk( $run ) {
			$n       = (int) $run['cursor'];
			$plan    = WPF_SM_Runs::chunk( $run['id'], 'plan', $n );
			$exclude = array_flip( isset( $run['skip_ids'] ) ? (array) $run['skip_ids'] : array() );
			$backup  = array();
			$result  = array();
			$parents = array();
			$written = (int) $run['written'];
			$failed  = (int) $run['failed'];
			$mode    = $run['options']['stock_mode'];
			wp_defer_term_counting( true );
			foreach ( $plan as $i => $entry ) {
				if ( ! in_array( $entry['status'], array( 'ready', 'check' ), true ) || isset( $exclude[ $entry['pid'] ] ) ) {
					continue;
				}
				$r = self::write_entry( $entry, $mode, $run );
				if ( is_wp_error( $r ) ) {
					$result[ $i ] = array( 'failed', $r->get_error_message() );
					++$failed;
					continue;
				}
				$backup[]     = array( $entry['pid'], $r['backup'] );
				$result[ $i ] = array( 'written', $r['after'] );
				++$written;
				if ( $entry['parent'] ) {
					$parents[ $entry['parent'] ] = true;
				}
			}
			wp_defer_term_counting( false );
			foreach ( array_keys( $parents ) as $parent ) {
				$pp = wc_get_product( $parent );
				if ( $pp && $pp->is_type( 'variable' ) ) {
					WC_Product_Variable::sync( $parent );
					wc_delete_product_transients( $parent );
				}
			}
			WPF_SM_Runs::put( $run['id'], 'backup', $n, $backup );
			WPF_SM_Runs::put( $run['id'], 'result', $n, $result );
			++$n;
			$data = array(
				'cursor'  => $n,
				'written' => $written,
				'failed'  => $failed,
			);
			if ( $n >= (int) $run['item_chunks'] ) {
				$data['state']    = 'done';
				$data['finished'] = time();
				$data['cursor']   = 0;
				WPF_SM_Runs::drop( $run['id'], 'items' );
				WPF_SM_Runs::drop( $run['id'], 'rows' );
			}
			$run = WPF_SM_Runs::update( $run['id'], $data );
			if ( 'done' === $run['state'] ) {
				if ( ! empty( $run['restore_of'] ) ) {
					WPF_SM_Runs::update( (int) $run['restore_of'], array( 'restored_by' => $run['id'] ) );
				}
				/**
				 * An update finished.
				 *
				 * @param array $run Run (counts, written, failed, source).
				 */
				do_action( 'wpfactory_wc_sm_run_done', $run );
			}
			return $run;
		}

		/**
		 * Write one product. Returns backup and the values after saving, or WP_Error.
		 *
		 * @param array  $entry Plan entry.
		 * @param string $mode  set or add.
		 * @param array  $run   Run.
		 * @return array|WP_Error
		 */
		protected static function write_entry( $entry, $mode, $run ) {
			try {
				$p = wc_get_product( $entry['pid'] );
				if ( ! $p ) {
					return new WP_Error( 'gone', __( 'The product no longer exists.', 'products-stock-manager-with-excel' ) );
				}
				$changes = $entry['changes'];
				$fields  = array_keys( $changes );
				if ( isset( $changes['stock'] ) || isset( $changes['manage_stock'] ) ) {
					$fields = array_merge( $fields, array( 'stock', 'manage_stock', 'stock_status' ) );
				}
				if ( isset( $changes['sale_price'] ) || isset( $changes['regular_price'] ) ) {
					$fields = array_merge( $fields, array( 'regular_price', 'sale_price' ) );
				}
				$fields = array_values( array_unique( $fields ) );
				$before = self::current( $p, $fields );
				if ( isset( $changes['sale_price'] ) ) {
					$before['date_on_sale_from'] = $p->get_date_on_sale_from( 'edit' ) ? $p->get_date_on_sale_from( 'edit' )->date( 'Y-m-d H:i:s' ) : '';
					$before['date_on_sale_to']   = $p->get_date_on_sale_to( 'edit' ) ? $p->get_date_on_sale_to( 'edit' )->date( 'Y-m-d H:i:s' ) : '';
				}
				/**
				 * Before a product is written (custom row handlers).
				 *
				 * @param WC_Product $p     Product.
				 * @param array      $entry Plan entry (changes: field => array( old, new )).
				 * @param array      $run   Run.
				 */
				do_action( 'wpfactory_wc_sm_before_row', $p, $entry, $run );
				$add = null;
				if ( isset( $changes['manage_stock'] ) ) {
					$p->set_manage_stock( 'yes' === $changes['manage_stock'][1] );
				}
				foreach ( $changes as $key => $change ) {
					$new = $change[1];
					switch ( $key ) {
						case 'manage_stock':
							break;
						case 'stock':
							if ( 'add' === $mode && isset( $change[2] ) && empty( $entry['restore'] ) && empty( $entry['missing'] ) ) {
								$add = wc_stock_amount( $change[2] );
							} else {
								$p->set_stock_quantity( $new );
							}
							break;
						case 'stock_status':
							$p->set_stock_status( $new );
							break;
						case 'regular_price':
							$p->set_regular_price( $new );
							break;
						case 'sale_price':
							$p->set_sale_price( $new );
							break;
						case 'low_stock_amount':
							$p->set_low_stock_amount( $new );
							break;
						case 'date_on_sale_from':
							$p->set_date_on_sale_from( $new ? $new : null );
							break;
						case 'date_on_sale_to':
							$p->set_date_on_sale_to( $new ? $new : null );
							break;
						default:
							/**
							 * Write a field the free plugin does not know (Pro fields, custom meta).
							 *
							 * @param WC_Product $p     Product.
							 * @param string     $key   Field.
							 * @param mixed      $new   Value.
							 * @param array      $entry Plan entry.
							 */
							do_action( 'wpfactory_wc_sm_write_field', $p, $key, $new, $entry );
					}
				}
				$p->save();
				if ( null !== $add ) {
					wc_update_product_stock( $p, abs( $add ), $add >= 0 ? 'increase' : 'decrease' );
					$p = wc_get_product( $entry['pid'] );
				}
				do_action( 'wpfactory_wc_sm_after_row', $p, $entry, $run );
				$after = self::current( wc_get_product( $entry['pid'] ), array_keys( $changes ) );
				return array(
					'backup' => $before,
					'after'  => $after,
				);
			} catch ( \Throwable $e ) {
				return new WP_Error( 'write', $e->getMessage() );
			}
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* 5. Restore a finished run from its backup.                                                              */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Make a restore run (planned, ready to confirm).
		 *
		 * @param int $id Run to undo.
		 * @return int|WP_Error New run id.
		 */
		public static function make_restore( $id ) {
			$run = WPF_SM_Runs::get( $id );
			if ( ! $run || 'done' !== $run['state'] || ! empty( $run['purged'] ) ) {
				return new WP_Error( 'wpf_sm_no_backup', __( 'The backup of this update is no longer kept.', 'products-stock-manager-with-excel' ) );
			}
			$items = array();
			foreach ( WPF_SM_Runs::all( $id, 'backup' ) as $b ) {
				$items[] = array(
					'lines'   => array(),
					'pid'     => (int) $b[0],
					'values'  => (array) $b[1],
					'bad'     => array(),
					'notes'   => array(),
					'info'    => array(),
					'keys'    => array(),
					'restore' => 1,
				);
			}
			if ( ! $items ) {
				return new WP_Error( 'wpf_sm_no_backup', __( 'This update did not change any product, so there is nothing to restore.', 'products-stock-manager-with-excel' ) );
			}
			$new = WPF_SM_Runs::create(
				/* translators: %s: name of the file of the update being undone */
				sprintf( __( 'Restore: %s', 'products-stock-manager-with-excel' ), $run['title'] ),
				array(
					'state'       => 'comparing',
					'source'      => 'restore',
					'restore_of'  => $id,
					'options'     => array_merge( self::default_options(), array( 'stock_mode' => 'set' ) ),
					'map'         => array(),
					'cursor'      => 0,
					'counts'      => array(
						'ready' => 0,
						'check' => 0,
						'skip'  => 0,
						'same'  => 0,
					),
				)
			);
			$n = 0;
			foreach ( array_chunk( $items, self::ITEMS ) as $chunk ) {
				WPF_SM_Runs::put( $new, 'items', $n++, $chunk );
			}
			WPF_SM_Runs::update( $new, array( 'item_chunks' => $n ) );
			return $new;
		}

		/* ------------------------------------------------------------------------------------------------------ */
		/* 6. Reading results.                                                                                     */
		/* ------------------------------------------------------------------------------------------------------ */

		/**
		 * Plan entries with their results, filtered and paged (for the preview and the results screen).
		 *
		 * @param array  $run      Run.
		 * @param string $filter   all, ready, check, skip, same, failed.
		 * @param int    $page     Page.
		 * @param int    $per_page Per page.
		 * @return array( 'entries' => array, 'total' => int )
		 */
		public static function entries( $run, $filter = 'all', $page = 1, $per_page = 100 ) {
			$out   = array();
			$total = 0;
			$from  = ( max( 1, $page ) - 1 ) * $per_page;
			$n     = (int) WPF_SM_Runs::count_chunks( $run['id'], 'plan' );
			for ( $c = 0; $c < $n; $c++ ) {
				$plan    = WPF_SM_Runs::chunk( $run['id'], 'plan', $c );
				$results = WPF_SM_Runs::chunk( $run['id'], 'result', $c );
				foreach ( $plan as $i => $e ) {
					$e['result'] = isset( $results[ $i ] ) ? $results[ $i ] : null;
					if ( 'all' !== $filter ) {
						if ( 'failed' === $filter ) {
							if ( ! $e['result'] || 'failed' !== $e['result'][0] ) {
								continue;
							}
						} elseif ( 'changed' === $filter ) {
							if ( ! in_array( $e['status'], array( 'ready', 'check' ), true ) ) {
								continue;
							}
						} elseif ( $e['status'] !== $filter ) {
							continue;
						}
					}
					if ( $total >= $from && count( $out ) < $per_page ) {
						$out[] = $e;
					}
					++$total;
				}
			}
			return array(
				'entries' => $out,
				'total'   => $total,
			);
		}
	}

endif;
