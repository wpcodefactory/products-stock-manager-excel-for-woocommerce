<?php
/**
 * Stock Manager AI on WPFactory AI credits: match columns, suggest matches for skipped rows, suggest what to reorder.
 *
 * Nothing is sent until an administrator connects the site on WPFactory > AI Credits. Every AI answer is a suggestion
 * the merchant accepts; AI never writes to the store. Calls offer one tool and ask for it, so answers are JSON. Failed or empty
 * answers are refunded. The filter `wpfactory_wc_sm_ai_response` answers instead of WPFactory AI (tests, own key).
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPF_SM_AI' ) ) :

	/**
	 * AI.
	 */
	class WPF_SM_AI {

		const PRODUCT = 'stock-manager-excel';
		const ALIASES = 'wpfactory_wc_sm_aliases';

		/**
		 * Hooks.
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_product' ), 5 );
			add_filter( 'wpfactory_wc_sm_match_row', array( __CLASS__, 'match_alias' ), 10, 2 );
			add_action( 'wpfactory_wc_sm_header_right', array( __CLASS__, 'pill' ) );
			add_action( 'wpfactory_wc_sm_columns_tools', array( __CLASS__, 'columns_button' ), 10, 2 );
			add_action( 'wpfactory_wc_sm_run_notes', array( __CLASS__, 'matches_card' ), 20, 2 );
			add_action( 'wpfactory_wc_sm_lowstock_tools', array( __CLASS__, 'reorder_card' ), 20, 3 );
			foreach ( array( 'columns', 'matches', 'use_matches', 'reorder' ) as $a ) {
				add_action( 'admin_post_wpf_sm_ai_' . $a, array( __CLASS__, 'handle_' . $a ) );
			}
		}

		/**
		 * The library, when bundled and loaded.
		 *
		 * @return object|null
		 */
		public static function lib() {
			return function_exists( 'wpfactory_ai' ) ? wpfactory_ai() : null;
		}

		/**
		 * Shows on the AI Credits screen.
		 */
		public static function register_product() {
			if ( self::lib() ) {
				self::lib()->register_product( self::PRODUCT, array( 'name' => __( 'Stock Manager', 'products-stock-manager-with-excel' ) ) );
			}
		}

		/**
		 * Whether AI can answer now.
		 *
		 * @return bool
		 */
		public static function ready() {
			return has_filter( 'wpfactory_wc_sm_ai_response' ) || ( self::lib() && self::lib()->is_connected() );
		}

		/**
		 * Credits pill in the header.
		 */
		public static function pill() {
			if ( self::lib() && self::lib()->is_connected() ) {
				echo '<div class="wpf-sm-pill">' . wp_kses_post( self::lib()->pill( self::PRODUCT ) ) . '</div>';
			}
		}

		/**
		 * Button or "turn on" link for an AI action.
		 *
		 * @param string $action  admin-post action without prefix.
		 * @param array  $fields  Hidden fields.
		 * @param string $label   Button text.
		 * @param int    $credits Estimated credits.
		 * @param string $extra   Extra inputs (HTML, escaped by the caller).
		 */
		protected static function button( $action, $fields, $label, $credits, $extra = '' ) {
			$icon = '<svg class="wpf-sm-spark" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9L12 2zm7 12l.9 2.6 2.6.9-2.6.9L19 21l-.9-2.6-2.6-.9 2.6-.9L19 14z"/></svg>';
			if ( ! self::ready() ) {
				$url = self::lib() ? self::lib()->admin_url() : '';
				if ( $url ) {
					echo '<a class="wpf-sm-btn secondary wpf-sm-ai" href="' . esc_url( $url ) . '">' . $icon . esc_html( $label ) . '<span class="wpf-sm-sub-inline">' . esc_html__( 'Turn on AI', 'products-stock-manager-with-excel' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				return;
			}
			echo '<form class="wpf-sm-inline wpf-sm-ai-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wpf_sm_ai_' . $action );
			echo '<input type="hidden" name="action" value="wpf_sm_ai_' . esc_attr( $action ) . '">';
			foreach ( $fields as $k => $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
			echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			/* translators: %s: number of credits */
			echo '<button class="wpf-sm-btn secondary wpf-sm-ai" type="submit">' . $icon . esc_html( $label ) . '<span class="wpf-sm-sub-inline">' . esc_html( sprintf( _n( 'about %s credit', 'about %s credits', max( 1, $credits ), 'products-stock-manager-with-excel' ), number_format_i18n( max( 1, $credits ) ) ) ) . '</span></button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/* -------------------------------------------------------------------------------------------------- */
		/* Calls                                                                                               */
		/* -------------------------------------------------------------------------------------------------- */

		/**
		 * One call with one forced tool. Returns array( 'input' => tool input, 'request_id' => id ) or WP_Error.
		 *
		 * @param string $task       Task (shows in usage).
		 * @param string $model      Model.
		 * @param int    $max_tokens Most output tokens.
		 * @param string $system     System prompt.
		 * @param string $user       User message.
		 * @param string $tool       Tool name.
		 * @param array  $schema     Tool input schema.
		 * @return array|WP_Error
		 */
		public static function ask( $task, $model, $max_tokens, $system, $user, $tool, $schema ) {
			$body = array(
				'model'       => $model,
				'max_tokens'  => $max_tokens,
				'system'      => $system,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
				'tools'       => array(
					array(
						'name'         => $tool,
						'description'  => 'Save the answer.',
						'input_schema' => $schema,
					),
				),
				// The 5.5 models refuse a forced tool ("tool_choice type tool is not supported for this model"), so
				// the model chooses and the prompt asks for the tool. A text answer is refunded below.
				'tool_choice' => array( 'type' => 'auto' ),
			);
			$body['system'] .= ' Always answer by calling the ' . $tool . ' tool exactly once, with no other text.';
			/**
			 * Answer an AI call instead of WPFactory AI (tests, own key). Return null to use credits, an Anthropic
			 * message array (with `content`), or WP_Error.
			 *
			 * @param null|array|WP_Error $answer Answer.
			 * @param array               $body   Anthropic Messages body.
			 * @param string              $task   Task.
			 */
			$pre = apply_filters( 'wpfactory_wc_sm_ai_response', null, $body, $task );
			if ( null !== $pre ) {
				if ( is_wp_error( $pre ) ) {
					return $pre;
				}
				$r = array(
					'message'    => isset( $pre['message'] ) ? $pre['message'] : $pre,
					'request_id' => '',
				);
			} else {
				$ai = self::lib();
				if ( ! $ai || ! $ai->is_connected() ) {
					return new WP_Error( 'wpf_sm_ai_off', __( 'Turn on AI first: connect this site on WPFactory > AI Credits.', 'products-stock-manager-with-excel' ) );
				}
				$r = $ai->call( self::PRODUCT, $body, array( 'task' => $task ) );
				if ( is_wp_error( $r ) ) {
					if ( false !== stripos( $r->get_error_message(), 'X-WPFactory-Product' ) ) {
						return new WP_Error( 'wpf_sm_ai_unknown', __( 'WPFactory AI does not run Stock Manager tasks yet. Nothing was charged. Please try again later.', 'products-stock-manager-with-excel' ) );
					}
					return $r; // Plain message from the library; nothing charged.
				}
			}
			foreach ( (array) ( isset( $r['message']['content'] ) ? $r['message']['content'] : array() ) as $block ) {
				if ( isset( $block['type'], $block['name'], $block['input'] ) && 'tool_use' === $block['type'] && $tool === $block['name'] ) {
					return array(
						'input'      => (array) $block['input'],
						'request_id' => (string) $r['request_id'],
					);
				}
			}
			self::refund( (string) $r['request_id'] );
			$stop = isset( $r['message']['stop_reason'] ) ? (string) $r['message']['stop_reason'] : '';
			if ( 'max_tokens' === $stop ) {
				return new WP_Error( 'wpf_sm_ai_long', __( 'The AI needed more room than this request allows, so nothing was charged. Try again with fewer rows or products.', 'products-stock-manager-with-excel' ), array( 'stop_reason' => $stop ) );
			}
			return new WP_Error( 'wpf_sm_ai_empty', __( 'The AI answer was empty, so nothing was charged. Try again.', 'products-stock-manager-with-excel' ), array( 'stop_reason' => $stop ) );
		}

		/**
		 * Refund a call that gave nothing usable.
		 *
		 * @param string $request_id Request id.
		 */
		public static function refund( $request_id ) {
			if ( $request_id && self::lib() ) {
				self::lib()->refund( $request_id );
			}
		}

		/**
		 * Guard for handlers.
		 *
		 * @param string $action Action.
		 */
		protected static function guard( $action ) {
			if ( ! current_user_can( WPF_SM_Admin::cap() ) ) {
				wp_die( esc_html__( 'You cannot do this.', 'products-stock-manager-with-excel' ), 403 );
			}
			check_admin_referer( 'wpf_sm_ai_' . $action );
		}

		/**
		 * Back to a screen with a notice.
		 *
		 * @param array  $args Query args.
		 * @param string $type Type.
		 * @param string $text Text.
		 */
		protected static function back( $args, $type, $text ) {
			WPF_SM_Admin::flash( $type, $text );
			wp_safe_redirect( WPF_SM_Admin::$instance->url( $args ) );
			exit;
		}

		/* -------------------------------------------------------------------------------------------------- */
		/* 1. Match columns                                                                                    */
		/* -------------------------------------------------------------------------------------------------- */

		/**
		 * Button on the Columns step when some headers are not matched.
		 *
		 * @param array        $run   Run.
		 * @param WPF_SM_Admin $admin Admin.
		 */
		public static function columns_button( $run, $admin ) {
			$open = 0;
			foreach ( (array) $run['header'] as $i => $h ) {
				if ( '' !== trim( (string) $h ) && empty( $run['map'][ $i ] ) ) {
					++$open;
				}
			}
			if ( $open || ! empty( $run['options']['legacy'] ) ) {
				self::button( 'columns', array( 'run' => $run['id'] ), __( 'Match columns with AI', 'products-stock-manager-with-excel' ), 1 );
			}
		}

		/**
		 * Ask AI which field each column is.
		 */
		public static function handle_columns() {
			self::guard( 'columns' );
			$id  = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			$run = WPF_SM_Runs::get( $id );
			if ( ! $run || 'new' !== $run['state'] ) {
				self::back( array( 'tab' => 'import' ), 'bad', __( 'This update is no longer open.', 'products-stock-manager-with-excel' ) );
			}
			$fields = array();
			foreach ( WPF_SM_Fields::all() as $key => $f ) {
				$fields[ $key ] = $f['label'];
			}
			$cols = array();
			foreach ( (array) $run['header'] as $i => $h ) {
				$samples = array();
				foreach ( (array) $run['samples'] as $s ) {
					$samples[] = isset( $s[ $i ] ) ? mb_substr( (string) $s[ $i ], 0, 40 ) : '';
				}
				$cols[] = array(
					'column'  => WPF_SM_Fields::letter( $i ),
					'header'  => mb_substr( (string) $h, 0, 60 ),
					'samples' => $samples,
				);
			}
			$keys = array_merge( array_keys( $fields ), array( 'ignore' ) );
			$res  = self::ask(
				'map-columns',
				'claude-haiku-5-5',
				1500,
				'You match the columns of a supplier or stock spreadsheet to WooCommerce product fields. Use only the field keys given. A column that finds the product (ID, SKU, GTIN/EAN/barcode) is a key field. Use "ignore" when unsure or when no field fits. Never guess wildly: a wrong match changes prices or stock.',
				wp_json_encode(
					array(
						'fields'  => $fields,
						'columns' => $cols,
					)
				),
				'set_columns',
				array(
					'type'       => 'object',
					'properties' => array(
						'columns' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'column' => array( 'type' => 'string' ),
									'field'  => array(
										'type' => 'string',
										'enum' => $keys,
									),
									'reason' => array( 'type' => 'string' ),
								),
								'required'   => array( 'column', 'field' ),
							),
						),
					),
					'required'   => array( 'columns' ),
				)
			);
			if ( is_wp_error( $res ) ) {
				self::back( array( 'tab' => 'import', 'run' => $id ), 'bad', $res->get_error_message() );
			}
			$map = (array) $run['map'];
			if ( ! empty( $run['options']['legacy'] ) ) {
				// The old fixed layout was only a guess: AI starts from an empty mapping.
				$map = array_fill( 0, max( 1, count( (array) $run['header'] ) ), '' );
			}
			$used    = array_flip( array_filter( $map ) );
			$changed = array();
			foreach ( (array) ( isset( $res['input']['columns'] ) ? $res['input']['columns'] : array() ) as $c ) {
				$i     = WPF_SM_Fields::index( isset( $c['column'] ) ? $c['column'] : '' );
				$field = isset( $c['field'] ) ? (string) $c['field'] : '';
				if ( $i < 0 || 'ignore' === $field || ! WPF_SM_Fields::get( $field ) || isset( $used[ $field ] ) ) {
					continue;
				}
				if ( ! empty( $map[ $i ] ) ) {
					continue; // Keep what the header names or the merchant already chose.
				}
				$map[ $i ]        = $field;
				$used[ $field ]   = true;
				$changed[]        = WPF_SM_Fields::letter( $i ) . ' ' . WPF_SM_Fields::get( $field )['label'];
			}
			if ( ! $changed ) {
				self::refund( $res['request_id'] );
				self::back( array( 'tab' => 'import', 'run' => $id ), 'warn', __( 'AI found no other column it could match. Nothing was charged.', 'products-stock-manager-with-excel' ) );
			}
			$opts           = $run['options'];
			$opts['legacy'] = false;
			WPF_SM_Runs::update( $id, array( 'map' => $map, 'options' => $opts ) );
			/* translators: %s: list of columns and fields */
			self::back( array( 'tab' => 'import', 'run' => $id ), 'ok', sprintf( __( 'AI matched: %s. Check them before you go on.', 'products-stock-manager-with-excel' ), implode( ', ', $changed ) ) );
		}

		/* -------------------------------------------------------------------------------------------------- */
		/* 2. Suggest matches for skipped rows                                                                 */
		/* -------------------------------------------------------------------------------------------------- */

		/**
		 * Remembered codes: normalised code => product id.
		 *
		 * @return array
		 */
		public static function aliases() {
			$a = get_option( self::ALIASES, array() );
			return is_array( $a ) ? $a : array();
		}

		/**
		 * Normalise a supplier code: lower case, letters and digits only.
		 *
		 * @param string $code Code.
		 * @return string
		 */
		public static function norm( $code ) {
			return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) $code ) ) );
		}

		/**
		 * Engine filter: a row whose code was matched before (accepted suggestion) finds its product.
		 *
		 * @param int   $pid   Product found so far.
		 * @param array $entry Row.
		 * @return int
		 */
		public static function match_alias( $pid, $entry ) {
			if ( $pid ) {
				return $pid;
			}
			$aliases = self::aliases();
			if ( ! $aliases ) {
				return $pid;
			}
			foreach ( array( 'sku', 'gtin', 'id' ) as $k ) {
				if ( ! empty( $entry['keys'][ $k ] ) ) {
					$n = self::norm( $entry['keys'][ $k ] );
					if ( '' !== $n && isset( $aliases[ $n ] ) ) {
						return (int) $aliases[ $n ];
					}
				}
			}
			return $pid;
		}

		/**
		 * Skipped rows that could be matched (not found by their code).
		 *
		 * @param array $run Run.
		 * @return array line => array( code, name, gtin )
		 */
		protected static function unmatched( $run ) {
			$out  = array();
			$data = WPF_SM_Engine::entries( $run, 'skip', 1, 200 );
			foreach ( $data['entries'] as $e ) {
				if ( $e['pid'] ) {
					continue;
				}
				$codes = wp_list_pluck( $e['notes'], 0 );
				if ( ! array_intersect( $codes, array( 'no_sku', 'no_gtin', 'no_id' ) ) ) {
					continue;
				}
				$code = '';
				$gtin = '';
				foreach ( $e['notes'] as $n ) {
					if ( in_array( $n[0], array( 'no_sku', 'no_id' ), true ) ) {
						$code = (string) $n[1];
					} elseif ( 'no_gtin' === $n[0] ) {
						$gtin = (string) $n[1];
					}
				}
				$line         = (int) reset( $e['lines'] );
				$out[ $line ] = array(
					'code' => $code,
					'name' => (string) $e['name'],
					'gtin' => $gtin,
				);
			}
			return array_slice( $out, 0, 25, true );
		}

		/**
		 * Card on the preview: suggestions to accept, or the button.
		 *
		 * @param array        $run   Run.
		 * @param WPF_SM_Admin $admin Admin.
		 */
		public static function matches_card( $run, $admin ) {
			if ( 'planned' !== $run['state'] || 'restore' === $run['source'] ) {
				return;
			}
			if ( ! empty( $run['ai_matches'] ) ) {
				echo '<form class="wpf-sm-card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'wpf_sm_ai_use_matches' );
				echo '<input type="hidden" name="action" value="wpf_sm_ai_use_matches"><input type="hidden" name="run" value="' . (int) $run['id'] . '">';
				echo '<div class="wpf-sm-card-head"><h2>' . esc_html__( 'Suggested matches', 'products-stock-manager-with-excel' ) . '</h2></div><div class="wpf-sm-table-wrap"><table class="wpf-sm-table"><thead><tr><th><span class="screen-reader-text">' . esc_html__( 'Use', 'products-stock-manager-with-excel' ) . '</span></th><th>' . esc_html__( 'Row', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'In the file', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'Your product', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'Why', 'products-stock-manager-with-excel' ) . '</th></tr></thead><tbody>';
				foreach ( $run['ai_matches'] as $m ) {
					$p = wc_get_product( (int) $m['pid'] );
					if ( ! $p ) {
						continue;
					}
					echo '<tr><td><input type="checkbox" name="use[]" value="' . (int) $m['line'] . '" checked aria-label="' . esc_attr__( 'Use this match', 'products-stock-manager-with-excel' ) . '"></td><td class="wpf-sm-mute">' . (int) $m['line'] . '</td><td>' . esc_html( $m['code'] ) . '<span class="wpf-sm-sub">' . esc_html( $m['name'] ) . '</span></td>';
					echo '<td>' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '<span class="wpf-sm-sub">' . esc_html( $p->get_sku() ? $p->get_sku() : '#' . $p->get_id() ) . '</span></td><td class="wpf-sm-mute">' . esc_html( $m['reason'] ) . '</td></tr>';
				}
				echo '</tbody></table></div><div class="wpf-sm-card-foot"><button class="wpf-sm-btn primary" type="submit">' . esc_html__( 'Use the ticked matches and check again', 'products-stock-manager-with-excel' ) . '</button><span class="wpf-sm-mute">' . esc_html__( 'The codes are remembered for the next files.', 'products-stock-manager-with-excel' ) . '</span></div></form>';
				return;
			}
			$rows = self::unmatched( $run );
			if ( $rows ) {
				echo '<div class="wpf-sm-ai-row">';
				/* translators: %s: number of rows */
				echo '<span class="wpf-sm-mute">' . esc_html( sprintf( _n( '%s row did not match a product by its code.', '%s rows did not match a product by their code.', count( $rows ), 'products-stock-manager-with-excel' ), number_format_i18n( count( $rows ) ) ) ) . '</span> ';
				self::button( 'matches', array( 'run' => $run['id'] ), __( 'Suggest matches with AI', 'products-stock-manager-with-excel' ), (int) ceil( count( $rows ) / 10 ) );
				echo '</div>';
			}
		}

		/**
		 * Up to 15 likely products per row, by text similarity of codes and names (no AI, no data leaves the site).
		 *
		 * @param array $rows Rows.
		 * @return array line => array of candidates
		 */
		protected static function candidates( $rows ) {
			global $wpdb;
			$all = $wpdb->get_results( "SELECT p.ID, p.post_title, p.post_parent, m.meta_value sku FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private','draft','pending')", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out = array();
			foreach ( $rows as $line => $r ) {
				$code   = self::norm( $r['code'] ? $r['code'] : $r['gtin'] );
				$name   = strtolower( remove_accents( $r['name'] ) );
				$scored = array();
				foreach ( $all as $p ) {
					$score = 0;
					$sku   = self::norm( $p['sku'] );
					if ( '' !== $code && '' !== $sku ) {
						similar_text( $code, $sku, $pc );
						$score = max( $score, $pc + ( false !== strpos( $sku, $code ) || false !== strpos( $code, $sku ) ? 30 : 0 ) );
					}
					if ( '' !== $name ) {
						similar_text( $name, strtolower( remove_accents( $p['post_title'] ) ), $pn );
						$score = max( $score, $pn );
					}
					if ( $score >= 40 ) {
						$scored[] = array( $score, (int) $p['ID'], (string) $p['sku'], wp_strip_all_tags( $p['post_title'] ) );
					}
				}
				usort(
					$scored,
					function ( $a, $b ) {
						return $b[0] <=> $a[0];
					}
				);
				$out[ $line ] = array_map(
					function ( $s ) {
						return array(
							'id'   => $s[1],
							'sku'  => $s[2],
							'name' => mb_substr( $s[3], 0, 80 ),
						);
					},
					array_slice( $scored, 0, 15 )
				);
			}
			return $out;
		}

		/**
		 * Ask AI to pick matches.
		 */
		public static function handle_matches() {
			self::guard( 'matches' );
			$id  = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			$run = WPF_SM_Runs::get( $id );
			if ( ! $run || 'planned' !== $run['state'] ) {
				self::back( array( 'tab' => 'import' ), 'bad', __( 'This preview is no longer open.', 'products-stock-manager-with-excel' ) );
			}
			$rows  = self::unmatched( $run );
			$cands = self::candidates( $rows );
			$ask   = array();
			foreach ( $rows as $line => $r ) {
				if ( ! empty( $cands[ $line ] ) ) {
					$ask[] = array_merge( array( 'row' => $line ), $r, array( 'candidates' => $cands[ $line ] ) );
				}
			}
			if ( ! $ask ) {
				self::back( array( 'tab' => 'import', 'run' => $id ), 'warn', __( 'No product in your store looks close to these rows, so AI was not asked. Nothing was charged.', 'products-stock-manager-with-excel' ) );
			}
			$res = self::ask(
				'match-rows',
				'claude-haiku-5-5',
				1500 + 100 * count( $ask ),
				'You match rows of a supplier file to products of a WooCommerce store. For each row, pick the one candidate that is the same product (same item, same size or colour), judging by code and name. Supplier codes often differ in case, dashes or prefixes. If none is clearly the same product, answer 0. Never pick a product only because it is similar.',
				wp_json_encode( array( 'rows' => $ask ) ),
				'save_matches',
				array(
					'type'       => 'object',
					'properties' => array(
						'matches' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'row'        => array( 'type' => 'integer' ),
									'product_id' => array( 'type' => 'integer' ),
									'reason'     => array( 'type' => 'string' ),
								),
								'required'   => array( 'row', 'product_id' ),
							),
						),
					),
					'required'   => array( 'matches' ),
				)
			);
			if ( is_wp_error( $res ) ) {
				self::back( array( 'tab' => 'import', 'run' => $id ), 'bad', $res->get_error_message() );
			}
			$found = array();
			foreach ( (array) ( isset( $res['input']['matches'] ) ? $res['input']['matches'] : array() ) as $m ) {
				$line = isset( $m['row'] ) ? (int) $m['row'] : 0;
				$pid  = isset( $m['product_id'] ) ? (int) $m['product_id'] : 0;
				if ( ! $pid || ! isset( $cands[ $line ] ) || ! in_array( $pid, wp_list_pluck( $cands[ $line ], 'id' ), true ) ) {
					continue; // Only products we offered.
				}
				$found[] = array(
					'line'   => $line,
					'code'   => $rows[ $line ]['code'] ? $rows[ $line ]['code'] : $rows[ $line ]['gtin'],
					'name'   => $rows[ $line ]['name'],
					'pid'    => $pid,
					'reason' => isset( $m['reason'] ) ? sanitize_text_field( mb_substr( (string) $m['reason'], 0, 160 ) ) : '',
				);
			}
			if ( ! $found ) {
				self::refund( $res['request_id'] );
				self::back( array( 'tab' => 'import', 'run' => $id, 'show' => 'skip' ), 'warn', __( 'AI found no sure match. Nothing was charged.', 'products-stock-manager-with-excel' ) );
			}
			WPF_SM_Runs::update( $id, array( 'ai_matches' => $found ) );
			/* translators: %s: number */
			self::back( array( 'tab' => 'import', 'run' => $id, 'show' => 'skip' ), 'ok', sprintf( _n( 'AI suggests %s match. Tick the ones that are right.', 'AI suggests %s matches. Tick the ones that are right.', count( $found ), 'products-stock-manager-with-excel' ), number_format_i18n( count( $found ) ) ) );
		}

		/**
		 * Save the ticked matches as codes and check the file again.
		 */
		public static function handle_use_matches() {
			self::guard( 'use_matches' );
			$id  = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			$run = WPF_SM_Runs::get( $id );
			if ( ! $run || 'planned' !== $run['state'] || empty( $run['ai_matches'] ) ) {
				self::back( array( 'tab' => 'import' ), 'bad', __( 'This preview is no longer open.', 'products-stock-manager-with-excel' ) );
			}
			$use     = isset( $_POST['use'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['use'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			$aliases = self::aliases();
			$n       = 0;
			foreach ( $run['ai_matches'] as $m ) {
				$code = self::norm( $m['code'] );
				if ( in_array( (int) $m['line'], $use, true ) && '' !== $code ) {
					$aliases[ $code ] = (int) $m['pid'];
					++$n;
				}
			}
			update_option( self::ALIASES, $aliases, false );
			WPF_SM_Runs::update( $id, array( 'ai_matches' => array() ) );
			WPF_SM_Engine::confirm_columns( $id, (array) $run['map'] );
			/* translators: %s: number */
			self::back( array( 'tab' => 'import', 'run' => $id ), 'ok', sprintf( _n( '%s match saved. The file is being checked again.', '%s matches saved. The file is being checked again.', $n, 'products-stock-manager-with-excel' ), number_format_i18n( $n ) ) );
		}

		/* -------------------------------------------------------------------------------------------------- */
		/* 3. What to reorder                                                                                  */
		/* -------------------------------------------------------------------------------------------------- */

		/**
		 * Weekly units sold for the last 12 weeks, per product or variation.
		 *
		 * @param int[] $ids Ids.
		 * @return array id => array of 12 numbers (oldest first)
		 */
		protected static function weekly( $ids ) {
			global $wpdb;
			$t = $wpdb->prefix . 'wc_order_product_lookup';
			$s = $wpdb->prefix . 'wc_order_stats';
			if ( ! $ids || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return array();
			}
			$since = gmdate( 'Y-m-d H:i:s', time() - 84 * DAY_IN_SECONDS );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT IF(l.variation_id > 0, l.variation_id, l.product_id) id, FLOOR(DATEDIFF(%s, l.date_created) / 7) wk, SUM(l.product_qty) qty FROM {$t} l JOIN {$s} o ON o.order_id = l.order_id WHERE l.date_created >= %s AND o.status NOT IN ('wc-refunded','wc-cancelled','wc-failed','wc-pending','wc-checkout-draft','trash') GROUP BY id, wk", current_time( 'mysql', 1 ), $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from $wpdb->prefix.
			$out   = array();
			$want  = array_flip( array_map( 'intval', $ids ) );
			foreach ( $rows as $r ) {
				$id = (int) $r->id;
				$wk = (int) $r->wk;
				if ( ! isset( $want[ $id ] ) || $wk < 0 || $wk > 11 ) {
					continue;
				}
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = array_fill( 0, 12, 0 );
				}
				$out[ $id ][ 11 - $wk ] += (float) $r->qty;
			}
			return $out;
		}

		/**
		 * Card on Low stock: suggestions with reasons, or the button.
		 *
		 * @param array        $rows  Low stock rows.
		 * @param array        $a     Filters.
		 * @param WPF_SM_Admin $admin Admin.
		 */
		public static function reorder_card( $rows, $a, $admin ) {
			if ( ! $rows ) {
				return;
			}
			$saved = get_transient( 'wpf_sm_ai_reorder_' . get_current_user_id() );
			echo '<section class="wpf-sm-card"><div class="wpf-sm-card-head"><h2>' . esc_html__( 'What to reorder', 'products-stock-manager-with-excel' ) . '</h2>';
			$lead  = '<label class="wpf-sm-inline-field">' . esc_html__( 'Days until a new order arrives', 'products-stock-manager-with-excel' ) . ' <input type="number" name="lead" min="0" max="180" value="' . (int) ( $saved ? $saved['lead'] : 14 ) . '"></label>';
			self::button( 'reorder', array( 'show' => $a['show'], 'days' => $a['days'], 'cover' => $a['cover'] ), __( 'Suggest reorder with AI', 'products-stock-manager-with-excel' ), (int) ceil( min( 40, count( $rows ) ) / 8 ), $lead );
			echo '</div>';
			if ( $saved && ! empty( $saved['items'] ) ) {
				echo '<div class="wpf-sm-table-wrap"><table class="wpf-sm-table"><thead><tr><th>' . esc_html__( 'Product', 'products-stock-manager-with-excel' ) . '</th><th class="num">' . esc_html__( 'Order', 'products-stock-manager-with-excel' ) . '</th><th>' . esc_html__( 'Why', 'products-stock-manager-with-excel' ) . '</th></tr></thead><tbody>';
				foreach ( $saved['items'] as $it ) {
					echo '<tr><td>' . esc_html( $it['name'] ) . '<span class="wpf-sm-sub">' . esc_html( $it['sku'] ) . '</span></td><td class="num"><strong>' . esc_html( number_format_i18n( $it['qty'] ) ) . '</strong></td><td class="wpf-sm-mute">' . esc_html( $it['reason'] ) . '</td></tr>';
				}
				/* translators: %s: date and time */
				echo '</tbody></table></div><p class="wpf-sm-foot-note">' . esc_html( sprintf( __( 'Suggested %s from your sales and stock. Check them before you order.', 'products-stock-manager-with-excel' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $saved['time'] ) ) ) . '</p>';
			} else {
				echo '<p class="wpf-sm-foot-note">' . esc_html__( 'AI looks at 12 weeks of sales, the trend and your stock, and suggests how many to order with a reason for each product.', 'products-stock-manager-with-excel' ) . '</p>';
			}
			echo '</section>';
		}

		/**
		 * Ask AI what to reorder.
		 */
		public static function handle_reorder() {
			self::guard( 'reorder' );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
			$show  = isset( $_POST['show'] ) ? sanitize_key( wp_unslash( $_POST['show'] ) ) : 'low';
			$days  = isset( $_POST['days'] ) ? min( 365, max( 1, absint( $_POST['days'] ) ) ) : 30;
			$cover = isset( $_POST['cover'] ) ? min( 365, max( 1, absint( $_POST['cover'] ) ) ) : 30;
			$lead  = isset( $_POST['lead'] ) ? min( 180, absint( $_POST['lead'] ) ) : 14;
			// phpcs:enable
			$back = array(
				'tab'   => 'report',
				'show'  => $show,
				'days'  => $days,
				'cover' => $cover,
			);
			$rows = array_slice( WPF_SM_Export::low_stock( in_array( $show, array( 'low', 'out', 'all' ), true ) ? $show : 'low', $days, $cover ), 0, 40 );
			if ( ! $rows ) {
				self::back( $back, 'warn', __( 'Nothing to reorder.', 'products-stock-manager-with-excel' ) );
			}
			$weeks = self::weekly( wp_list_pluck( $rows, 'id' ) );
			$items = array();
			foreach ( $rows as $r ) {
				$weekly   = isset( $weeks[ $r['id'] ] ) ? $weeks[ $r['id'] ] : array_fill( 0, 12, 0 );
				$recent   = array_sum( array_slice( $weekly, -4 ) ) / 4;
				$baseline = max( 0, (int) ceil( $recent / 7 * ( $lead + $cover ) - (float) $r['stock'] ) );
				$items[]  = array(
					'id'                     => $r['id'],
					'name'                   => mb_substr( $r['name'], 0, 80 ),
					'stock'                  => $r['stock'],
					'low_level'              => $r['level'],
					'weekly_sales'           => $weekly,
					'average_last_4_weeks'   => round( $recent, 1 ),
					'baseline_order'         => $baseline,
				);
			}
			$res = self::ask(
				'reorder',
				(string) apply_filters( 'wpfactory_wc_sm_ai_reorder_model', 'claude-haiku-5-5' ),
				1500 + 120 * count( $items ),
				'You help a small shop decide what to reorder. For each product you get the stock, its low stock level, units sold per week for the last 12 weeks (oldest first), the average of the last 4 weeks and baseline_order: what the last 4 weeks of sales need for the lead time plus the cover days, minus the stock. Start from baseline_order. Change it only when the 12 weeks clearly show sales growing (order more) or falling (order less), and never suggest 0 for a product that sold in the last 4 weeks and has less stock than baseline_order needs. Give a whole number and a reason of at most 20 plain words that names the numbers you used. Use only the numbers given. Do not invent prices, suppliers or dates.',
				wp_json_encode(
					array(
						'lead_time_days' => $lead,
						'cover_days'     => $cover,
						'products'       => $items,
					)
				),
				'save_reorder',
				array(
					'type'       => 'object',
					'properties' => array(
						'products' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'       => array( 'type' => 'integer' ),
									'quantity' => array( 'type' => 'integer' ),
									'reason'   => array( 'type' => 'string' ),
								),
								'required'   => array( 'id', 'quantity', 'reason' ),
							),
						),
					),
					'required'   => array( 'products' ),
				)
			);
			if ( is_wp_error( $res ) ) {
				self::back( $back, 'bad', $res->get_error_message() );
			}
			$by  = array();
			$out = array();
			foreach ( $rows as $r ) {
				$by[ $r['id'] ] = $r;
			}
			foreach ( (array) ( isset( $res['input']['products'] ) ? $res['input']['products'] : array() ) as $p ) {
				$id = isset( $p['id'] ) ? (int) $p['id'] : 0;
				if ( ! isset( $by[ $id ] ) ) {
					continue;
				}
				$out[] = array(
					'id'     => $id,
					'name'   => $by[ $id ]['name'],
					'sku'    => $by[ $id ]['sku'],
					'qty'    => max( 0, (int) $p['quantity'] ),
					'reason' => sanitize_text_field( wp_trim_words( (string) $p['reason'], 30, '...' ) ),
				);
			}
			if ( ! $out ) {
				self::refund( $res['request_id'] );
				self::back( $back, 'bad', __( 'The AI answer could not be used, so nothing was charged. Try again.', 'products-stock-manager-with-excel' ) );
			}
			usort(
				$out,
				function ( $a, $b ) {
					return $b['qty'] <=> $a['qty'];
				}
			);
			set_transient(
				'wpf_sm_ai_reorder_' . get_current_user_id(),
				array(
					'items' => $out,
					'lead'  => $lead,
					'time'  => time(),
				),
				DAY_IN_SECONDS
			);
			self::back( $back, 'ok', __( 'Reorder suggestions are ready below.', 'products-stock-manager-with-excel' ) );
		}
	}

endif;
