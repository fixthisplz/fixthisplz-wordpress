<?php
/**
 * Plugin Name:       FixThisPlz
 * Plugin URI:        https://github.com/fixthisplz/fixthisplz-wordpress
 * Description:       Lets visitors and editors report bugs from any page of a WordPress site.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            dlicha
 * Text Domain:       fixthisplz
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'FIXTHISPLZ_VERSION', '1.2.0' );
define( 'FIXTHISPLZ_FILE', __FILE__ );
define( 'FIXTHISPLZ_DIR', plugin_dir_path( __FILE__ ) );
define( 'FIXTHISPLZ_URL', plugin_dir_url( __FILE__ ) );

require_once FIXTHISPLZ_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'FixThisPlz\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FixThisPlz\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'FixThisPlz\\Plugin', 'boot' ) );
