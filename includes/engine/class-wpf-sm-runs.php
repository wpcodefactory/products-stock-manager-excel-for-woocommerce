<?php
/**
 * Stock Manager engine: storage for updates ("runs") without extra database tables.
 *
 * A run is a private post of type `wpf_sm_run`. Small state (step, counts, choices) is JSON in post_content, the step
 * name sits in post_mime_type (`wpf-sm/<state>`) so lists can filter on it. Big data (the file's rows, the planned
 * changes, the backup of old values, the results) is JSON in post meta chunks, read and written with direct queries so
 * WordPress never loads every chunk into the object cache at once.
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPF_SM_Runs' ) ) :

	/**
	 * Runs.
	 */
	class WPF_SM_Runs {

		const TYPE  = 'wpf_sm_run';
		const CHUNK = 500;

		/**
		 * Register the post type (hidden everywhere).
		 */
		public static function register() {
			if ( post_type_exists( self::TYPE ) ) {
				return;
			}
			register_post_type(
				self::TYPE,
				array(
					'label'               => __( 'Stock updates', 'products-stock-manager-with-excel' ),
					'public'              => false,
					'show_ui'             => false,
					'show_in_rest'        => false,
					'show_in_nav_menus'   => false,
					'exclude_from_search' => true,
					'publicly_queryable'  => false,
					'query_var'           => false,
					'rewrite'             => false,
					'can_export'          => false,
					'delete_with_user'    => false,
					'supports'            => array( 'title', 'author' ),
				)
			);
		}

		/**
		 * New run.
		 *
		 * @param string $title File name or label.
		 * @param array  $data  Initial state.
		 * @return int Run id (0 on failure).
		 */
		public static function create( $title, $data ) {
			global $wpdb;
			$data = wp_parse_args(
				$data,
				array(
					'state'  => 'new',
					'source' => 'upload',
					'counts' => array(),
				)
			);
			$now  = current_time( 'mysql' );
			$gmt  = current_time( 'mysql', 1 );
			$ok   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->posts,
				array(
					'post_author'       => get_current_user_id(),
					'post_date'         => $now,
					'post_date_gmt'     => $gmt,
					'post_modified'     => $now,
					'post_modified_gmt' => $gmt,
					'post_title'        => sanitize_text_field( $title ),
					'post_content'      => wp_json_encode( $data ),
					'post_excerpt'      => '',
					'post_status'       => 'private',
					'post_type'         => self::TYPE,
					'post_name'         => 'wpf-sm-' . wp_generate_password( 12, false, false ),
					'post_mime_type'    => 'wpf-sm/' . $data['state'],
					'to_ping'           => '',
					'pinged'            => '',
					'post_content_filtered' => '',
					'guid'              => '',
					'comment_status'    => 'closed',
					'ping_status'       => 'closed',
				)
			);
			if ( ! $ok ) {
				return 0;
			}
			$id = (int) $wpdb->insert_id;
			clean_post_cache( $id );
			return $id;
		}

		/**
		 * Run with its state, or null.
		 *
		 * @param int $id Run id.
		 * @return array|null
		 */
		public static function get( $id ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_author, post_date, post_title, post_content FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", $id, self::TYPE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $row ) {
				return null;
			}
			$data = json_decode( $row['post_content'], true );
			$data = is_array( $data ) ? $data : array();
			return array_merge(
				array(
					'state'  => 'new',
					'source' => 'upload',
					'counts' => array(),
				),
				$data,
				array(
					'id'    => (int) $row['ID'],
					'user'  => (int) $row['post_author'],
					'date'  => $row['post_date'],
					'title' => $row['post_title'],
				)
			);
		}

		/**
		 * Merge into the run's state and save.
		 *
		 * @param int   $id   Run id.
		 * @param array $data Keys to set.
		 * @return array The new state.
		 */
		public static function update( $id, $data ) {
			global $wpdb;
			$run = self::get( $id );
			if ( ! $run ) {
				return array();
			}
			$keep = array_diff_key( $run, array_flip( array( 'id', 'user', 'date', 'title' ) ) );
			$new  = array_merge( $keep, $data );
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->posts,
				array(
					'post_content'      => wp_json_encode( $new ),
					'post_mime_type'    => 'wpf-sm/' . $new['state'],
					'post_modified'     => current_time( 'mysql' ),
					'post_modified_gmt' => current_time( 'mysql', 1 ),
				),
				array( 'ID' => $id )
			);
			clean_post_cache( $id );
			return array_merge( $new, array_intersect_key( $run, array_flip( array( 'id', 'user', 'date', 'title' ) ) ) );
		}

		/**
		 * Store one chunk of data.
		 *
		 * @param int    $id   Run id.
		 * @param string $kind rows, items, plan, backup, result.
		 * @param int    $n    Chunk number.
		 * @param array  $data Data.
		 */
		public static function put( $id, $kind, $n, $data ) {
			global $wpdb;
			$key   = '_wpf_sm_' . $kind . '_' . (int) $n;
			$value = wp_json_encode( $data );
			$mid   = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $mid ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $value ), array( 'meta_id' => $mid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			} else {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->postmeta,
					array(
						'post_id'    => $id,
						'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
			}
			wp_cache_delete( $id, 'post_meta' );
		}

		/**
		 * One chunk, or an empty array.
		 *
		 * @param int    $id   Run id.
		 * @param string $kind Kind.
		 * @param int    $n    Chunk number.
		 * @return array
		 */
		public static function chunk( $id, $kind, $n ) {
			global $wpdb;
			$v = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $id, '_wpf_sm_' . $kind . '_' . (int) $n ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$d = $v ? json_decode( $v, true ) : array();
			return is_array( $d ) ? $d : array();
		}

		/**
		 * How many chunks of a kind exist.
		 *
		 * @param int    $id   Run id.
		 * @param string $kind Kind.
		 * @return int
		 */
		public static function count_chunks( $id, $kind ) {
			global $wpdb;
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $id, $wpdb->esc_like( '_wpf_sm_' . $kind . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		/**
		 * Delete every chunk of a kind.
		 *
		 * @param int    $id   Run id.
		 * @param string $kind Kind.
		 */
		public static function drop( $id, $kind ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $id, $wpdb->esc_like( '_wpf_sm_' . $kind . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $id, 'post_meta' );
		}

		/**
		 * Every item of every chunk of a kind, in order (for reports; fine up to tens of thousands of rows).
		 *
		 * @param int    $id   Run id.
		 * @param string $kind Kind.
		 * @return array
		 */
		public static function all( $id, $kind ) {
			$out = array();
			$n   = self::count_chunks( $id, $kind );
			for ( $i = 0; $i < $n; $i++ ) {
				foreach ( self::chunk( $id, $kind, $i ) as $k => $v ) {
					$out[] = $v;
				}
			}
			return $out;
		}

		/**
		 * Finished runs, newest first.
		 *
		 * @param int   $page     Page (1-based).
		 * @param int   $per_page Per page.
		 * @param array $states   States to list.
		 * @return array( 'runs' => array, 'total' => int )
		 */
		public static function finished( $page = 1, $per_page = 20, $states = array( 'done', 'failed' ) ) {
			global $wpdb;
			$in    = implode( ',', array_fill( 0, count( $states ), '%s' ) );
			$args  = array_merge( array( self::TYPE ), array_map( function ( $s ) { return 'wpf-sm/' . $s; }, $states ) ); // phpcs:ignore Generic.Functions.OpeningFunctionBraceKernighanRitchie
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_mime_type IN ($in)", $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN list built from placeholders.
			$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_mime_type IN ($in) ORDER BY ID DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, max( 0, $page - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN list built from placeholders.
			return array(
				'runs'  => array_filter( array_map( array( __CLASS__, 'get' ), array_map( 'intval', $ids ) ) ),
				'total' => $total,
			);
		}

		/**
		 * Delete a run and its data.
		 *
		 * @param int $id Run id.
		 */
		public static function delete( $id ) {
			global $wpdb;
			$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->posts, array( 'ID' => $id, 'post_type' => self::TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $id );
		}

		/**
		 * Daily clean up: finished runs past their keep date lose their backup and big data (the summary stays for the
		 * history), unfinished runs older than two days are deleted.
		 */
		public static function purge() {
			global $wpdb;
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", self::TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$now = time();
			foreach ( $ids as $id ) {
				$run = self::get( (int) $id );
				if ( ! $run ) {
					continue;
				}
				$age = $now - strtotime( get_gmt_from_date( $run['date'] ) . ' UTC' );
				if ( ! in_array( $run['state'], array( 'done', 'failed' ), true ) ) {
					if ( $age > 2 * DAY_IN_SECONDS ) {
						self::delete( (int) $id );
					}
					continue;
				}
				if ( ! empty( $run['expires'] ) && $run['expires'] < $now && empty( $run['purged'] ) ) {
					foreach ( array( 'rows', 'items', 'backup' ) as $kind ) {
						self::drop( (int) $id, $kind );
					}
					self::update( (int) $id, array( 'purged' => 1 ) );
				}
				if ( $age > 400 * DAY_IN_SECONDS ) {
					self::delete( (int) $id );
				}
			}
		}
	}

endif;
