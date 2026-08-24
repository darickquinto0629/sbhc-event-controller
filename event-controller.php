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
 * Version:           1.8.1
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
define( 'EVENT_CONTROLLER_VERSION', '1.8.0' );

/**
 * Action Scheduler must be required unconditionally, as early as possible, and
 * NOT inside a hook callback - this is Action Scheduler's own documented
 * standalone integration requirement. Its bootstrap file self-registers with
 * ActionScheduler_Versions, which arbitrates between every copy loaded by any
 * active plugin/theme and initializes only the newest one. This means Event
 * Controller works correctly whether it ships the only copy on the site or
 * one of several (e.g. alongside WooCommerce) without any conflict.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/vendor/action-scheduler/action-scheduler.php';

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
 * Job queue infrastructure (Phase 1, see EVENT-CONTROLLER-ARCHITECTURE-REVIEW.md)
 */
require plugin_dir_path( __FILE__ ) . 'includes/db/class-ec-schema.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-ec-site-registry.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-ec-logger.php';
require plugin_dir_path( __FILE__ ) . 'includes/jobs/class-ec-job-manager.php';
require plugin_dir_path( __FILE__ ) . 'includes/rest/class-ec-rest-controller.php';

add_action( 'ec_logger_purge_old_logs', array( 'EC_Logger', 'purge_old_logs' ) );
if ( ! wp_next_scheduled( 'ec_logger_purge_old_logs' ) ) {
	wp_schedule_event( time(), 'daily', 'ec_logger_purge_old_logs' );
}

/**
 * Single-site Events Manager API client + payload transformer (Phase 3)
 */
require plugin_dir_path( __FILE__ ) . 'includes/api/class-ec-payload-transformer.php';
require plugin_dir_path( __FILE__ ) . 'includes/api/class-ec-events-api-client.php';

/**
 * Error classification, retry backoff, circuit breaker, reconciliation (Phase 4)
 */
require plugin_dir_path( __FILE__ ) . 'includes/retry/class-ec-error-classifier.php';
require plugin_dir_path( __FILE__ ) . 'includes/retry/class-ec-retry-policy.php';
require plugin_dir_path( __FILE__ ) . 'includes/retry/class-ec-circuit-breaker.php';
require plugin_dir_path( __FILE__ ) . 'includes/retry/class-ec-reconciler.php';

/**
 * Background delivery worker (Phase 2 plumbing + Phase 3 real delivery)
 */
require plugin_dir_path( __FILE__ ) . 'includes/jobs/class-ec-site-job-repository.php';
require plugin_dir_path( __FILE__ ) . 'includes/jobs/class-ec-delivery-worker.php';
add_action( 'plugins_loaded', array( 'EC_Schema', 'maybe_upgrade' ) );

add_action( 'plugins_loaded', function() {
	new EC_Delivery_Worker();
} );

add_action( 'rest_api_init', function() {
	$ec_rest_controller = new EC_REST_Controller();
	$ec_rest_controller->register_routes();
} );

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
