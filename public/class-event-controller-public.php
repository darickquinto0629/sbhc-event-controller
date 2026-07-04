<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://https://summitbhc.com/
 * @since      1.0.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/public
 * @author     SBHC <darickquinto@gmail.com>
 */
class Event_Controller_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;
		$this->event_controller_functionalities();

	}

	/**
	 * Register Event Controller functionalities
	 *
	 * @since    1.0.0
	 */	

	public function event_controller_functionalities() { 

		// Send Events to client sites
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/partials/event-controller-post-event.php';	

		// Send Events to client sites
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/partials/event-controller-form-script.php';	

	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Event_Controller_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Event_Controller_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/event-controller-public.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Event_Controller_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Event_Controller_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/event-controller-public.js', array( 'jquery' ), $this->version, false );

		// Localize script data
		wp_localize_script(
			$this->plugin_name,
			'eventControllerData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'event_controller_submit' ),
			)
		);

	}

}
