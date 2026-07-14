<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://https://summitbhc.com/
 * @since             1.0.0
 * @package           Event_Controller
 *
 * @wordpress-plugin
 * Plugin Name:       Event Controller
 * Plugin URI:        https://https://summitbhc.com/
 * Description:       This plugin manages and controls event dispatching to remote event client endpoints.
 * Version:           1.1.1
 * Author:            SBHC
 * Author URI:        https://https://summitbhc.com//
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       event-controller
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'EVENT_CONTROLLER_VERSION', '1.1.1' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-event-controller-activator.php
 */
function activate_event_controller() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-event-controller-activator.php';
	Event_Controller_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-event-controller-deactivator.php
 */
function deactivate_event_controller() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-event-controller-deactivator.php';
	Event_Controller_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_event_controller' );
register_deactivation_hook( __FILE__, 'deactivate_event_controller' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-event-controller.php';

/**
 * Form submission handler
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-event-controller-form-handler.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_event_controller() {

	$plugin = new Event_Controller();
	$plugin->run();

}
run_event_controller();
