<?php
/**
 * Loads the Stock Manager engine (shared by the free plugin and Pro; only one of them loads it).
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'fields', 'reader', 'runs', 'engine', 'xlsx', 'export', 'admin', 'ai' ) as $wpf_sm_part ) {
	require_once __DIR__ . '/class-wpf-sm-' . $wpf_sm_part . '.php';
}
unset( $wpf_sm_part );

// WPFactory AI library (the newest bundled copy across WPFactory plugins loads once). Nothing is sent until an admin connects.
if ( file_exists( dirname( __DIR__, 2 ) . '/vendor/wpfactory/wpfactory-ai/wpfactory-ai.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/vendor/wpfactory/wpfactory-ai/wpfactory-ai.php';
}
WPF_SM_AI::init();
