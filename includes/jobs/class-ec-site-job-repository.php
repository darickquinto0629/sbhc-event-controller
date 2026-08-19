<?php

/**
 * All SQL access to the ec_site_jobs table, including the atomic claim
 * operation that prevents two workers (or a duplicate Action Scheduler
 * dispatch) from processing the same site job twice.
 *
 * @link       https://summitbhc.com/
 * @since      1.4.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/jobs
 */

class EC_Site_Job_Repository {

	/** @var string */
	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ec_site_jobs';
	}

	/**
	 * @param int $site_job_id
	 * @return array|null
	 */
	public function get( $site_job_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $site_job_id ),
			ARRAY_A
		);
	}

	/**
	 * Atomically transition a queued/retrying site job to 'processing'.
	 *
	 * The WHERE clause is the claim: only a row still in 'queued' or 'retrying'
	 * can be claimed, so if two workers (or a stray duplicate Action Scheduler
	 * dispatch) call this for the same site_job_id at the same time, only one
	 * UPDATE affects a row - the other gets 0 affected rows and must not proceed.
	 *
	 * @param int $site_job_id
	 * @return bool True if this call won the claim.
	 */
	public function claim( $site_job_id ) {
		global $wpdb;

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				SET status = 'processing', attempts = attempts + 1, last_attempt_at = %s
				WHERE id = %d AND status IN ('queued', 'retrying')",
				current_time( 'mysql', true ),
				$site_job_id
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Update a site job's status plus any additional columns.
	 *
	 * @param int    $site_job_id
	 * @param string $status
	 * @param array  $fields Additional column => value pairs.
	 */
	public function mark_status( $site_job_id, $status, array $fields = array() ) {
		global $wpdb;

		$fields['status'] = $status;

		$formats = array();
		foreach ( $fields as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		$wpdb->update( $this->table, $fields, array( 'id' => $site_job_id ), $formats, array( '%d' ) );
	}
}
