<?php

/**
 * Database schema for the Event Controller job queue (Phase 1 infrastructure).
 *
 * @link       https://summitbhc.com/
 * @since      1.3.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/includes/db
 */

class EC_Schema {

	const DB_VERSION_OPTION = 'ec_db_version';
	const DB_VERSION        = '1.2.0';

	/**
	 * Create (or upgrade) the ec_jobs and ec_site_jobs tables.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$jobs_table       = $wpdb->prefix . 'ec_jobs';
		$site_jobs_table  = $wpdb->prefix . 'ec_site_jobs';

		$sql_jobs = "CREATE TABLE {$jobs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			idempotency_key CHAR(64) NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'queued',
			created_by BIGINT UNSIGNED NOT NULL,
			event_title VARCHAR(255) NOT NULL DEFAULT '',
			event_payload LONGTEXT NOT NULL,
			media_path VARCHAR(255) DEFAULT NULL,
			media_meta TEXT DEFAULT NULL,
			total_sites SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			succeeded_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			failed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			completed_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_idem (idempotency_key),
			KEY idx_status_created (status, created_at)
		) {$charset_collate};";

		dbDelta( $sql_jobs );

		$sql_site_jobs = "CREATE TABLE {$site_jobs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL,
			site_key VARCHAR(100) NOT NULL,
			site_name VARCHAR(255) NOT NULL DEFAULT '',
			site_url VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			phase VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
			remote_media_id BIGINT UNSIGNED DEFAULT NULL,
			remote_event_id BIGINT UNSIGNED DEFAULT NULL,
			last_http_status SMALLINT UNSIGNED DEFAULT NULL,
			last_error_code VARCHAR(50) DEFAULT NULL,
			last_error_message TEXT DEFAULT NULL,
			last_attempt_at DATETIME DEFAULT NULL,
			next_attempt_at DATETIME DEFAULT NULL,
			completed_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_job_site (job_id, site_key),
			KEY idx_ready (status, next_attempt_at),
			KEY idx_job (job_id, status)
		) {$charset_collate};";

		dbDelta( $sql_site_jobs );

		$log_table = $wpdb->prefix . 'ec_job_log';

		$sql_log = "CREATE TABLE {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED DEFAULT NULL,
			site_job_id BIGINT UNSIGNED DEFAULT NULL,
			correlation_id VARCHAR(50) DEFAULT NULL,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			phase VARCHAR(20) DEFAULT NULL,
			message TEXT NOT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_job (job_id),
			KEY idx_site_job (site_job_id),
			KEY idx_created (created_at)
		) {$charset_collate};";

		dbDelta( $sql_log );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Re-run install() if the stored schema version is behind the current one.
	 * Covers cases where activation didn't fire (e.g. network-activated multisite).
	 */
	public static function maybe_upgrade() {
		$installed_version = get_option( self::DB_VERSION_OPTION );

		if ( $installed_version !== self::DB_VERSION ) {
			self::install();

			// Before 1.2.0, ec_jobs.status was VARCHAR(20), too short for
			// 'completed_with_errors', so those jobs were stuck at 'processing'.
			if ( $installed_version && version_compare( $installed_version, '1.2.0', '<' ) ) {
				self::repair_stuck_jobs();
			}
		}
	}

	/**
	 * Re-run the status rollup for jobs still marked queued/processing whose
	 * site jobs are all terminal.
	 */
	private static function repair_stuck_jobs() {
		global $wpdb;

		$jobs_table      = $wpdb->prefix . 'ec_jobs';
		$site_jobs_table = $wpdb->prefix . 'ec_site_jobs';

		$job_ids = $wpdb->get_col(
			"SELECT j.id FROM {$jobs_table} j
			WHERE j.status IN ('queued', 'processing')
			AND NOT EXISTS (
				SELECT 1 FROM {$site_jobs_table} s
				WHERE s.job_id = j.id AND s.status NOT IN ('complete', 'failed', 'cancelled')
			)"
		);

		$job_manager = new EC_Job_Manager();
		foreach ( $job_ids as $job_id ) {
			$job_manager->rollup_job_counters( (int) $job_id );
		}
	}

	/**
	 * Drop all plugin tables. Used by uninstall.php only.
	 */
	public static function drop_tables() {
		global $wpdb;

		$jobs_table      = $wpdb->prefix . 'ec_jobs';
		$site_jobs_table = $wpdb->prefix . 'ec_site_jobs';
		$log_table       = $wpdb->prefix . 'ec_job_log';

		$wpdb->query( "DROP TABLE IF EXISTS {$log_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$site_jobs_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$jobs_table}" );

		delete_option( self::DB_VERSION_OPTION );
	}
}
