<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://https://summitbhc.com/
 * @since      1.0.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Event_Controller
 * @subpackage Event_Controller/includes
 * @author     SBHC <darickquinto@gmail.com>
 */
class Event_Controller_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {

		wp_clear_scheduled_hook( 'event_controller_cleanup_stale_options' );
		wp_clear_scheduled_hook( 'ec_logger_purge_old_logs' );

	}

}
