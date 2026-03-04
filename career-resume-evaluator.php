<?php
/**
 * Plugin Name: Career Resume Evaluator
 * Plugin URI:  https://github.com/Ahmad-Codemaster/career-resume-evaluator
 * Description: Allows users to upload a DOCX resume and enter career goals. The plugin extracts text from the resume, sends it to the xAI Grok API, and returns a detailed career path analysis table.
 * Version:     1.0.0
 * Author:      Ahmad-Codemaster
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: career-resume-evaluator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CRE_VERSION', '1.0.0' );
define( 'CRE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CRE_PLUGIN_DIR . 'includes/class-docx-parser.php';
require_once CRE_PLUGIN_DIR . 'includes/class-grok-api.php';
require_once CRE_PLUGIN_DIR . 'includes/class-resume-evaluator.php';

/**
 * Initialise the plugin.
 */
function cre_init() {
	$evaluator = new CRE_Resume_Evaluator();
	$evaluator->init();
}
add_action( 'plugins_loaded', 'cre_init' );
