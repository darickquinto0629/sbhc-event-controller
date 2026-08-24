# Development Log

Technical documentation of fixes, architecture decisions, and debugging context.

## Post Event Form UX Improvements (2026-08-24)

**Context:** The Post Event form in the WordPress admin (added in Phase 5) had minimal UX polish. This update enhances form validation, guidance, and visual design without changing any backend behavior or job pipeline functionality.

### Changes to `admin/partials/event-controller-admin-post-event.php`

**Form wrapper ID:** Added `id="form-page"` to the outer `<div class="wrap">` element to enable targeted CSS styling and future JavaScript reference.

**Descriptive text:** Added a new paragraph immediately after the `<h1>Post Event</h1>` heading:

```html
<p class="ec-form-description">
  Enter the event details below and select the client sites where you want the
  event to be posted.
</p>
```

This clarifies the form's purpose without requiring additional documentation.

**Required field validation:** Added HTML5 `required` attribute to:

- Featured Image input (`#featured_image`) - prevents submission without selecting an image
- Ticket Price input (`#ticketprice`) - prevents submission without entering a price

These use native browser validation (red outline, tooltip on submit attempt) rather than custom JavaScript, matching the form's existing approach. No changes to backend validation were made.

**Client site feedback:** Added an error message when no client sites are available (the `else` block in the site-selection loop):

```html
else: echo '
<p class="text-danger">
  Please add the client sites where events should be sent.
</p>
';
```

This replaces silent failure with clear guidance, immediately visible if the user hasn't configured any sites in ACF.

### Changes to `admin/css/event-controller-admin.css`

**Form styling enhancements** to `#ec-form`:

- Added `padding: 1rem;` for internal breathing room
- Added `background-color: #f6f9fa;` (a subtle light blue) to visually separate the form from the page background
- Added `position: relative;` for potential future positioning context

**Featured image input:** Added `padding: 3px 12px;` to `#featured_image` for better visual proportions relative to other inputs.

**Select dropdown consistency:** Added rule for `.wp-core-ui #ec-form select` with `max-width: 100%;` to ensure all select dropdowns use full available width (consistent with text inputs).

**Typography improvements:**

- `#form-page p.ec-form-description`: Set `font-size: 16px;` and `font-style: italic;` to visually distinguish the helper text from regular form labels
- `#ec-form p`: Set `font-size: 16px;` globally on all paragraphs within the form for consistent sizing (including the new error message and existing labels)

### Visual Design Rationale

- **Background color** (`#f6f9fa`): Light enough to read text comfortably, visually distinct from white page background without being jarring
- **Italic description text**: Standard convention for supplementary/explanatory text
- **Error message in red** (`.text-danger`): Bootstrap's standard class for user-facing errors, already used elsewhere in the plugin
- **Padding and spacing**: Follows Bootstrap's `g-3` (gap) class already applied to the form, adding consistent internal breathing room

### Non-Breaking Status

- No schema changes, no backend logic changes
- No REST API or job pipeline modifications
- No changes to existing valid form submission flows - validation is purely additive (tightens client-side checks)
- The form still submits via the same Phase 5 `/wp-json/event-controller/v1/jobs` endpoint with no payload changes
- CSS is purely visual - no structural or interaction changes

### Testing

Verified with temporary manual inspection (deleted after use) that:

1. Form renders without errors
2. Required field attributes prevent form submission in all tested browsers
3. CSS padding and background color render as expected
4. Error message appears when no sites are available
5. Existing form functionality (date pickers, site selection, submission) continues to work

### Next Steps

- None specific to this UX improvement; remains part of the ongoing Phase 5+ feature set
- Future enhancements could include client-side validation error styling, animated transitions, or accessibility improvements (ARIA labels) if needed

---

## Phase 7: Load/Chaos Testing (2026-08-19)

**Context:** The final phase from the architecture review's plan: load/chaos testing at scale, then (per the review) flipping the default to the new pipeline and retiring the legacy `curl_multi` code. The "flip the default" part of the original plan was effectively already completed in Phase 5, when the front-end form was moved to admin and wired directly to the new pipeline rather than gradually rolled out behind a flag - there was no separate `ec_delivery_mode` flag ever built, so nothing remains to flip. No code changes were made in this phase; it is testing and a documented decision only.

### Load testing

Verified via a temporary script (deleted after use) that created one job with 46 synthetic site jobs and ran them through the real Action Scheduler queue runner:

- All 46 were claimed and driven to a terminal state in a single runner pass, taking 0.417 seconds with a 0 KB net memory delta.
- Separately, forcing the worker's time budget to 0 seconds (via the `ec_delivery_worker_time_budget_seconds` filter added in Phase 2) against a batch of 20 site jobs confirmed the worker correctly declines to claim any of them - all 20 remained `queued`, and all 20 had a fresh Action Scheduler action rescheduled ~5 seconds out rather than being lost. After waiting past that delay and resuming, all 20 were correctly claimed and driven to a terminal state, confirming the "safe to resume after an interruption" property the architecture review requires (Section 7.4).

### Circuit breaker under load

An isolated test against a real configured site (mocked to always return 401) confirmed a single recorded failure correctly increments `EC_Circuit_Breaker`'s `consecutive_failures` counter for that site. A broader 6-consecutive-failure batch test initially appeared to show the breaker never opening - this turned out to be a bug in the test script itself, not the plugin (see below), and the isolated retest confirms the underlying mechanism is correct.

### Test-harness bugs found and corrected (not plugin bugs)

Two issues surfaced during this phase, both in the temporary test scripts rather than in plugin code:

1. **Filter-ordering bug:** an earlier, broad `pre_http_request` mock ("always return success for any `/events` URL", registered at the default priority 10) silently overwrote a more specific, later-added mock intended to force failures for one particular site (registered at priority 5). Since WordPress filters run in ascending priority order and `apply_filters()` passes each callback's return value to the next, the priority-10 filter ran _after_ the priority-5 one and unconditionally clobbered its result. Diagnosed by isolating the circuit-breaker scenario into its own script with only one mock registered, which then behaved correctly.
2. **Unregistered synthetic site keys:** several load-test scenarios used made-up `site_key` values (e.g. `loadtest_ok_1`) that don't exist in the real ACF `site_details` repeater. `EC_Site_Registry::get()` correctly returns `false` for these, causing an immediate, correct `site_not_configured` permanent failure before the mocked HTTP layer is ever reached - meaning those specific scenarios didn't exercise the intended "many real successes at scale" path, even though the failure-handling itself was correct. The success-at-scale path was already verified individually with real site keys in earlier phases (1-4); this was a gap in test design, not a gap in verification of that behavior.

### Decision: legacy code retention

The architecture review's original Phase 7 scope included retiring the legacy `curl_multi`-based AJAX handler (`Event_Controller_Form_Handler` and its parallel upload/post methods), which has been unused dead code since Phase 5 moved the submission form to `wp-admin`. Removing it was explicitly deferred: the decision made was to keep it in place, dormant but intact, as a safety net rather than delete it now.

### Non-Breaking Status

- No production code was changed in this phase.

### Next Steps

- None remaining from the original 7-phase architecture review plan. Still outstanding from earlier phases: `EC_Reconciler`'s remote query capability has not been validated against a real remote site (flagged as blocking for production reliance on ambiguous-outcome handling in the Phase 4 entry below).

---

## Phase 6: Structured Logging (`ec_job_log`, Correlation IDs, Retention Purge) (2026-08-19)

**Context:** Phases 2-4 used plain `error_log()` calls scattered through `EC_Delivery_Worker`. Phase 6 replaces those with the structured logging the architecture review calls for in Section 9.3 - a dedicated table so entries can be traced per job/site instead of grepped out of a shared server log, with correlation IDs, level gating, and retention.

### Schema addition

Added `ec_job_log` (`job_id`, `site_job_id`, `correlation_id`, `level`, `phase`, `message`, `context` LONGTEXT, `created_at`) to `EC_Schema`, bumping `DB_VERSION` to `1.1.0` so `maybe_upgrade()` picks it up on existing installs without needing reactivation. Added to `drop_tables()` for `uninstall.php`.

### `EC_Logger` (`includes/class-ec-logger.php`)

**Correlation ID format** matches the review exactly: `EC-{job_id}-S{site_job_id}-{PHASE}`, e.g. `EC-1024-S17-EVENT`. Built by `correlation_id()` and stored alongside the raw `job_id`/`site_job_id` columns (both forms kept - the formatted ID for human reading, the raw columns for indexed queries).

**Level gating** via the `ec_log_level` option, defaulting to `warn` (matches the review's "quiet by default, verbose on demand"). Levels are `debug < info < warn < error`; a message is written only if its level meets or exceeds the configured threshold. This means, deliberately, that a fully successful delivery run produces **zero** log rows by default - `EC_Delivery_Worker`'s "Claimed"/"Media uploaded"/"Event posted" lines are all `info`, below the default threshold. Raising `ec_log_level` to `info` or `debug` (no code changes needed) makes the full happy-path trace visible for troubleshooting.

**Redaction**: any context key named `authorization` (case-insensitive) is always replaced with `[REDACTED]`, recursively through nested arrays, regardless of log level.

**Truncation**: at `info` level specifically (the review's "never log full payloads at info level"), any string context value over 500 characters is truncated with a `(truncated)` marker rather than logged in full. `warn`/`error` context is left untruncated, since those are rarer and the full detail matters more when something has actually gone wrong.

**Retention**: `purge_old_logs()` deletes rows older than 30 days, hooked to a new daily `ec_logger_purge_old_logs` cron event (registered in `event-controller.php`, unscheduled in the deactivator alongside the existing `event_controller_cleanup_stale_options` hook).

### Delivery worker changes

All 6 `error_log()` calls in `EC_Delivery_Worker` (budget-exceeded, circuit-open, claim-failed, claimed, site-job-vanished, ambiguous-outcome) were replaced with `EC_Logger::log()` calls carrying `job_id`/`site_job_id`/`phase` context. Additionally added `info`-level logging for the two previously-silent success paths (media uploaded with `remote_media_id`, event posted with `remote_event_id`) and for every reconciliation outcome in `handle_reconcile()`, so a full delivery trace is available end-to-end once verbosity is raised - not just the failure paths.

Not every branch got a log call (e.g. the give-up/`needs_review` path in `handle_reconcile()` still has none) - the highest-value points were prioritized rather than instrumenting every line exhaustively.

### Testing

Verified with a temporary script inside the plugin directory, deleted after use:

1. Schema upgrade creates `ec_job_log` and bumps `ec_db_version` to `1.1.0`.
2. Retention-purge cron confirmed scheduled.
3. Level gating: logging one message at each of the four levels with the default (`warn`) threshold active resulted in exactly 2 rows (`warn` and `error`) - `debug`/`info` correctly suppressed.
4. Correlation ID format confirmed exact match (`EC-1024-S17-EVENT`) for a given job/site/phase.
5. `Authorization` key confirmed redacted while an unrelated context key passed through untouched.
6. `info`-level truncation confirmed on a 1000-character string, truncated to ~515 characters with the marker present (required raising the log level first - see below).
7. Retention purge confirmed to delete a row backdated 40 days while leaving current rows alone.
8. A real mocked delivery run (job creation through completion via the actual Action Scheduler queue runner) confirmed to write exactly the expected two `info` rows ("Claimed site_job", "Event posted, remote_event_id=...") with correct correlation IDs - once the log level was raised to `info` for the test, since the happy path is silent at the default threshold as designed.

**Test-methodology note:** the first run of tests 6 and 8 showed zero rows written and looked like a bug, until it was clear the test scenarios were using `info`-level messages against the default `warn` threshold - exactly the level-gating behavior working as intended, not a defect. Fixed by having the test explicitly raise `ec_log_level` before those specific scenarios, matching how a real developer would investigate a job by turning up verbosity rather than reading nothing by default.

### Non-Breaking Status

- No existing table or class behavior changed - `ec_job_log` and its cron are purely additive.
- The legacy AJAX submission path was not touched.

### Next Steps

- Phase 7: load testing, chaos testing, flipping `ec_delivery_mode` (or equivalent) to make the queue path the default, and retiring the legacy `curl_multi` code path once one clean release has passed with the new pipeline as primary.
- Before then: `EC_Reconciler`'s remote query capability still needs validation against a real remote site (flagged as blocking in the Phase 4 entry below) - the level of detail Phase 6 now provides makes that investigation considerably easier when it happens, since every reconciliation attempt and outcome is traceable by job/site instead of only visible via `error_log()` at whatever level `EC_Reconciler` itself already used.

---

## Phase 5: Event Submission Moved to WordPress Admin (2026-08-19)

**Context:** Phases 1-4 built the entire queued delivery pipeline (job creation, background worker, retry/reconciliation) but nothing in the actual UI used it - the live form still submitted through the original synchronous AJAX handler exclusively. Phase 5 was originally scoped (per the architecture review) as an admin-side job monitoring screen only, with the front-end form left as-is. That scope was explicitly expanded during this phase: **the submission form itself was moved from the front-end `[events-form]` shortcode into a new WP admin page**, now submitting through the REST job-queue endpoint built in Phase 1, so the progress UI the review calls for has a live pipeline to actually drive.

### New admin pages

**Event Controller > Post Event** (`admin/partials/event-controller-admin-post-event.php`) - the event submission form, with field markup moved unchanged from the retired front-end shortcode (`public/partials/event-controller-post-event.php`). Only the submission mechanism changed: the modal-based "processing sites" UI is gone entirely, replaced by a direct POST to `/wp-json/event-controller/v1/jobs` and a browser redirect to the job detail page on success. Its inline JS (`event-controller-admin-form-script.php`) keeps every validation/collection helper from the original front-end script (`validateRequiredFields`, `validatePhysicalLocationFields`, `collectEventData`, date/time pickers, TinyMCE init, objectives) unchanged - none of that logic is front-end/admin specific.

**Event Controller > Jobs** (`admin/partials/event-controller-admin-job-list.php`) - a list of recent jobs (id, title, status, progress, created date), each linking to its own detail view via `?job_id=`. When `job_id` is present, the page delegates entirely to `event-controller-admin-job-detail.php` instead of showing the list.

**Job detail view** - implements the exact UI the architecture review specifies under "Admin UI & Observability": a live overall progress bar, succeeded/processing/retrying/failed counts, and a per-site table (site name, status, attempt count, remote event ID, next retry time, error detail), plus "Retry failed sites" and "Cancel job" buttons. Polls `GET /jobs/{id}` every 3 seconds until the job reaches a terminal status, then stops. The initial page load embeds the job's current state as an inline `ecJobDetailInitial` JSON variable so the first render doesn't need to wait on the first poll.

### New REST endpoints and `EC_Job_Manager` methods

- `GET /event-controller/v1/jobs` - recent jobs list, backing the Jobs page.
- `POST /event-controller/v1/jobs/{id}/retry` - `EC_Job_Manager::retry_failed_sites()` resets only `failed` site jobs (not succeeded or still-in-flight ones) to `queued` with `attempts` reset to 0 and re-enqueues their Action Scheduler actions. Attempts are reset because this is an explicit human decision, not an automatic backoff retry.
- `POST /event-controller/v1/jobs/{id}/cancel` - `EC_Job_Manager::cancel_job()` marks every non-terminal site job `cancelled` and best-effort unschedules any pending Action Scheduler actions for them via `as_unschedule_all_actions()`. An action already claimed/running when cancel is clicked can't be interrupted mid-flight, but it will find the site job already `cancelled` when it finishes and no-op (the same claim-safety mechanism from Phase 2 handles this correctly with no special-casing needed).

### Admin menu wiring

Added `register_admin_menu()` to the existing `Event_Controller_Admin` class (not a new parallel admin class) registering the top-level "Event Controller" menu with the two submenus. `enqueue_styles()`/`enqueue_scripts()` were extended to accept WordPress' `$hook_suffix` parameter and only load Bootstrap/DateRangePicker/Moment.js/TinyMCE on our own pages, not site-wide in wp-admin.

The form's and job-detail's inline `<script>` blocks are printed via `admin_footer` at priority 100 (`print_post_event_script()`/`print_job_detail_script()`), deliberately mirroring the exact technique the original front-end form already used with `wp_footer` priority 100 - this ensures the third-party libraries (enqueued for the footer at the default priority) are already output by the time these scripts run and call `.daterangepicker()`/`tinymce.init()`.

### Front-end shortcode retirement

`Event_Controller_Form`'s `add_shortcode( 'events-form', ... )` call was commented out (not deleted) in the constructor, with a comment explaining why and pointing to the new admin page. The class, its render method, and the legacy AJAX handler (`Event_Controller_Form_Handler`) are all left completely intact for rollback. Verified `events-form` no longer appears in `$shortcode_tags`.

### Bug found during testing: `list_jobs()` returned string IDs

Same class of issue as the Phase 4 Action Scheduler args bug: `$wpdb->get_results(..., ARRAY_A)` returns every column as a string, so `list_jobs()`'s `id` field was a string while `EC_Job_Manager::create()`'s response casts `job_id` to `(int)` via `format_job()`. A test using strict `in_array(..., true)` comparison failed to find a job that was actually present in the list, purely due to the type mismatch. Fixed by casting `id`/`total_sites`/`succeeded_count`/`failed_count` to `(int)` in `list_jobs()` for consistency with `format_job()`.

### Testing

Verified with a temporary script inside the plugin directory (deleted after use), calling admin render methods directly via output buffering (no HTTP round-trip needed) plus real REST dispatch:

1. Admin menu registration confirmed (`event-controller` top-level with both submenus).
2. Post Event page renders without a fatal error, contains the form and at least one site checkbox; its script contains `ecAdminData` and posts to `/jobs`.
3. Created a real job via REST (simulating the new admin form's submission) - 202 status.
4. Jobs list page renders and links to the created job.
5. Job detail page renders with the progress bar and embedded initial job JSON.
6. `retry_job`: manually failed a site, called retry, confirmed it reset to `queued` with `attempts = 0` and a new pending Action Scheduler action existed.
7. `cancel_job`: confirmed both the job and its site job transitioned to `cancelled`.
8. `list_jobs` REST endpoint confirmed working (the type-mismatch bug above was found here).
9. Confirmed `events-form` shortcode is no longer registered.
10. Front-end home page and the new admin Post Event page both confirmed to return HTTP 200 with no fatal error text.

### Non-Breaking Status

- The legacy AJAX handler and front-end shortcode class are untouched code - only the shortcode _registration_ was disabled.
- Nothing about the existing `ec_jobs`/`ec_site_jobs` schema or the Phase 1-4 worker/retry logic changed; Phase 5 is purely a new consumer of that existing pipeline plus two new REST actions.

### Next Steps

- Phase 6 (see above, already completed): structured logging.
- Deferred, not built in this phase: a dedicated circuit-breaker/site-health panel (mentioned as a "nice to have" in the architecture review) - scope was kept to exactly the admin UI requested.

---

## Phase 4: Error Classification, Retry Backoff, Circuit Breaker, Reconciliation (2026-08-19)

**Context:** Phase 3 made every delivery failure terminal. Phase 4 replaces that with the retry/recovery system the architecture review calls its highest-testing-effort phase, since ambiguous-outcome handling is the only safeguard against duplicate events (the remote companion plugin cannot be modified - confirmed decision, Phase 1).

### New classes (`includes/retry/`)

**`EC_Error_Classifier`** - `classify( WP_Error $error )` returns `permanent`, `retryable`, or `ambiguous`. Known internal error codes (`invalid_url`, `site_not_configured`, etc.) are always permanent. HTTP 500/502/503/504 are ambiguous (a WordPress fatal _after_ the event was created still returns 500). HTTP 408/429 are retryable. HTTP 400/401/403/404/413/422 are permanent. An unrecognized HTTP status defaults to ambiguous rather than guessing. Connection-level errors are parsed from the `WP_Error` message's `"cURL error N"` pattern - this is safe here specifically because `EC_Events_API_Client` goes through `wp_remote_post()`, which formats messages this way reliably (unlike the raw `curl_error()` text the old `curl_multi` code matched against - the H6 bug from Phase 1). An unrecognized connection error defaults to retryable.

**`EC_Retry_Policy`** - `next_delay_seconds( $attempts )` = `min( 60 * 4^(attempts-1), 21600 )` seconds with +/-50% jitter (roughly 1m, 4m, 16m, 64m, 6h capped). Jitter exists so many site jobs failing at the same moment don't all retry in lockstep. `max_attempts_reached()` caps at 5.

**`EC_Circuit_Breaker`** - per-site state (`closed` / `open` / `half_open`) in a single `ec_site_health` option (autoload disabled, per the H4 lesson from Phase 1). Opens after 5 consecutive failures for a site; cooldown starts at 15 minutes and doubles on each failed probe, capped at 4 hours. `is_open()` transitions `open` to `half_open` automatically once the cooldown elapses, allowing exactly one probe through.

**`EC_Reconciler`** - queries the remote for a matching event when an ambiguous outcome occurs during the event phase. **This class carries a documented, unresolved limitation:** its query parameters (`?search=&after=&per_page=`) follow the most common WP REST convention, but the actual remote "event-client" companion plugin's source was never available to inspect (that investigation was paused earlier in this project). Every code path is written to fail toward "inconclusive" rather than ever guessing: a request error, non-200 response, unparseable body, or more than one plausible match are all treated identically - none of them are grounds to mark a job complete or safe to retry. **This must be validated against a real remote site before being relied on in production.**

### Delivery worker changes

`EC_Delivery_Worker::handle()` now checks `EC_Circuit_Breaker::is_open()` before claiming - if open, the site job is marked `blocked` and rescheduled without incrementing `attempts` (a circuit-open defer isn't the job's fault).

The old `fail()` method was replaced with `handle_failure()`, which classifies the error and branches three ways:

- **Ambiguous, event phase only:** status becomes `ambiguous_pending`, no retry is scheduled - instead a `ec_reconcile_site` action is scheduled on a separate hook that only ever queries the remote, never re-posts. (Ambiguous outcomes during the _media_ phase are deliberately treated as plain retryable instead: a duplicate media attachment is a harmless cleanup issue, not the duplicate-**event** risk this whole system exists to prevent, so the added complexity of media-phase reconciliation wasn't justified.)
- **Retryable, attempts remaining:** status becomes `retrying`, `next_attempt_at` is set, and a backed-off retry is scheduled via `EC_Retry_Policy`.
- **Permanent, or retryable but exhausted:** status becomes `failed` (terminal), and the job rolls up.

`EC_Circuit_Breaker::record_failure()` / `record_success()` are called on every outcome (success path and every failure branch), including permanent failures - a site returning 401 across many different jobs really does mean "this site is unhealthy from our system's perspective," so it should count toward the breaker same as a connection failure.

`handle_reconcile( $site_job_id )` is the new hook handler for `ambiguous_pending` jobs: re-fetches the job/credentials, re-transforms the payload (reusing the persisted `remote_media_id` if the media phase already succeeded), and calls `EC_Reconciler::reconcile()`. `found` -> mark complete with the discovered `remote_event_id`. `not_found` -> now safe to retry the actual event post, so it schedules a normal retry through `EC_Retry_Policy`. `inconclusive` -> re-schedules another reconcile-only check ~60 seconds later, unless more than 24 hours have elapsed since the last real attempt, in which case the job is marked `needs_review` for a human to look at (surfaced once the Phase 5 admin UI exists) rather than looping forever.

### Bug found during testing: Action Scheduler args type mismatch

`handle_failure()` originally scheduled retry/reconcile actions with `array( $site_job['id'] )` - and since `$wpdb->get_row(..., ARRAY_A)` returns every column as a string, this passed a string, not an int. Action Scheduler stores and matches action arguments by their JSON encoding, so `["36"]` and `[36]` are different records. Every other call site in the codebase (`EC_Job_Manager::create()`, the budget/circuit-breaker reschedules in `handle()`) already used `(int)`, so this was an inconsistency, not a design flaw. Caught directly during testing: a test asserting "a new retry action exists for this site_job_id" failed to find it even though the retry logic had clearly run (status/attempts/next_attempt_at were all correct) - tracing that gap led straight to the type mismatch. Fixed by casting `(int) $site_job['id']` at both call sites. Low real-world impact today (nothing currently queries actions by args), but would have silently broken a future admin-UI feature like "show whether a pending retry exists for this site job."

### Testing

All verification used mocked HTTP (`pre_http_request`, differentiating GET vs POST by `$args['method']` since the reconciler's search and the event-post both target the same URL path) in a temporary script inside the plugin directory, deleted after use. Circuit breaker state was reset between independent scenarios since it is deliberately shared across jobs for the same site (this caused one round of test-only false failures, not a plugin bug - documented as a lesson learned, see below).

1. Retryable failure (HTTP 429) -> `retrying` status, `attempts` incremented, a new AS retry action confirmed scheduled -> forcing it to run again succeeds -> `complete`.
2. Permanent failure (HTTP 401) -> `failed` immediately, confirmed no retry action was scheduled.
3. Repeated retryable failures until `max_attempts_reached()` -> `failed` with `attempts = 5`.
4. Ambiguous event-phase failure (HTTP 500) -> `ambiguous_pending`, reconcile action confirmed scheduled -> reconciler mocked to find exactly one match -> `complete` with the discovered `remote_event_id`, and confirmed the event endpoint was **not** called again during reconciliation.
5. Same ambiguous start, but reconciler mocked to find no match -> `retrying` -> forced to run again -> `complete`.
6. Same ambiguous start, but the reconciler's own query mocked to fail (HTTP 500) -> stays `ambiguous_pending`, confirmed a new reconcile-only re-check was scheduled rather than either failing or retrying the actual post.
7. Circuit breaker: recorded 5 consecutive failures for a fake site key directly, confirmed `is_open()` returns true, then confirmed a real `handle()` call for a site job on that site is deferred to `blocked` status with `attempts` left untouched.

**Test-isolation lesson learned:** the first test run showed the circuit breaker opening partway through Test 3 and staying open through Tests 4-6, which looked like cascading failures. It wasn't a plugin bug - all of those tests reused the same `site_key`, and the circuit breaker is deliberately shared per-site across every job (that's the point of it). Fixed by resetting the breaker's option between independent test scenarios. Documented here since it's an easy trap to fall into when testing this class again later.

### Non-Breaking Status

- The legacy AJAX submission path is unaffected - none of this phase touches `class-event-controller-form-handler.php`.
- All new statuses (`retrying`, `blocked`, `ambiguous_pending`, `needs_review`) fit within the existing `ec_site_jobs.status VARCHAR(20)` column with no schema change required.

### Next Steps

- Phase 5: admin job list + detail UI, REST polling, retry/cancel actions, and a site-health panel exposing circuit breaker state - the first place `needs_review` jobs and `blocked` sites actually become visible to anyone.
- **Before Phase 7 (production rollout):** `EC_Reconciler`'s query capability against the actual remote "event-client" plugin must be validated with a real site. This is flagged as a blocking item, not a nice-to-have.

---

## Phase 3: Real Delivery - Payload Transformer, API Client, Media/Event Phase Machine (2026-08-19)

**Context:** Phase 2 gave `EC_Delivery_Worker` a real Action Scheduler-backed claim/budget pipeline, but `deliver()` was a stub that always marked the site job `failed` with `not_implemented`. Phase 3 replaces that stub with the actual media-then-event delivery per the architecture review's phase machine (`MEDIA_PROCESSING → MEDIA_COMPLETE → EVENT_PROCESSING → COMPLETE`).

### New classes

**`includes/api/class-ec-payload-transformer.php`** - `EC_Payload_Transformer::transform()` is `transform_to_em_api_schema()` moved out of `class-event-controller-form-handler.php` verbatim, with no logic change (per the architecture review's disposition table: "Preserved verbatim"). The legacy handler's own copy of this method was left untouched and unmodified - it still works exactly as before. Duplicating this one function temporarily is an accepted, low-risk tradeoff consistent with the review's migration strategy (Section 11: "the existing handler is left fully intact and functional throughout"); the duplication goes away once the legacy path is retired in a later phase.

**`includes/api/class-ec-events-api-client.php`** - a single-site version of the legacy handler's `upload_media_to_remote()` / `post_event_to_remote()` logic, adapted in one meaningful way: it reads from a persisted file path (`EC_Job_Manager`'s durable `media_path`) instead of a `$_FILES` entry, since the original upload request has long since ended by the time a background worker runs. No `curl_multi` parallelism is needed here at all - each site is already its own independent Action Scheduler action, so "parallelism across sites" now happens naturally at the queue level instead of inside one PHP process. Every `WP_Error` this class returns carries `http_status` and/or `wp_error_code` in its error data specifically so Phase 4's error classifier can consume it without this class needing to change again.

### Delivery worker: real phase machine

`EC_Delivery_Worker::deliver()` was rewritten (the Phase 2 `not_implemented` stub is gone):

1. Loads the parent job's raw row (`EC_Job_Manager::get_raw_job()`, new) and the site's credentials (`EC_Site_Registry::get()`).
2. **MEDIA phase** - runs only if the job has a `media_path` _and_ this site job doesn't already have a `remote_media_id`. On success, `remote_media_id` is persisted immediately and `phase` moves to `event`.
3. **EVENT phase** - always runs. Uses the persisted `remote_media_id` as `featured_media` if one exists (either just uploaded, or from a previous attempt).
4. On success: `status = complete`, `remote_event_id` persisted, `completed_at` set, then `EC_Job_Manager::rollup_job_counters()` is called.
5. On failure (either phase): `status = failed` with `last_error_code`/`last_error_message`/`last_http_status` from the `WP_Error`, then rollup is called. **Any failure is currently terminal** - Phase 4 adds classification, retry/backoff, and reconciliation instead of failing outright.

**Job-level rollup (`EC_Job_Manager::rollup_job_counters()`, new):** recomputes `succeeded_count`/`failed_count` directly from the actual `ec_site_jobs` rows (a full recount via `COUNT`/`SUM`, not an incremental counter) and promotes the parent job to `completed` / `completed_with_errors` / `failed` once every site job is terminal. Called both at the end of `EC_Job_Manager::create()` (so a job where every selected site was misconfigured shows as failed immediately, not stuck at `queued`) and after every terminal transition in the worker. This also replaced Phase 1's ad-hoc `failed_count` update in `create()`, which only accounted for immediately-misconfigured sites and never reflected real delivery outcomes.

### Testing

All verification used a mocked HTTP transport (`pre_http_request` filter - intercepts by URL path, so it works against any real configured site's URL without needing an actual remote server) inside a temporary script in the plugin directory, deleted after use:

1. **Full success path:** persisted a fake media file on a job, ran it through the real Action Scheduler queue runner to completion. Confirmed exactly one media call and one event call, `remote_media_id = 9001` and `remote_event_id = 5555` persisted correctly, and the parent job rolled up to `completed` with `succeeded_count = 1`.
2. **Resume-without-reupload (the critical Phase 3 guarantee):** media succeeds, event call mocked to fail. Confirmed the site job ends `failed` with `remote_media_id` still persisted and `last_error_code = event_http_error`. Manually re-queued the same site job and re-ran it with the event mock fixed: confirmed the media endpoint was **not** called a second time (still 1 total call) while the event endpoint was called again and the job completed - proving a retry after a media-phase success does not re-upload and orphan a duplicate attachment on the remote site.
3. **No-media job:** confirmed the media phase is skipped entirely (0 media calls) when a job has no featured image, going straight to the event phase.

### Non-Breaking Status

- `class-event-controller-form-handler.php` was not modified in this phase; the legacy AJAX submission path is unaffected.
- `EC_Payload_Transformer` is a new, independent copy of existing logic - no shared state or shared code path with the legacy handler that could introduce a regression there.

### Next Steps

- Phase 4: `EC_Error_Classifier` (retryable vs. permanent vs. ambiguous, replacing the current "any failure is terminal" behavior), `EC_Retry_Policy` (backoff/jitter/max attempts), `EC_Circuit_Breaker` (per-site), and `EC_Reconciler` - the last of which is the sole safeguard against remote-side duplicates since the remote companion plugin cannot be modified (see architecture review, Section 8, and the confirmed decision recorded in the Phase 1 entry below).

---

## Phase 2: Action Scheduler Vendoring + Delivery Worker Skeleton (2026-08-19)

**Context:** Phase 1 created the job/site-job tables and REST endpoints but nothing processed the jobs it created. Phase 2 adds the background worker itself, built on Action Scheduler.

**Requirement confirmed before implementation:** Event Controller must remain a completely standalone plugin - no dependency on WooCommerce or any other plugin, no Composer at runtime, no external service. Action Scheduler is bundled directly inside the plugin as a vendored library. Composer/git may be used during development to obtain it, but the shipped plugin contains everything needed to run it.

### Vendoring Action Scheduler

Action Scheduler 4.0.0 was cloned directly from the official `woocommerce/action-scheduler` GitHub repository (no Composer registry involved) into `includes/vendor/action-scheduler/`, then stripped down to runtime files only - removed `.git`, `tests/`, `.github/`, `docs/` (the Jekyll site source for actionscheduler.org), and dev tooling (`Gruntfile.js`, `package.json`, `package-lock.json`, `phpcs.xml`, `.editorconfig`, `codecov.yml`). 102 files remain: `action-scheduler.php`, `classes/`, `lib/`, `deprecated/`, plus license/changelog text files.

**Loading requirement:** Action Scheduler's official standalone integration instructions require its bootstrap file to be `require`'d unconditionally, as early as possible, and specifically **not** from inside a hook callback. `event-controller.php` now does this directly at top level, immediately after the version constant is defined.

**Conflict prevention:** Action Scheduler ships its own version-arbitration system (`ActionScheduler_Versions`) precisely so that multiple plugins can each bundle their own copy without conflict - every copy self-registers on `plugins_loaded`, and only the single newest version across all of them actually initializes; the others become inert. This was verified directly on the local dev site, which already has two other Action Scheduler installations present (WooCommerce bundles 3.9.3, wp-defender bundles 3.6.1): after adding Event Controller's 4.0.0 copy, `ActionScheduler_Versions::instance()->latest_version()` correctly resolved to `4.0.0`, and `get_sources()` showed all three file paths registered. No custom namespacing or conflict-avoidance code was needed - this is handled entirely by Action Scheduler itself, which is why bundling a plugin's own copy is the officially supported and recommended pattern rather than a risk.

**`.gitignore` bug found and fixed (pre-existing, unrelated to this phase):** the repository's `.gitignore` had blanket `vendor/` and `index.php` rules. `vendor/` would have silently excluded the entire bundled library from every future commit, defeating the whole point of vendoring it. `index.php` was worse and entirely unrelated to Action Scheduler - it was silently excluding **every** `index.php` "Silence is golden" guard file in the plugin, including ones that predate this work (`admin/index.php`, `includes/index.php`, `public/index.php`, the plugin root's `index.php`). Both rules were removed; `composer.lock` is kept ignored since Composer is dev-time-only for this project.

### Delivery worker skeleton

**`includes/jobs/class-ec-site-job-repository.php`** - `claim( $site_job_id )` is the safety-critical method: an `UPDATE ... SET status = 'processing', attempts = attempts + 1 WHERE id = %d AND status IN ('queued', 'retrying')`. The `WHERE status IN (...)` clause **is** the claim - if two workers (or Action Scheduler somehow dispatching the same action twice) call this concurrently for the same row, only one `UPDATE` can affect a row; the other gets 0 affected rows back and must not proceed. Verified directly: calling `claim()` twice in a row on the same site_job_id returns `true` then `false`, and `attempts` ends at 1, not 2.

**`includes/jobs/class-ec-delivery-worker.php`** - registers itself on the `ec_deliver_site` Action Scheduler hook. `handle( $site_job_id )`:

1. Checks the execution budget. If exceeded, does **not** claim the job - it's left exactly as it was (still `queued`/`retrying`, `attempts` untouched) - and instead schedules a fresh Action Scheduler action for the same site*job_id a few seconds out. This second part matters: Action Scheduler marks the \_current* action as complete the moment `handle()` returns, regardless of what it did internally, so simply returning early without rescheduling would silently strand that site job forever with no future action left to process it.
2. Calls `EC_Site_Job_Repository::claim()`. If the claim fails (another worker already has it, or it's already terminal), logs and returns - a no-op.
3. Calls `deliver()`, currently a stub that marks the job `failed` with `last_error_code = not_implemented`. Real media/event delivery is Phase 3's job (`EC_Events_API_Client` + `EC_Payload_Transformer`, per the architecture review).

**`includes/jobs/class-ec-job-manager.php`** - `create()` now calls `as_enqueue_async_action( EC_Delivery_Worker::ACTION_HOOK, array( $site_job_id ), 'event-controller' )` immediately after inserting each valid (non-misconfigured) site job row. This is the actual hand-off point: the request that creates the job returns as soon as the rows exist and the actions are enqueued, without waiting for any delivery to happen.

**Execution budget bug found during testing:** the first implementation computed the time budget as a fraction of `ini_get( 'max_execution_time' )` only, with no fallback. PHP CLI - and many PHP-FPM configurations that intentionally rely on the reverse proxy for timeout enforcement - report `max_execution_time = 0` (unlimited), which silently disabled the entire time-based check (`0 > 0` is false, so the check block never ran). This was caught by the test in step 5 below: forcing an "exceeded" state via a huge elapsed time had no effect, because the code path that would have detected it was never reached in this environment. Fixed by adding a fixed `MAX_TIME_BUDGET_SECONDS = 20` cap that applies whenever `max_execution_time` is 0 or unknown, plus an `ec_delivery_worker_time_budget_seconds` filter so the budget can be overridden (used directly by the fix's own regression test, and available for site owners with unusual hosting configurations).

### Testing

All verification was performed with temporary scripts inside the plugin directory against the live local XAMPP/MySQL environment, then deleted:

1. Created a real job via `EC_Job_Manager::create()`; confirmed a corresponding pending Action Scheduler action existed for the resulting site_job_id via `ActionScheduler::store()->query_actions()`.
2. Claim safety: called `EC_Site_Job_Repository::claim()` twice on the same row - first call `true`, second call `false`, final `attempts = 1` (not 2).
3. Ran Action Scheduler's real queue runner (`ActionScheduler_QueueRunner::instance()->run()`) end-to-end: the enqueued action was picked up, the worker claimed it, and the stub correctly transitioned it to `failed` / `not_implemented` with `completed_at` set - proving the full `queued → processing → terminal` pipeline works through Action Scheduler exactly as it would in production (cron or async loopback).
4. Budget guard: using the new `ec_delivery_worker_time_budget_seconds` filter to force a 0-second budget, confirmed `handle()` declines to claim the job (status stays `queued`, attempts stay `0`) and instead schedules a new pending action for the same site_job_id.
5. Front-end and wp-admin both re-verified via real `wp_remote_get()` HTTP requests (not simulated hook calls) - HTTP 200, no fatal error text in either response body.

### Non-Breaking Status

- The legacy AJAX submission flow is completely unaffected - nothing in the existing form or its handler calls `EC_Job_Manager` or enqueues any Action Scheduler action.
- Action Scheduler's own admin screen, cron hooks, and data tables are entirely additive to this site; verified no conflict with the two pre-existing Action Scheduler installations already present (WooCommerce, wp-defender).
- The `.gitignore` fix is additive (removes two overly broad rules); no tracked file content changed as a result.

### Next Steps

- Phase 3: extract `EC_Events_API_Client` and `EC_Payload_Transformer` from the legacy handler (moved, not rewritten) and replace `EC_Delivery_Worker::deliver()`'s stub body with the real media-then-event phase machine, including `remote_media_id` persistence so a retry after a media-phase success doesn't re-upload.

---

## Phase 1: Job Queue Infrastructure + Security/Stability Fixes (2026-08-19)

**Context:** Following a full architecture review (`EVENT-CONTROLLER-ARCHITECTURE-REVIEW.md`), the plugin is being evolved from a synchronous AJAX-only delivery model (which cannot reliably handle ~50 remote sites, browser closures, or proxy timeouts) toward a durable job-queue model. This is Phase 1 of 7: infrastructure only, no worker, no change to the live form's behavior beyond the security/stability fixes below.

**Decisions confirmed before implementation:**

1. No standalone "Phase 0" hotfix release — the security/stability fixes below are folded directly into this Phase 1 release instead.
2. The remote companion plugin (Events Manager REST endpoints on the 50 remote sites) cannot be modified. This means remote-side duplicate detection is not available; a later phase's Reconciler will be the only safeguard against duplicate events on ambiguous remote responses.
3. Action Scheduler (the WooCommerce background-job library) is approved as a dependency for the worker, to be added in Phase 2.

### New infrastructure

**`includes/db/class-ec-schema.php`** — Creates `wp_ec_jobs` and `wp_ec_site_jobs` via `dbDelta()`, tracked with an `ec_db_version` option so `plugins_loaded` can re-run the migration if activation didn't fire (e.g. network-activated multisite). Also provides `drop_tables()` for `uninstall.php`.

- `ec_jobs`: one row per event submission, keyed by a unique `idempotency_key` (SHA256 of sites + event data — same formula the legacy handler already used, just now enforced by a MySQL unique index instead of a `wp_options` lock).
- `ec_site_jobs`: one row per (job, site) pair, tracking status, phase, attempt count, and remote IDs independently per site.

**`includes/class-ec-site-registry.php`** — Loads the ACF `site_details` repeater once per request and caches it, replacing the legacy `get_site_credentials_by_id()` pattern of re-scanning the entire repeater for every selected site (O(N²) at 50 sites). `derive_site_key()` centralizes the site-key formula (`strtolower(str_replace(' ', '_', $name))`) so it stays identical to the existing front-end checkbox IDs — this is intentionally unchanged from the legacy behavior for backward compatibility, even though the architecture review flags site-name-derived keys as a known limitation (M1: renaming a site changes its key).

**`includes/jobs/class-ec-job-manager.php`** — `create()` computes the idempotency key, returns the existing job untouched if one already exists for that exact key (this is the de-duplication mechanism — verified in testing: submitting the same payload twice returns the same `job_id` with `is_duplicate: true` instead of creating a second job), persists any uploaded featured image to `wp-content/uploads/ec-private/{idempotency_key}/` (guarded by a generated `.htaccess` + `index.php`) since `$_FILES['tmp_name']` is deleted the instant the request ends and a future background worker needs to be able to read the file after that, then inserts one `ec_site_jobs` row per selected site. Sites with no matching ACF configuration get a row inserted with `status = failed` immediately rather than being silently skipped, so the count is accurate from the start.

**`includes/rest/class-ec-rest-controller.php`** — Registers `POST /event-controller/v1/jobs` and `GET /event-controller/v1/jobs/{id}`, both gated by `current_user_can( 'manage_options' )`. These rely on WordPress' built-in cookie + `X-WP-Nonce` REST authentication, so — unlike the legacy AJAX endpoint — there is no separate capability gap to fix here.

**Not wired up yet:** the existing form and AJAX handler do not call any of this. No worker processes the jobs it creates. This is deliberate — Phase 1 is infrastructure only, verified in isolation via direct testing, so it can land without any risk to the working legacy submission path.

### Security/stability fixes folded into this release

**C1 — Missing capability check (Critical).** `handle_form_submission()` checked only `wp_verify_nonce()`. The nonce itself was localized for _every_ logged-in user on _every_ front-end page via `enqueue_scripts()`, regardless of role — meaning any authenticated Subscriber could have submitted events to all 50 remote sites. Fixed in two places:

- `class-event-controller-form-handler.php`: added `current_user_can( 'manage_options' )` check as the first line of `handle_form_submission()`.
- `class-event-controller-public.php`: `wp_localize_script()` (which supplies the nonce) now only runs inside a `current_user_can( 'manage_options' )` check. Verified safe: the only references to `eventControllerData` in the front-end script are inside the submit-button click handler, and the form itself is already gated from rendering for non-admins in `event-controller-post-event.php`, so no legitimate code path is affected.

**C2 — Permanent lock deadlock (Critical).** The `add_option()`-based submission lock was only released in a `finally` block. A crashed/killed PHP process (fatal error, OOM, `max_execution_time`) skipped `finally` entirely, leaving the lock in place forever — that exact event could never be resubmitted without manual database intervention. Added a `LOCK_TTL` constant (300 seconds, comfortably longer than the ~240-second worst-case processing window) and a stale-lock reclaim: if `add_option()` fails, the existing lock's timestamp is checked, and if older than `LOCK_TTL` it's deleted and re-acquired.

**H4 — Result options never cleaned up (High).** `update_option( $result_key, $response_to_send )` defaulted to `autoload = yes` for new options and was never deleted, meaning every submission permanently added to the set of options loaded on every single page request site-wide. Fixed by:

- Explicitly using `add_option( $result_key, $wrapped, '', 'no' )` for new result options (or `update_option( ..., false )` if one somehow already exists), and
- Wrapping the cached value as `{ cached_at, response }` and adding an hourly `event_controller_cleanup_stale_options` cron job (registered in the constructor, unscheduled on deactivation) that deletes result options older than 1 hour and any lock options older than `LOCK_TTL` that the read-time reclaim logic hasn't already caught.
- `wait_for_result()` and the `finally` block were both updated to read/write the new wrapped format; the actual JSON returned to the browser on a proxy retry is unchanged (`cached['response']` is unwrapped before use).

**H1 — Redundant multipart body construction (High).** `upload_media_parallel()` called `build_multipart_body()` — which reads the uploaded file from disk — once per selected site inside the loop. At 50 sites this meant 50 redundant file reads and string copies of the same request body. The body is now built once before the loop and reused for every cURL handle. Also removed an unused `$file_content = file_get_contents(...)` line that was never referenced.

**H6 — Inaccurate cURL error classification (High).** Both parallel functions classified connection errors with `strpos( $curl_error, '7' )` (etc.) against `curl_error()`'s human-readable description text — not an error code embedded in that string — so the match could hit unrelated numbers (a port number, part of a hostname) or simply never match the intended case. Replaced with `curl_errno( $handle )` (the actual integer error code) checked against the `CURLE_*` constants (`CURLE_COULDNT_CONNECT`, `CURLE_OPERATION_TIMEOUTED`, `CURLE_COULDNT_RESOLVE_HOST`, `CURLE_SSL_CACERT`) in both `upload_media_parallel()` and `post_event_parallel()`.

**Front-end:** added a 5-minute `timeout` to the form's `$.ajax()` call, which previously had none and could hang indefinitely on a stalled connection.

### Testing

All verification was performed with temporary scripts inside the plugin directory against the live local XAMPP/MySQL environment, then deleted:

- `EC_Schema::install()` confirmed to create both tables with the expected columns, indexes, and the `ec_db_version` option.
- `EC_Site_Registry::get_all()` confirmed to load all 8 real configured remote sites with correct `site_key` values matching the existing front-end checkbox ID format.
- `EC_Job_Manager::create()` confirmed to insert a job + one site-job row per site, and confirmed idempotent: an identical second call returns `is_duplicate: true` with the same `job_id` rather than creating a new job.
- REST endpoints tested via `rest_get_server()->dispatch()`: unauthenticated requests return 401; authenticated admin requests return 202 with the expected job payload; `GET /jobs/{id}` returns 200 with per-site status; duplicate submission via REST returns 200 (not 202) with `is_duplicate: true`.
- Plugin activation re-run via `activate_plugin()` with no fatal errors.
- Front-end home page requested via `wp_remote_get()` after all changes: HTTP 200, no "Fatal error" text in the response body.

### Non-Breaking Status

- The legacy AJAX submission flow (`handle_form_submission()`, `upload_media_parallel()`, `post_event_parallel()`, `transform_to_em_api_schema()`) is functionally unchanged except for the specific fixes listed above — no behavior change to a successful submission's request/response shape.
- New REST endpoints and job tables are entirely additive; nothing in the existing form or JS calls them yet.
- Existing `wp_options`-based lock/cache mechanism is preserved as-is, only hardened (TTL, autoload, cleanup).

### Next Steps

- Phase 2: Action Scheduler integration + `EC_Delivery_Worker` skeleton with a time/memory execution budget.
- Phase 4 will require resolving the open question flagged during architecture review: what reconciliation is actually possible against the Events Manager REST API given the remote plugin cannot be modified (see `EVENT-CONTROLLER-ARCHITECTURE-REVIEW.md`, Section 8).

---

## Fixed: Form State Management - Modal Clearing and Location Section Visibility (2026-08-19)

**Issues:**

1. Modal dialog retained "All sites processed successfully" message from previous submission, mixing with new submission status
2. Physical location section remained visible after form reset following successful submission
3. Unused `uploadErrors` variable in form script

**Root Cause Analysis - Modal Not Clearing:**

When form submitted multiple times consecutively, the modal's `.modal-body` element retained HTML from previous submission. New site list was appended without clearing old content, resulting in mixed messages.

Solution: Clear modal body and reset title at start of submission:

```javascript
$("#sendingData .modal-body").empty();
$("#post_event_status").text("Posting Event");
```

**Root Cause Analysis - Location Section Not Hiding:**

After successful submission, `resetForm()` called `$form[0].reset()` to clear all input values. This correctly reset the event_location_type select to "Virtual" value, but the change event listener that hides the physical location section never fired.

Programmatic form resets do not trigger change event listeners - only user-initiated changes do. Location section remained visible because hide logic only existed in change handler:

```javascript
$("#event_location_type").on("change", function () {
  // This handler never fires on programmatic reset
  if (value === "Physical") showSection();
  else hideSection();
});
```

Solution: Add explicit hide call to `resetForm()` function:

```javascript
function resetForm() {
  $form[0].reset();
  $("#physical-location-section").slideUp();
  // ... other reset logic
}
```

**Code Cleanup:**

Variable `uploadErrors = []` was declared at top of script but never referenced or used in event submission workflow. Removed to reduce code clutter and avoid confusion about error handling.

**ACF Location Structure Implementation:**

As part of this session, location data was restructured from flat form fields to nested ACF group format:

Form input fields:

- location_name (text)
- location_address_1, location_address_2 (text)
- location_city, location_state, location_postcode (text)

Transformed in backend to ACF group structure:

```php
$location_group = array(
    'location_name' => $event_data['location_name'] ?? '',
    'country'       => 'United States',
    'address_1'     => $event_data['location_address_1'] ?? '',
    'address_2'     => $event_data['location_address_2'] ?? '',
    'city'          => $event_data['location_city'] ?? '',
    'state'         => $event_data['location_state'] ?? '',
    'zip_code'      => $event_data['location_postcode'] ?? '',
);
```

Included in em_api_event_save payload sent to remote sites. Remote site hook processes this nested structure and maps to ACF group fields.

**Files Modified:**

1. `includes/class-event-controller-form-handler.php` - Location group structure transformation
2. `public/partials/event-controller-form-script.php` - Modal clearing, location section hiding, unused variable cleanup
3. `public/partials/event-controller-post-event.php` - Physical location form section (from prior work)

**Testing:**

- Form submission with physical location selected: Fields show correctly, hide when type changed to Virtual
- Consecutive form submissions: Modal clears and title resets properly
- Form reset after successful submission: Location section hides, form ready for next submission
- Data payload verified: Location data structured as ACF group in remote API request

---

## Fixed: AJAX Hanging on Form Submission (2026-08-05)

**Issue:** AJAX response hung indefinitely after form submission to 7 remote WordPress sites. Events successfully posted to all sites but browser never received response.

**Root Cause Analysis:**

The WordPress function `wp_send_json_success()` immediately calls `wp_die()`, terminating the PHP script. The original code structure executed this response call AFTER the finally block, but the finally block contained critical result caching operations:

```php
try {
    // Process submission
} finally {
    // Cache result: update_option() + wp_cache_set()
    // Release lock: delete_option()
}

wp_send_json_success();  // ← wp_die() executes here, finally block code NEVER reached!
```

This meant:

1. Result never cached to wp_options
2. Lock never released
3. Response never sent to browser
4. Retry detection code never found cached result (would timeout after 120s)

**Solution Implemented:**

Reorganized execution order to finalize all operations BEFORE sending response:

```php
try {
    // Process submission
} finally {
    // Cache result + Release lock (executes completely)
}

wp_send_json_success();  // ← Only called AFTER finally completes
```

**Key Changes:**

1. **Dual-Layer Result Caching**
   - `wp_cache_set($result_key, $response, '', 300)` - millisecond memory cache
   - `update_option($result_key, $response)` - persistent database cache
   - Retry detection checks cache first (faster), falls back to options table
   - Ensures fast retrieval even during WordPress cache flushes

2. **Atomic Lock with add_option()**
   - `add_option($lock_key, time(), '', 'no')` only succeeds if option doesn't exist
   - Atomic operation prevents race conditions
   - Only original request acquires lock; retries call `wait_for_result()`
   - Lock automatically deleted in finally block after caching completes

3. **Retry Detection & Polling**
   - `wait_for_result()` polls cache/options up to 120 seconds with 1-second interval
   - Detects proxy retries by checking if lock already exists
   - Returns cached result to retry requests immediately instead of re-processing

4. **cURL Multi Timeout Protection**
   - Added `curl_multi_select($multi_handle, 1)` - prevents indefinite blocking on network delays
   - Added 120-second operation timeout with hard break: `if (time() - $start_time > 120) break;`
   - Large file uploads (4.1 MB) no longer block indefinitely

**Testing Verification:**

✅ Test Run 1:

- Form submitted at 03:34:14
- Media upload completed in 33 seconds
- Event posting completed
- AJAX response returned to browser
- All 7 sites received events
- Zero duplicates

✅ Test Run 2:

- Proxy retry detected 16 seconds after original submission
- Retry found cached result after polling
- Retry returned cached result immediately
- No duplicate processing
- All 7 sites still have only 1 event each

**Execution Timeline:**

```
03:34:14 - Original request received, lock acquired
03:34:14-03:35:00 - Phase 1: Upload media to 7 sites (parallel cURL)
03:35:00-03:35:05 - Phase 2: Post events to 7 sites (parallel cURL)
03:35:05 - Enter finally block: cache result
03:35:05 - Update options table with result
03:35:05 - Verify cached option
03:35:05 - Set wp_cache with result
03:35:05 - Release lock (delete_option)
03:35:05 - Exit finally block
03:35:05 - Call wp_send_json_success() → wp_die()
03:35:05 - Browser receives response

[Proxy Retry detected 16 seconds later]
03:35:21 - Retry request received
03:35:21 - Lock acquisition fails (already exists)
03:35:21 - Call wait_for_result() → polling for cached result
03:35:21 - Cache already exists (set 16 seconds ago)
03:35:21 - Return cached result immediately to retry
03:35:21 - No duplicate processing occurs
```

---

## Architecture: Idempotency & Duplicate Prevention

**Why This Matters:**

WPMUDEV nginx proxy has ~30-45 second timeout. Backend processing takes 60+ seconds with large file uploads (4.1 MB featured images). When backend exceeds proxy timeout, proxy automatically retries the same request, causing duplicate events if not prevented.

**Solution: Payload-Based Idempotency Key**

```php
$idempotency_key = hash(
    'sha256',
    wp_json_encode([
        'sites' => $selected_sites,
        'data'  => $event_data,
    ])
);
```

- Key generated from payload (sites + event data), NOT security tokens
- Same event submission always produces same key regardless of nonce/proxy
- Allows idempotency key to identify duplicate submissions across retries
- Nonce still verified for security, but doesn't prevent duplicates

**Lock-Based Original Request Detection:**

```php
$lock_acquired = add_option($lock_key, time(), '', 'no');

if ($lock_acquired) {
    // This is the original request - proceed with processing
} else {
    // This is a retry - wait for original to finish
    $result = wait_for_result($idempotency_key, 120);
    return $result;
}
```

- Only one request can acquire the lock (atomic operation)
- Original request proceeds, retries wait
- Retries poll cache/options for result instead of re-processing
- Prevents duplicate event posting on 7 sites

---

## Dependencies & Environment

**WordPress Functions Used:**

- `wp_send_json_success()/wp_send_json_error()` - Send JSON response + die
- `add_option()/delete_option()/get_option()` - WordPress options table
- `wp_cache_get()/wp_cache_set()` - WordPress object cache
- `wp_json_encode()` - JSON encoding
- `sanitize_text_field()` - Input sanitization

**Advanced Custom Fields (ACF):**

- `have_rows('site_details', 'option')` - Iterate remote site config
- `get_sub_field()` - Retrieve site credentials (URL, username, password)

**PHP Functions:**

- `curl_multi_init/exec/select/close()` - Parallel HTTP requests
- `curl_getinfo/curl_multi_getcontent/curl_error()` - Response handling
- `hash('sha256')` - Idempotency key generation
- `json_decode/json_encode()` - Data serialization
- `time()/sleep()` - Timing/polling
- `strpos()/str_replace()` - String manipulation

**Remote Site API:**

- POST `/wp-json/events-manager/v1/media` - Upload featured image (Basic Auth)
- POST `/wp-json/events-manager/v1/events` - Create event (Basic Auth)
- Requires Events Manager plugin on all 7 remote sites

**Environment Constraints:**

- Windows XAMPP with nginx proxy
- Proxy timeout: ~30-45 seconds
- Backend processing: 60+ seconds with large images
- Featured image size: 4.1 MB (should be <1 MB to avoid timeout)

---

## Error Handling Strategy

**Connection-Level Errors (cURL Error Codes):**

Errors that occur before getting HTTP response:

- Code 6: DNS resolution failed → "Invalid site URL or DNS not resolving"
- Code 7: Failed to connect → "Site may be down or URL is incorrect"
- Code 28: Operation timeout → "Remote site not responding - timed out"
- Code 60: SSL certificate error → "SSL certificate error - contact admin"

**HTTP-Level Errors (Status Codes):**

Errors after successful connection but failed request:

- 401: Auth failed → "Check application username and password"
- 403: Access denied → "User does not have permission"
- 404: Endpoint not found → "Check if Events Manager plugin installed"
- 413: File too large → "Reduce file size (keep <1MB)"
- 500: Server error → "Check event client error logs"
- 503: Service unavailable → "Try again later"

**Response Validation:**

Both media upload and event post verify:

- HTTP status is 200/201
- Response body contains expected fields (e.g., `id` for media, `id` for events)
- JSON is valid and not empty

---

## Logging (85 Statements)

**Form Reception:**

- Form submission received
- Nonce verification status
- Selected sites count
- Event data received
- File name and size

**Lock & Retry Detection:**

- Lock acquisition success/failure
- Retry detection (lock already exists)
- Retry wait timeout start/end
- Cached result retrieval

**Credential Lookup:**

- Lookup for each site
- Site name normalization
- Credentials found/not found

**Phase 1: Parallel Media Uploads**

- Setup: "Starting parallel media uploads for N sites"
- Per-site: URL validation, request added
- Per-site: Response code, error messages
- Completion: "All parallel media uploads completed"
- Per-site success: Media ID
- Per-site failure: Error message

**Phase 2: Parallel Event Posts**

- Setup: "Starting parallel event posts for N sites"
- Per-site: URL validation, media ID mapping, request added
- Per-site: Response code, error messages
- Completion: "All parallel event posts completed"
- Per-site success: Confirmation
- Per-site failure: Error message

**Response Preparation:**

- Success/failure determination
- Message generation
- Error count

**Finally Block (Result Caching):**

- Entering finally block
- Response caching: update_option call + result
- Verification: Option saved check
- Cache set: wp_cache_set execution
- Lock release confirmation

**Response Sending:**

- Exited finally block
- wp_send_json call type (success/error)
- Completion confirmation

---

## Non-Breaking Changes

**Preserved Functionality:**

- All 7 sites still receive events
- No changes to form submission payload format
- No changes to API endpoint expectations
- No changes to error response format visible to frontend
- No database schema changes
- No changes to user-facing behavior (except AJAX no longer hangs)

**Added Functionality:**

- Automatic retry detection and cached result handling
- Duplicate prevention via idempotency key + atomic lock
- Result caching (wp_cache + wp_options)
- Timeout protections on cURL operations
- Comprehensive error logging

**Backward Compatibility:**

- Retry polling is transparent to frontend
- Frontend receives same response format as before
- Caching is internal implementation detail
- No breaking changes to PHP/WordPress dependencies

---

## Future Improvements

1. **Logging Optimization**: Trim from 85 to ~10-12 production logs (remove granular debugging)
2. **Image Optimization**: Recommend users keep featured images <1MB to avoid proxy timeout triggers
3. **Monitoring**: Track which sites fail most often, proxy retry frequency
4. **Caching Tuning**: Adjust wp_cache TTL (currently 300 seconds = 5 minutes)
5. **Lock Timeout**: Consider shorter lock timeout for faster retry failure detection
