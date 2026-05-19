<?php
/**
 * Plugin Name:       Cegem360 Revit Library
 * Plugin URI:        https://cegem360.hu/
 * Description:       Lead-gated Revit library download with admin submission list, time-limited download tokens and a private file manager.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cegem360
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cegem360-revit-library
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CRL_VERSION', '1.0.0' );
define( 'CRL_PLUGIN_FILE', __FILE__ );
define( 'CRL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CRL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once CRL_PLUGIN_DIR . 'includes/helpers.php';
require_once CRL_PLUGIN_DIR . 'includes/class-activator.php';
require_once CRL_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once CRL_PLUGIN_DIR . 'includes/class-plugin.php';
require_once CRL_PLUGIN_DIR . 'includes/class-submissions.php';
require_once CRL_PLUGIN_DIR . 'includes/class-tokens.php';
require_once CRL_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once CRL_PLUGIN_DIR . 'includes/class-zip-manager.php';
require_once CRL_PLUGIN_DIR . 'includes/class-file-manager.php';
require_once CRL_PLUGIN_DIR . 'includes/class-mailer.php';
require_once CRL_PLUGIN_DIR . 'includes/class-form.php';
require_once CRL_PLUGIN_DIR . 'includes/class-download-handler.php';
require_once CRL_PLUGIN_DIR . 'includes/class-admin.php';
require_once CRL_PLUGIN_DIR . 'includes/class-settings.php';
require_once CRL_PLUGIN_DIR . 'includes/class-submissions-list-table.php';

register_activation_hook( __FILE__, array( 'CRL_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CRL_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', function() {
    CRL_Plugin::instance()->init();
} );
