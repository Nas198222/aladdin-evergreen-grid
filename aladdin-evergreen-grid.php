<?php
/**
 * Plugin Name:       Aladdin Evergreen Grid
 * Plugin URI:        https://github.com/Nas198222/aladdin-evergreen-grid
 * Description:       Universal Gutenberg content grid block for Aladdin Mediterranean Cuisine. Displays any post type (recipes, blog posts, locations, products, etc.) with filters, search, and pagination. Independent of Brett Shumaker's aladdins-customizations plugin.
 * Version:           0.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Aladdin Mediterranean Cuisine
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aladdin-evergreen-grid
 *
 * @package Aladdin_Evergreen_Grid
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AEG_VERSION', '0.4.0' );
define( 'AEG_PATH', plugin_dir_path( __FILE__ ) );
define( 'AEG_URL', plugin_dir_url( __FILE__ ) );
define( 'AEG_FILE', __FILE__ );

register_activation_hook( AEG_FILE, function () {
	// Reserve for future activation logic (rewrite rules, options init).
	flush_rewrite_rules();
} );

register_deactivation_hook( AEG_FILE, function () {
	flush_rewrite_rules();
} );

require_once AEG_PATH . 'includes/class-aeg-helpers.php';
require_once AEG_PATH . 'includes/class-aeg-rest-endpoint.php';
require_once AEG_PATH . 'includes/class-aeg-block-registration.php';

/**
 * Bootstraps the plugin once all plugins are loaded.
 *
 * @return void
 */
function aeg_bootstrap() {
	AEG_REST_Endpoint::init();
	AEG_Block_Registration::init();
}
add_action( 'plugins_loaded', 'aeg_bootstrap' );
