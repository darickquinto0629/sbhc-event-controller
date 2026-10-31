<?php

/**
 * Job creation and status lookup for the queued event-delivery pipeline.
 *
 * Phase 1 scope only: creates durable ec_jobs / ec_site_jobs rows and persists
 * uploaded media to a private directory. No worker exists yet (Phase 2+), so
 * jobs created here are not processed until a later phase wires up delivery.
 *
 * @link       https://summitbhc.com/
 * @since      1.3.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/jobs
 */

class EC_Job_Manager {

	/** Job statuses with nothing left to deliver (see rollup_job_counters() and cancel_job()). */
	const FINISHED_STATUSES = array( 'completed', 'completed_with_errors', 'failed', 'cancelled' );

	/** @var string */
	private $jobs_table;

	/** @var string */
	private $site_jobs_table;

	public function __construct() {
		global $wpdb;

		$this->jobs_table      = $wpdb->prefix . 'ec_jobs';
		$this->site_jobs_table = $wpdb->prefix . 'ec_site_jobs';
	}

	/**
	 * Create a new job, or return the existing one if this exact submission
	 * (same sites + same event data) already has a job on record.
	 *
	 * @param array      $selected_sites Array of site_key strings.
	 * @param array      $event_data     Raw event form data.
	 * @param array|null $file           $_FILES-style entry for the featured image, or null.
	 * @param int        $user_id        Current user ID (job creator).
	 * @return array|WP_Error Job status array (see get_job_status()) or WP_Error on failure.
	 */
	public function create( $selected_sites, $event_data, $file, $user_id ) {
		global $wpdb;

		$idempotency_key = hash(
			'sha256',
			wp_json_encode(
				array(
					'sites' => $selected_sites,
					'data'  => $event_data,
				)
			)
		);

		$existing = $this->find_by_idempotency_key( $idempotency_key );
		if ( $existing ) {
			$existing['is_duplicate'] = true;
			return $existing;
		}

		$media_path = null;
		if ( $file && ! empty( $file['tmp_name'] ) && empty( $file['error'] ) ) {
			$media_path = $this->persist_media( $file, $idempotency_key );
			if ( is_wp_error( $media_path ) ) {
				return $media_path;
			}
		}

		$sites = EC_Site_Registry::get_all();
		$now   = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$this->jobs_table,
			array(
				'idempotency_key' => $idempotency_key,
				'status'          => 'queued',
				'created_by'      => $user_id,
				'event_title'     => isset( $event_data['event_title'] ) ? sanitize_text_field( $event_data['event_title'] ) : '',
				'event_payload'   => wp_json_encode( $event_data ),
				'media_path'      => $media_path,
				'total_sites'     => count( $selected_sites ),
				'created_at'      => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			// Unique-key collision: another request won the race between our
			// lookup and this insert. Its job is authoritative; return it.
			$existing = $this->find_by_idempotency_key( $idempotency_key );
			if ( $existing ) {
				$existing['is_duplicate'] = true;
				return $existing;
			}

			return new WP_Error( 'ec_job_create_failed', 'Failed to create job', array( 'status' => 500 ) );
		}

		$job_id = (int) $wpdb->insert_id;

		foreach ( $selected_sites as $site_key ) {
			$site_key = sanitize_text_field( $site_key );
			$site     = isset( $sites[ $site_key ] ) ? $sites[ $site_key ] : false;

			if ( ! $site ) {
				$wpdb->insert(
					$this->site_jobs_table,
					array(
						'job_id'             => $job_id,
						'site_key'           => $site_key,
						'site_name'          => $site_key,
						'site_url'           => '',
						'status'             => 'failed',
						'phase'              => 'done',
						'last_error_code'    => 'site_not_configured',
						'last_error_message' => 'Site configuration not found for: ' . $site_key,
						'completed_at'       => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);

				continue;
			}

			$wpdb->insert(
				$this->site_jobs_table,
				array(
					'job_id'    => $job_id,
					'site_key'  => $site_key,
					'site_name' => $site['name'],
					'site_url'  => $site['url'],
					'status'    => 'queued',
					'phase'     => 'pending',
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);

			// Hand off to the background worker (see EC_Delivery_Worker) - this
			// request's job is done the moment these rows are inserted.
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( EC_Delivery_Worker::ACTION_HOOK, array( (int) $wpdb->insert_id ), 'event-controller' );
			}
		}

		// Recompute counts/status from the actual site_job rows (also handles
		// the edge case where every selected site was misconfigured, so the
		// job should already show as failed rather than sitting at 'queued').
		$this->rollup_job_counters( $job_id );

		return $this->get_job_status( $job_id );
	}

	/**
	 * Recompute a job's succeeded/failed counts and overall status from its
	 * site_job rows, and mark the job terminal once every site is terminal.
	 * Called after every site_job status transition (see EC_Delivery_Worker).
	 *
	 * @param int $job_id
	 */
	public function rollup_job_counters( $job_id ) {
		global $wpdb;

		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN status = 'complete' THEN 1 ELSE 0 END) AS succeeded,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN status IN ('complete', 'failed', 'cancelled') THEN 1 ELSE 0 END) AS terminal
				FROM {$this->site_jobs_table} WHERE job_id = %d",
				$job_id
			),
			ARRAY_A
		);

		if ( ! $counts || (int) $counts['total'] === 0 ) {
			return;
		}

		$fields = array(
			'succeeded_count' => (int) $counts['succeeded'],
			'failed_count'    => (int) $counts['failed'],
		);

		if ( (int) $counts['terminal'] >= (int) $counts['total'] ) {
			$fields['status']       = ( (int) $counts['failed'] === (int) $counts['total'] )
				? 'failed'
				: ( (int) $counts['failed'] > 0 ? 'completed_with_errors' : 'completed' );
			$fields['completed_at'] = current_time( 'mysql', true );
		} else {
			$fields['status'] = 'processing';
		}

		$wpdb->update( $this->jobs_table, $fields, array( 'id' => $job_id ) );

		// Every site has the image now, and a fully completed job has no failed
		// sites left to retry, so the private copy is no longer needed.
		if ( 'completed' === $fields['status'] ) {
			$job = $this->get_raw_job( $job_id );
			if ( $job ) {
				$this->delete_media( $job['media_path'] );
			}
		}
	}

	/**
	 * Fetch the raw ec_jobs row (event_payload, media_path, etc.) - unlike
	 * get_job_status(), this does not include the nested per-site array.
	 *
	 * @param int $job_id
	 * @return array|null
	 */
	public function get_raw_job( $job_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->jobs_table} WHERE id = %d", $job_id ),
			ARRAY_A
		);
	}

	/**
	 * Recent jobs for the admin job-list screen (Phase 5).
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array
	 */
	public function list_jobs( $limit = 20, $offset = 0 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_title, status, total_sites, succeeded_count, failed_count, created_at, completed_at
				FROM {$this->jobs_table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		// $wpdb returns every column as a string - cast numeric fields for
		// consistency with format_job() (see the Phase 4 Action Scheduler
		// args type-mismatch lesson: strict-comparison consumers elsewhere
		// can silently break on a string where an int was expected).
		foreach ( $rows as &$row ) {
			$row['id']              = (int) $row['id'];
			$row['total_sites']     = (int) $row['total_sites'];
			$row['succeeded_count'] = (int) $row['succeeded_count'];
			$row['failed_count']    = (int) $row['failed_count'];
		}

		return $rows;
	}

	/**
	 * Total number of jobs, for the admin job-list pagination.
	 *
	 * @return int
	 */
	public function count_jobs() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->jobs_table}" );
	}

	/**
	 * Whether a job status is terminal (nothing left to deliver).
	 *
	 * @param string $status
	 * @return bool
	 */
	public static function is_finished_status( $status ) {
		return in_array( $status, self::FINISHED_STATUSES, true );
	}

	/**
	 * Permanently delete the given jobs (the "Delete selected" admin action).
	 * Only finished jobs are deleted; any queued/processing job in the list is
	 * skipped so a delivery in progress is never pulled out from under the worker.
	 *
	 * @param int[] $job_ids
	 * @return int Number of jobs deleted.
	 */
	public function delete_jobs( array $job_ids ) {
		global $wpdb;

		$job_ids = array_values( array_filter( array_unique( array_map( 'absint', $job_ids ) ) ) );
		if ( empty( $job_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, status, media_path FROM {$this->jobs_table} WHERE id IN ({$placeholders})", $job_ids ),
			ARRAY_A
		);

		return $this->purge_jobs( $rows, true );
	}

	/**
	 * Permanently delete every job (the "Clear all jobs" admin action).
	 * Jobs still queued/processing are cancelled first, which unschedules
	 * their pending Action Scheduler actions, then deleted like the rest.
	 *
	 * @return int Number of jobs deleted.
	 */
	public function delete_all_jobs() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT id, status, media_path FROM {$this->jobs_table}", ARRAY_A );

		return $this->purge_jobs( $rows, false );
	}

	/**
	 * Delete jobs along with their site jobs, log entries and private media.
	 *
	 * @param array $rows          ec_jobs rows (id, status, media_path).
	 * @param bool  $finished_only Skip jobs that are not finished instead of cancelling them.
	 * @return int Number of jobs deleted.
	 */
	private function purge_jobs( array $rows, $finished_only ) {
		global $wpdb;

		$log_table = $wpdb->prefix . 'ec_job_log';
		$deleted   = 0;

		foreach ( $rows as $row ) {
			$job_id = (int) $row['id'];

			if ( ! self::is_finished_status( $row['status'] ) ) {
				if ( $finished_only ) {
					continue;
				}
				$this->cancel_job( $job_id );
			}

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$log_table} WHERE job_id = %d OR site_job_id IN (SELECT id FROM {$this->site_jobs_table} WHERE job_id = %d)",
					$job_id,
					$job_id
				)
			);
			$wpdb->delete( $this->site_jobs_table, array( 'job_id' => $job_id ), array( '%d' ) );

			if ( $wpdb->delete( $this->jobs_table, array( 'id' => $job_id ), array( '%d' ) ) ) {
				$deleted++;
			}

			$this->delete_media( $row['media_path'] );
		}

		return $deleted;
	}

	/**
	 * Remove a job's persisted featured image and its per-job directory
	 * (see persist_media()). Only ever touches uploads/ec-private/{key}/.
	 *
	 * @param string|null $media_path
	 */
	private function delete_media( $media_path ) {
		if ( empty( $media_path ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . 'ec-private' );
		$job_dir    = wp_normalize_path( dirname( $media_path ) );

		if ( dirname( $job_dir ) !== $base_dir || ! is_dir( $job_dir ) ) {
			return;
		}

		foreach ( (array) glob( $job_dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		@rmdir( $job_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Re-queue every 'failed' site job for a job (the "Retry failed sites"
	 * admin action, Phase 5). Sites that already succeeded, or are still
	 * in-flight, are left untouched. Resets attempts to 0 for a clean cycle
	 * since this is an explicit human decision, not an automatic backoff.
	 *
	 * @param int $job_id
	 * @return int Number of site jobs re-queued.
	 */
	public function retry_failed_sites( $job_id ) {
		global $wpdb;

		$failed_site_jobs = $wpdb->get_results(
			$wpdb->prepare( "SELECT id FROM {$this->site_jobs_table} WHERE job_id = %d AND status = 'failed'", $job_id ),
			ARRAY_A
		);

		foreach ( $failed_site_jobs as $row ) {
			$site_job_id = (int) $row['id'];

			$wpdb->update(
				$this->site_jobs_table,
				array(
					'status'             => 'queued',
					'attempts'           => 0,
					'last_error_code'    => null,
					'last_error_message' => null,
					'last_http_status'   => null,
					'next_attempt_at'    => null,
					'completed_at'       => null,
				),
				array( 'id' => $site_job_id )
			);

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( EC_Delivery_Worker::ACTION_HOOK, array( $site_job_id ), 'event-controller' );
			}
		}

		$this->rollup_job_counters( $job_id );

		return count( $failed_site_jobs );
	}

	/**
	 * Cancel a job (the "Cancel job" admin action, Phase 5). Every site job
	 * not already terminal is marked 'cancelled' and any pending Action
	 * Scheduler action for it is unscheduled (best-effort - an action already
	 * claimed/running cannot be stopped mid-flight, it will simply find the
	 * site job already terminal and no-op via EC_Site_Job_Repository::claim()).
	 *
	 * @param int $job_id
	 * @return int Number of site jobs cancelled.
	 */
	public function cancel_job( $job_id ) {
		global $wpdb;

		$active_site_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$this->site_jobs_table} WHERE job_id = %d AND status NOT IN ('complete', 'failed', 'cancelled')",
				$job_id
			),
			ARRAY_A
		);

		foreach ( $active_site_jobs as $row ) {
			$site_job_id = (int) $row['id'];

			$wpdb->update(
				$this->site_jobs_table,
				array(
					'status'       => 'cancelled',
					'completed_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $site_job_id )
			);

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( EC_Delivery_Worker::ACTION_HOOK, array( $site_job_id ), 'event-controller' );
				as_unschedule_all_actions( EC_Delivery_Worker::RECONCILE_HOOK, array( $site_job_id ), 'event-controller' );
			}
		}

		$wpdb->update(
			$this->jobs_table,
			array(
				'status'       => 'cancelled',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id )
		);

		return count( $active_site_jobs );
	}

	/**
	 * Fetch a job and its per-site rows by job ID.
	 *
	 * @param int $job_id
	 * @return array|false
	 */
	public function get_job_status( $job_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->jobs_table} WHERE id = %d", $job_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return false;
		}

		return $this->format_job( $row );
	}

	/**
	 * @param string $idempotency_key
	 * @return array|false
	 */
	private function find_by_idempotency_key( $idempotency_key ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->jobs_table} WHERE idempotency_key = %s", $idempotency_key ),
			ARRAY_A
		);

		if ( ! $row ) {
			return false;
		}

		return $this->format_job( $row );
	}

	/**
	 * @param array $row Raw ec_jobs row.
	 * @return array
	 */
	private function format_job( $row ) {
		global $wpdb;

		$sites = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT site_key, site_name, status, phase, attempts, remote_event_id, last_error_message, last_attempt_at, next_attempt_at
				FROM {$this->site_jobs_table} WHERE job_id = %d ORDER BY id ASC",
				$row['id']
			),
			ARRAY_A
		);

		return array(
			'job_id'          => (int) $row['id'],
			'idempotency_key' => $row['idempotency_key'],
			'status'          => $row['status'],
			'total_sites'     => (int) $row['total_sites'],
			'succeeded_count' => (int) $row['succeeded_count'],
			'failed_count'    => (int) $row['failed_count'],
			'created_at'      => $row['created_at'],
			'completed_at'    => $row['completed_at'],
			'sites'           => $sites,
			'is_duplicate'    => false,
		);
	}

	/**
	 * Copy an uploaded file out of PHP's ephemeral tmp location into a
	 * private, non-web-indexed directory so a background worker (Phase 2+)
	 * can still read it after this request ends.
	 *
	 * @param array  $file            $_FILES-style entry.
	 * @param string $idempotency_key Used as the storage sub-directory name.
	 * @return string|WP_Error Absolute path to the persisted file.
	 */
	private function persist_media( $file, $idempotency_key ) {
		if ( ! isset( $file['tmp_name'], $file['name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'ec_invalid_upload', 'Invalid file upload', array( 'status' => 400 ) );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'ec_upload_dir_error', $upload_dir['error'], array( 'status' => 500 ) );
		}

		$base_dir = trailingslashit( $upload_dir['basedir'] ) . 'ec-private';
		$job_dir  = trailingslashit( $base_dir ) . $idempotency_key;

		if ( ! wp_mkdir_p( $job_dir ) ) {
			return new WP_Error( 'ec_mkdir_failed', 'Could not create private media directory', array( 'status' => 500 ) );
		}

		$this->guard_directory( $base_dir );

		$filename = sanitize_file_name( $file['name'] );
		$dest     = trailingslashit( $job_dir ) . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'ec_move_upload_failed', 'Could not persist uploaded file', array( 'status' => 500 ) );
		}

		return $dest;
	}

	/**
	 * Block direct web access to the private media directory.
	 *
	 * @param string $dir
	 */
	private function guard_directory( $dir ) {
		$index_file = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php // Silence is golden\n" );
		}

		$htaccess_file = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Deny from all\n" );
		}
	}
}
