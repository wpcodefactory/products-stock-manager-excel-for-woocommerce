<?php
/**
 * Loads the Stock Manager engine (shared by the free plugin and Pro; only one of them loads it).
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'fields', 'reader', 'runs', 'engine', 'xlsx', 'export', 'admin' ) as $wpf_sm_part ) {
	require_once __DIR__ . '/class-wpf-sm-' . $wpf_sm_part . '.php';
}
unset( $wpf_sm_part );
