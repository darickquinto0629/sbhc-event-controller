<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://https://summitbhc.com/
 * @since      1.0.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/admin
 * @author     SBHC <darickquinto@gmail.com>
 */
class Event_Controller_Admin {

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
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the "Event Controller" admin menu: Post Event (the form,
	 * moved here from the front-end [events-form] shortcode) and Jobs
	 * (list + per-job progress detail). Phase 5.
	 *
	 * @since 1.7.0
	 */
	public function register_admin_menu() {

		add_menu_page(
			'Event Controller',
			'Event Controller',
			'manage_options',
			'event-controller',
			array( $this, 'render_post_event_page' ),
			'dashicons-calendar-alt',
			30
		);

		add_submenu_page(
			'event-controller',
			'Post Event',
			'Post Event',
			'manage_options',
			'event-controller',
			array( $this, 'render_post_event_page' )
		);

		add_submenu_page(
			'event-controller',
			'Jobs',
			'Jobs',
			'manage_options',
			'event-controller-jobs',
			array( $this, 'render_jobs_page' )
		);

	}

	/**
	 * @since 1.7.0
	 */
	public function render_post_event_page() {
		add_action( 'admin_footer', array( $this, 'print_post_event_script' ), 100 );
		require plugin_dir_path( __FILE__ ) . 'partials/event-controller-admin-post-event.php';
	}

	/**
	 * @since 1.7.0
	 */
	public function render_jobs_page() {
		if ( isset( $_GET['job_id'] ) ) {
			add_action( 'admin_footer', array( $this, 'print_job_detail_script' ), 100 );
		}
		require plugin_dir_path( __FILE__ ) . 'partials/event-controller-admin-job-list.php';
	}

	/**
	 * admin-post.php handler for the Jobs list "Delete selected" and
	 * "Clear all jobs" buttons. Redirects back to the list with a notice.
	 *
	 * @since 1.9.3
	 */
	public function handle_delete_jobs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to delete jobs.', 'event-controller' ), 403 );
		}

		check_admin_referer( 'ec_delete_jobs' );

		$job_manager = new EC_Job_Manager();

		if ( isset( $_POST['ec_clear_all'] ) ) {
			$deleted = $job_manager->delete_all_jobs();
		} else {
			$job_ids = isset( $_POST['job_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['job_ids'] ) ) : array();
			$deleted = $job_manager->delete_jobs( $job_ids );
		}

		$paged = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'event-controller-jobs',
					'paged'      => $paged,
					'ec_deleted' => $deleted,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @since 1.7.0
	 */
	public function print_post_event_script() {
		require plugin_dir_path( __FILE__ ) . 'partials/event-controller-admin-form-script.php';
	}

	/**
	 * @since 1.7.0
	 */
	public function print_job_detail_script() {
		require plugin_dir_path( __FILE__ ) . 'partials/event-controller-admin-job-detail-script.php';
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 * @param    string    $hook_suffix    The current admin page, passed by WordPress.
	 */
	public function enqueue_styles( $hook_suffix = '' ) {

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/event-controller-admin.css', array(), $this->version, 'all' );

		if ( in_array( $hook_suffix, array( 'toplevel_page_event-controller', 'event-controller_page_event-controller-jobs' ), true ) ) {
			wp_enqueue_style( 'ec-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', array(), '5.3.3' );
			wp_enqueue_style( 'ec-daterange-picker', 'https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css', array(), null );
		}

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 * @param    string    $hook_suffix    The current admin page, passed by WordPress.
	 */
	public function enqueue_scripts( $hook_suffix = '' ) {

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/event-controller-admin.js', array( 'jquery' ), $this->version, false );

		// Only load these heavy third-party assets on our own admin pages.
		if ( 'toplevel_page_event-controller' === $hook_suffix ) {
			wp_enqueue_script( 'ec-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', array(), '5.3.3', true );
			wp_enqueue_script( 'ec-drp-moment', 'https://cdn.jsdelivr.net/momentjs/latest/moment.min.js', array(), null, true );
			wp_enqueue_script( 'ec-daterange-picker', 'https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js', array( 'ec-drp-moment' ), null, true );
			// This is the TinyMCE API key from darickquinto@gmail.com - should be replaced before production.
			wp_enqueue_script( 'ec-wysiwyg', 'https://cdn.tiny.cloud/1/2gsxlj7p8c0istuycljhrr5v0vkef00u9iigmksvo3uiqfm5/tinymce/7/tinymce.min.js', array(), null, true );
		}

	}

}
