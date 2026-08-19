<?php

/**
 * "Jobs" admin page - lists recent event delivery jobs, or (when ?job_id=
 * is present) delegates to the single-job detail view with the live
 * progress bar and per-site table (Phase 5).
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

if ( isset( $_GET['job_id'] ) ) {
	require __DIR__ . '/event-controller-admin-job-detail.php';
	return;
}

$job_manager = new EC_Job_Manager();
$jobs        = $job_manager->list_jobs( 20 );
?>
<div class="wrap">
	<h1>Event Jobs</h1>

	<table class="widefat striped">
		<thead>
			<tr>
				<th>Job</th>
				<th>Event</th>
				<th>Status</th>
				<th>Progress</th>
				<th>Created</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $jobs ) ) : ?>
				<tr><td colspan="5">No jobs yet.</td></tr>
			<?php else : ?>
				<?php foreach ( $jobs as $job ) : ?>
					<tr>
						<td>#<?php echo esc_html( $job['id'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=event-controller-jobs&job_id=' . $job['id'] ) ); ?>">
								<?php echo esc_html( $job['event_title'] ); ?>
							</a>
						</td>
						<td><?php echo esc_html( $job['status'] ); ?></td>
						<td><?php echo esc_html( $job['succeeded_count'] . ' / ' . $job['total_sites'] ); ?></td>
						<td><?php echo esc_html( $job['created_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
