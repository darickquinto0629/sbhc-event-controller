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

$per_page     = 20;
$job_manager  = new EC_Job_Manager();
$total_jobs   = $job_manager->count_jobs();
$total_pages  = max( 1, (int) ceil( $total_jobs / $per_page ) );
$current_page = isset( $_GET['paged'] ) ? min( $total_pages, max( 1, absint( $_GET['paged'] ) ) ) : 1;
$jobs         = $job_manager->list_jobs( $per_page, ( $current_page - 1 ) * $per_page );

$pagination = paginate_links(
	array(
		'base'      => add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=event-controller-jobs' ) ),
		'format'    => '',
		'current'   => $current_page,
		'total'     => $total_pages,
		'prev_text' => '&laquo;',
		'next_text' => '&raquo;',
	)
);
?>
<div class="wrap">
	<h1>Event Jobs</h1>

	<?php if ( isset( $_GET['ec_deleted'] ) ) : ?>
		<?php $deleted_count = absint( $_GET['ec_deleted'] ); ?>
		<div class="notice notice-<?php echo $deleted_count ? 'success' : 'warning'; ?> is-dismissible">
			<p>
				<?php
				echo esc_html(
					$deleted_count
						? sprintf( _n( 'Deleted %d job.', 'Deleted %d jobs.', $deleted_count, 'event-controller' ), $deleted_count )
						: __( 'No jobs were deleted. Select at least one finished job.', 'event-controller' )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ec_delete_jobs" />
		<input type="hidden" name="paged" value="<?php echo esc_attr( $current_page ); ?>" />
		<?php wp_nonce_field( 'ec_delete_jobs' ); ?>

		<div class="tablenav top">
			<div class="alignleft actions">
				<button type="submit" name="ec_delete_selected" class="button" <?php disabled( empty( $jobs ) ); ?>
					onclick="return confirm('<?php echo esc_js( __( 'Permanently delete the selected jobs?', 'event-controller' ) ); ?>');">
					Delete selected
				</button>
				<button type="submit" name="ec_clear_all" class="button button-link-delete" <?php disabled( 0 === $total_jobs ); ?>
					onclick="return confirm('<?php echo esc_js( sprintf( __( 'Permanently delete ALL %d jobs? Jobs still queued or processing will be cancelled first. This cannot be undone.', 'event-controller' ), $total_jobs ) ); ?>');">
					Clear all jobs
				</button>
			</div>
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( sprintf( _n( '%d job', '%d jobs', $total_jobs, 'event-controller' ), $total_jobs ) ); ?></span>
				<?php if ( $pagination ) : ?>
					<span class="pagination-links"><?php echo wp_kses_post( $pagination ); ?></span>
				<?php endif; ?>
			</div>
			<br class="clear" />
		</div>

		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" aria-label="Select all finished jobs" />
					</td>
					<th>Job</th>
					<th>Event</th>
					<th>Status</th>
					<th>Progress</th>
					<th>Created</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $jobs ) ) : ?>
					<tr><td colspan="6">No jobs yet.</td></tr>
				<?php else : ?>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<th scope="row" class="check-column">
								<?php if ( EC_Job_Manager::is_finished_status( $job['status'] ) ) : ?>
									<input type="checkbox" name="job_ids[]" value="<?php echo esc_attr( $job['id'] ); ?>" aria-label="<?php echo esc_attr( 'Select job #' . $job['id'] ); ?>" />
								<?php endif; ?>
							</th>
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
	</form>
	<p class="description">Only finished jobs (completed, completed with errors, failed, cancelled) can be selected. "Clear all jobs" also cancels and removes jobs that are still queued or processing.</p>
</div>
