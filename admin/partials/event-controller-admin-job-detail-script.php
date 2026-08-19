<?php
/**
 * Inline JS for the job detail admin view. Required directly from
 * Event_Controller_Admin::print_job_detail_script() on admin_footer
 * (priority 100), only when ?job_id= is present on the Jobs page.
 *
 * Polls GET /jobs/{id} every 3 seconds until the job reaches a terminal
 * status, then stops. "Retry failed sites" and "Cancel job" post to their
 * respective REST actions and re-render immediately from the response.
 *
 * @package    Event_Controller
 * @subpackage Event_Controller/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<script>
var ecAdminData = {
	restUrl: <?php echo wp_json_encode( esc_url_raw( rest_url( 'event-controller/v1' ) ) ); ?>,
	restNonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>
};
(function($) {
  const jobId = $('#ec-job-detail').data('job-id');
  if (!jobId) {
    return;
  }

  const TERMINAL_JOB_STATUSES = ['completed', 'completed_with_errors', 'failed', 'cancelled'];
  let pollTimer = null;

  function statusLabel(status) {
    const map = {
      complete: '\u2713 complete',
      processing: '\u21bb processing',
      queued: '\u23f3 queued',
      retrying: '\u21ba retrying',
      failed: '\u2715 failed',
      blocked: '\u26d4 blocked',
      ambiguous_pending: '\u2753 checking',
      needs_review: '\u26a0 needs review',
      cancelled: '\u2013 cancelled'
    };
    return map[status] || status;
  }

  function relativeTime(mysqlDate) {
    if (!mysqlDate) {
      return '\u2014';
    }
    const target = new Date(mysqlDate.replace(' ', 'T') + 'Z').getTime();
    const diffSeconds = Math.round((target - Date.now()) / 1000);
    if (diffSeconds <= 0) {
      return 'due';
    }
    if (diffSeconds < 60) {
      return 'in ' + diffSeconds + 's';
    }
    if (diffSeconds < 3600) {
      return 'in ' + Math.round(diffSeconds / 60) + 'm';
    }
    return 'in ' + Math.round(diffSeconds / 3600) + 'h';
  }

  function escapeHtml(text) {
    return $('<div>').text(text == null ? '' : text).html();
  }

  function render(job) {
    const total = job.total_sites || 0;
    const done = job.succeeded_count + job.failed_count;
    const pct = total ? Math.round((job.succeeded_count / total) * 100) : 0;

    $('#ec-progress-bar').css('width', pct + '%');
    $('#ec-progress-text').text(done + ' / ' + total);

    const counts = { processing: 0, retrying: 0, queued: 0, blocked: 0, ambiguous_pending: 0 };
    (job.sites || []).forEach(function(site) {
      if (Object.prototype.hasOwnProperty.call(counts, site.status)) {
        counts[site.status]++;
      }
    });

    $('#ec-count-succeeded').text(job.succeeded_count);
    $('#ec-count-processing').text(counts.processing + counts.queued + counts.ambiguous_pending);
    $('#ec-count-retrying').text(counts.retrying + counts.blocked);
    $('#ec-count-failed').text(job.failed_count);

    let rows = '';
    (job.sites || []).forEach(function(site) {
      rows += '<tr>'
        + '<td>' + escapeHtml(site.site_name) + '</td>'
        + '<td>' + statusLabel(site.status) + '</td>'
        + '<td>' + site.attempts + '</td>'
        + '<td>' + (site.remote_event_id ? '#' + site.remote_event_id : '\u2014') + '</td>'
        + '<td>' + relativeTime(site.next_attempt_at) + '</td>'
        + '<td>' + (site.last_error_message ? escapeHtml(site.last_error_message) : '\u2014') + '</td>'
        + '</tr>';
    });
    $('#ec-site-rows').html(rows);

    const hasFailed = (job.sites || []).some(function(site) { return site.status === 'failed'; });
    const isTerminal = TERMINAL_JOB_STATUSES.indexOf(job.status) !== -1;

    $('#ec-retry-failed').prop('disabled', !hasFailed);
    $('#ec-cancel-job').prop('disabled', isTerminal);

    return isTerminal;
  }

  function poll() {
    $.ajax({
      url: ecAdminData.restUrl + '/jobs/' + jobId,
      headers: { 'X-WP-Nonce': ecAdminData.restNonce },
      success: function(job) {
        const isTerminal = render(job);
        if (!isTerminal) {
          pollTimer = setTimeout(poll, 3000);
        }
      },
      error: function() {
        pollTimer = setTimeout(poll, 5000);
      }
    });
  }

  $('#ec-retry-failed').on('click', function() {
    $(this).prop('disabled', true);
    $.ajax({
      url: ecAdminData.restUrl + '/jobs/' + jobId + '/retry',
      type: 'POST',
      headers: { 'X-WP-Nonce': ecAdminData.restNonce },
      success: function(job) {
        render(job);
        clearTimeout(pollTimer);
        poll();
      }
    });
  });

  $('#ec-cancel-job').on('click', function() {
    if (!window.confirm('Cancel this job? Sites still in progress will be stopped.')) {
      return;
    }
    $(this).prop('disabled', true);
    $.ajax({
      url: ecAdminData.restUrl + '/jobs/' + jobId + '/cancel',
      type: 'POST',
      headers: { 'X-WP-Nonce': ecAdminData.restNonce },
      success: function(job) {
        clearTimeout(pollTimer);
        render(job);
      }
    });
  });

  if (typeof ecJobDetailInitial !== 'undefined') {
    const isTerminal = render(ecJobDetailInitial);
    if (!isTerminal) {
      pollTimer = setTimeout(poll, 3000);
    }
  } else {
    poll();
  }
})(jQuery);
</script>
