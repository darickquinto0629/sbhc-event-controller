<?php

/**
 * Job detail admin view: live overall progress bar, per-site status table
 * (status, attempts, remote event ID, next retry, error detail), retry
 * failed sites, and cancel job (Phase 5 - Admin UI & Observability).
 *
 * @link       https://summitbhc.com/
 * @since      1.7.0
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$job_id      = isset( $_GET['job_id'] ) ? (int) $_GET['job_id'] : 0;
$job_manager = new EC_Job_Manager();
$job         = $job_id ? $job_manager->get_job_status( $job_id ) : false;
?>
<div class="wrap">
	<h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=event-controller-jobs' ) ); ?>">&larr; Jobs</a>
	</h1>

	<?php if ( ! $job ) : ?>
		<p>Job not found.</p>
	<?php else : ?>
		<div id="ec-job-detail" data-job-id="<?php echo esc_attr( $job_id ); ?>">
			<h2>Job #<?php echo esc_html( $job_id ); ?></h2>

			<div class="ec-progress-bar-wrap" style="background:#e5e5e5; border-radius:4px; height:24px; max-width:600px; margin-bottom:10px;">
				<div id="ec-progress-bar" style="background:#2271b1; height:100%; border-radius:4px; width:0%;"></div>
			</div>
			<p id="ec-progress-text"></p>

			<p>
				<span class="ec-badge">&check; <span id="ec-count-succeeded">0</span> succeeded</span>
				&nbsp;&nbsp;
				<span class="ec-badge">&#8635; <span id="ec-count-processing">0</span> processing</span>
				&nbsp;&nbsp;
				<span class="ec-badge">&#8646; <span id="ec-count-retrying">0</span> retrying</span>
				&nbsp;&nbsp;
				<span class="ec-badge">&cross; <span id="ec-count-failed">0</span> failed</span>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Site</th>
						<th>Status</th>
						<th>Try</th>
						<th>Remote ID</th>
						<th>Next retry</th>
						<th>Detail</th>
					</tr>
				</thead>
				<tbody id="ec-site-rows"></tbody>
			</table>

			<p class="submit">
				<button id="ec-retry-failed" class="button">Retry failed sites</button>
				<button id="ec-cancel-job" class="button">Cancel job</button>
			</p>
		</div>

		<script>
			var ecJobDetailInitial = <?php echo wp_json_encode( $job ); ?>;
		</script>
	<?php endif; ?>
</div>
