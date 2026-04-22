<?php
/**
 * Plugin Name: LumenPath Career Planner
 * Description: Answer a few questions about your future goals and work preferences so Lumen can map your skills into higher-paying, future-ready roles.
 * Version: 1.1.18
 * Author: Ahmad
 * Text Domain: career-resume-evaluator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CRE_VERSION', '1.1.18' );
define( 'CRE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader for classes
spl_autoload_register( function ( $class ) {
	$prefix = 'CRE\\';
	$base_dir = CRE_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . 'class-' . str_replace( '_', '-', strtolower( $relative_class ) ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

// Initialize Plugin
function cre_init() {
	new \CRE\Settings();
	new \CRE\Ajax();
	new \CRE\Shortcode();
}
add_action( 'plugins_loaded', 'cre_init' );

// Activation Hook for DB Table Creation
register_activation_hook( __FILE__, function() {
	require_once CRE_PLUGIN_DIR . 'includes/class-db.php';
	\CRE\DB::create_table();
} );