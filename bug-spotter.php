<?php
/**
 * Plugin Name:       Bug Spotter
 * Description:       Lets visitors and editors report bugs from any page of a WordPress site.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Text Domain:       bug-spotter
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'BUGSPOT_VERSION', '1.2.0' );
define( 'BUGSPOT_FILE', __FILE__ );
define( 'BUGSPOT_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUGSPOT_URL', plugin_dir_url( __FILE__ ) );

require_once BUGSPOT_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'Bug_Spotter\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Bug_Spotter\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Bug_Spotter\\Plugin', 'boot' ) );
