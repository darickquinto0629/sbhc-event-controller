# Changelog

All notable changes to Event Controller will be documented in this file.

## [1.8.3] - 2026-08-29

### Changed

#### **Site Checkboxes Sorted Alphabetically**

- Client sites on the Post Event form now display in alphabetical order instead of the order they appear in the site_details repeater
- Improves form usability when many sites are configured
- No impact on submission data or site matching logic

---

## [1.8.2] - 2026-08-26

### Changed

#### **Default Timezone Selection**

- Changed the default pre-selected timezone on the Post Event form from `America/Chicago` to `America/New_York`

### Fixed

#### **Stray "n" Character Appearing in Pasted Summary Content**

- Fixed a bug where pasting multi-paragraph content into the Summary/Short Summary WYSIWYG fields could introduce a stray `n` character between paragraphs (e.g. `</p>n<p>`)
- Root cause: the Jobs REST endpoint was un-escaping the submitted event data twice - once automatically by WordPress's REST server, and again in the plugin's own code - which corrupted line-break characters in the JSON payload
- Removed the redundant second un-escaping step so line breaks in submitted content are preserved correctly
- No change to the job creation flow, request/response format, or any other endpoint behavior

---

## [1.8.1] - 2026-08-24

### Changed

#### **Post Event Form UX Improvements**

- Added descriptive text to the Post Event form explaining how to use it
- Made Featured Image and Ticket Price fields required, with HTML5 validation
- Enhanced visual feedback when no client sites are configured (error message displayed)
- Improved form styling with padding, subtle background color, and consistent typography across form elements and paragraphs
- Added unique ID to form wrapper for targeted styling and JavaScript reference

---

## [1.8.0] - 2026-08-19

### Verified

#### **Load & Chaos Testing (Phase 7 of multi-site delivery rework)**

- Verified batch throughput and memory stability processing dozens of sites in a single background-worker run
- Verified a worker that runs out of time defers its remaining work without losing any of it, and that the deferred work is correctly picked up and completed afterward
- Verified the per-site circuit breaker correctly tracks repeated failures against a real site
- No code changes were required - this phase was testing/verification only
- **Decision:** the legacy front-end submission code is being kept in place (unused, but intact) as a safety net rather than removed at this time

### Added

#### **Structured Logging & Log Retention (Phase 6 of multi-site delivery rework)**

- Delivery events (claimed, media uploaded, event posted, retries, failures, reconciliation outcomes) are now written to a dedicated, queryable log table instead of the server's plain text error log
- Each log entry can be traced back to the exact job and site it belongs to
- Log detail level is adjustable (quiet by default, more detail available on demand) without any code changes
- Authorization credentials are never written to the log, even in detailed mode
- Old log entries are automatically cleaned up after 30 days

### Changed

- Plugin version bumped to 1.8.0

---

## [1.7.0] - 2026-08-19

### Changed

#### **Event Submission Moved to the WordPress Admin (Phase 5 of multi-site delivery rework)**

- The event submission form has moved from the front-end `[events-form]` shortcode to a new **Event Controller** admin menu (Post Event / Jobs), using the queued delivery pipeline built in Phases 1-4
- Submitting an event now returns immediately and takes you to a live job status page instead of waiting on the page with a spinner
- The job status page shows an overall progress bar, counts of succeeded/processing/retrying/failed sites, and a per-site table with status, attempt count, remote event ID, next retry time, and error detail - updating automatically every few seconds until the job finishes
- Added a "Retry failed sites" button to re-queue just the sites that failed, and a "Cancel job" button to stop a job that's still in progress
- Added a Jobs list page showing recent submissions and their outcomes
- The front-end shortcode is disabled (not deleted) as a safety net

### Added

- New REST endpoints for listing jobs and for retrying/cancelling a specific job

---

## [1.6.0] - 2026-08-19

### Added

#### **Retry, Circuit Breaker & Ambiguous-Outcome Handling (Phase 4 of multi-site delivery rework)**

- Delivery failures are now classified as permanent, retryable, or ambiguous instead of always failing outright
- Retryable failures (e.g. remote site temporarily unavailable) are automatically retried with increasing delays, up to 5 attempts
- Permanent failures (e.g. bad credentials, missing endpoint) fail immediately without wasting retries
- Ambiguous outcomes (e.g. the request timed out but the remote site may have actually processed it) are no longer retried blindly - the plugin now checks with the remote site first before deciding whether it's safe to retry, to avoid creating a duplicate event
- A site that fails repeatedly is temporarily skipped instead of having every queued job retry against it individually, then automatically tested again after a cooldown period
- **Important caveat:** the remote-site check for ambiguous outcomes has not yet been verified against a real remote site's actual capabilities. Every uncertain result defers rather than guessing, but this needs real-world validation before being relied on in production
- The existing form still uses the original, unaffected submission process

### Changed

- Plugin version bumped to 1.6.0

---

## [1.5.0] - 2026-08-19

### Added

#### **Real Event Delivery via the Job Queue (Phase 3 of multi-site delivery rework)**

- New `EC_Payload_Transformer` class: the Events Manager API payload transformation, moved out of the legacy handler with no logic change so both the legacy form and the new queue can use it
- New `EC_Events_API_Client` class: uploads media and posts events to a single remote site through the queue pipeline
- The background worker now performs real delivery: uploads the featured image (if any) then posts the event, persisting the remote site's IDs as each step succeeds
- If the event fails to post after the image already uploaded successfully, the image is not uploaded again on a later attempt - only the event post is retried, preventing duplicate/orphaned images on remote sites
- Job-level status and success/failure counts now update automatically as each site finishes
- **Note:** retry scheduling, backoff, and handling of ambiguous/timeout responses are not implemented yet — any delivery failure is currently final for that site. This lands in Phase 4. The existing form still uses the original, unaffected submission process

### Changed

- Plugin version bumped to 1.5.0

---

## [1.4.0] - 2026-08-19

### Added

#### **Background Delivery Worker (Phase 2 of multi-site delivery rework)**

- Bundled Action Scheduler 4.0.0 (the WooCommerce background-job library) directly inside the plugin at `includes/vendor/action-scheduler/` — no WooCommerce, Composer, or other plugin required at runtime
- Verified safe to run alongside other plugins that bundle their own (different) copy of Action Scheduler; only the newest version among all copies actually initializes, and no plugin depends on another's copy
- New `EC_Site_Job_Repository` class: atomically claims a queued site job for processing, preventing two workers (or a duplicate dispatch) from delivering the same event to the same site twice
- New `EC_Delivery_Worker` class: the background worker skeleton. Includes a time/memory execution budget so a single worker run cannot block a request indefinitely — remaining jobs are safely rescheduled instead
- Creating a job now automatically hands each site off to the background worker; the request that creates the job returns immediately without waiting for delivery
- **Note:** actual delivery (uploading media, posting the event) is not implemented yet — this release proves the queue → worker → completion pipeline end-to-end. Real delivery lands in Phase 3. The existing form still uses the original, unaffected submission process

### Changed

- Plugin version bumped to 1.4.0

---

## [1.3.0] - 2026-08-19

### Added

#### **Job Queue Infrastructure (Phase 1 of multi-site delivery rework)**

- New database tables `ec_jobs` and `ec_site_jobs` to durably track event delivery jobs and their per-site status, replacing the plan to rely on `wp_options` for this purpose
- New `EC_Site_Registry` class: loads remote site credentials from the ACF `site_details` repeater once per request instead of re-scanning it for every site
- New `EC_Job_Manager` class: creates jobs, persists uploaded featured images to a private, non-web-indexed directory, and de-duplicates identical submissions via a unique idempotency key
- New REST API endpoints (`POST /event-controller/v1/jobs`, `GET /event-controller/v1/jobs/{id}`), gated by the `manage_options` capability
- No background worker yet and the existing form does not use this pipeline yet — this release only adds the underlying infrastructure. See `EVENT-CONTROLLER-ARCHITECTURE-REVIEW.md` for the full multi-phase plan

### Fixed

#### **Security: Missing Capability Check on Event Submission**

- The AJAX handler for event submission previously checked only a nonce, and that nonce was generated for every logged-in user regardless of role
- Any authenticated user (not just administrators) could have submitted events to all configured remote sites
- Added an explicit `current_user_can( 'manage_options' )` check to the AJAX handler
- The submission nonce is now only sent to the browser for users who can actually use the form

#### **Stale Lock Could Permanently Block Re-Submission of an Event**

- The duplicate-submission lock was only released in a `finally` block; a crashed or killed PHP process left the lock in place forever, meaning that exact event could never be submitted again
- Locks older than 5 minutes (longer than the slowest expected processing time) are now automatically reclaimed

#### **Cached Submission Results Were Never Cleaned Up**

- Cached results and locks were written to `wp_options` without `autoload` disabled, and were never deleted
- Both now explicitly set `autoload = no`, and an hourly scheduled cleanup removes results older than 1 hour and locks older than 5 minutes

#### **Memory Usage During Multi-Site Media Upload**

- The featured image was being read from disk and re-encoded into a multipart request body once per remote site
- The multipart body is now built once and reused for every site

#### **Inaccurate Connection Error Messages**

- Connection error classification used a substring search for digits like `'7'` or `'28'` against the raw error text, which could match unrelated numbers (e.g. a port number) and produce the wrong error message
- Now uses the actual cURL error code for classification

### Changed

- Plugin version bumped to 1.3.0

---

## [1.2.2] - 2026-08-19

### Fixed

#### **Form State Management - Modal Clearing Between Submissions**

- Fixed issue where modal dialog retained success message from previous form submission
- Modal body now explicitly cleared at start of each new form submission
- Modal title reset to "Posting Event" before building new site processing list
- Prevents confusing UI state where old and new status messages mixed together

#### **Form Reset - Location Section Visibility**

- Fixed issue where physical location section remained visible after form reset following successful submission
- Root cause: jQuery form reset does not trigger change event listeners
- Solution: Added explicit hide logic to `resetForm()` function
- Location section now properly hides when form resets and event type reverts to Virtual
- Ensures clean UI state for consecutive event submissions

### Changed

#### **Event Location Data Structure - ACF Group Format**

- Restructured location data transmission from flat fields to nested ACF group structure
- Remote site Events Manager API now receives location as object:
  ```javascript
  {
    location_name: string,
    country: 'United States',
    address_1: string,
    address_2: string,
    city: string,
    state: string,
    zip_code: string
  }
  ```
- Supports ACF location group fields on remote sites with proper hierarchy
- Backend `transform_to_em_api_schema()` now builds location_group object
- em_api_event_save hook on remote sites receives structured data for ACF field storage

#### **Code Cleanup**

- Removed unused `uploadErrors` variable from form submission script
- Variable was declared but never referenced in event submission workflow

---

## [1.2.1] - 2026-08-05

### Fixed

#### **AJAX Response Hanging on Form Submission**

- Fixed issue where AJAX response hung indefinitely after form submission
- Root cause: `wp_send_json_success()` calls `wp_die()` before result caching completed
- Reorganized execution order: caching now completes in try-finally block before response sent
- AJAX now responds promptly (~5-30 seconds) instead of hanging indefinitely

#### **Duplicate Event Prevention with Proxy Retry Handling**

- Implemented payload-based idempotency key (SHA256 hash of sites + event_data)
- Added atomic lock mechanism using WordPress options table `add_option()`
- Implemented dual-layer result caching: wp_cache (memory) + wp_options (persistent)
- Retry requests now wait up to 120 seconds for cached result from original request
- Prevents duplicate event posting when WPMUDEV nginx proxy retries after timeout
- Lock automatically released after caching completes

#### **cURL Multi Timeout Protection**

- Added 1-second timeout to `curl_multi_select()` to prevent indefinite blocking
- Added 120-second hard limit to parallel media upload and event post operations
- Operations break gracefully if timeout exceeded and return partial results
- Prevents hanging on large file uploads (tested with 4.1 MB featured images)

#### **Error Logging for Production Monitoring**

- Added comprehensive error logging (~85 statements) throughout submission workflow
- Logs include: lock acquisition, retry detection, credential lookup, upload/post progress
- Tracks per-site media ID assignments and response codes
- Enables debugging of remote site failures without access to remote server logs

### Changed

#### **Try-Finally Pattern for Lock Management**

- Submission processing now uses try-finally block structure
- Lock acquired before try block, held throughout processing
- Finally block executes after all operations complete (success or early return)
- Ensures lock is always released and result always cached before response sent

---

## [1.2.0] - 2026-08-04

### Added

#### **Parallel Media Upload & Event Post Processing**

- Implemented cURL multi-handle parallel processing for multi-site submissions
- Media uploads now occur simultaneously across all target sites instead of sequentially
- Event posts now occur in parallel after media uploads complete
- Significantly improves performance when distributing events to multiple sites
- Falls back to sequential processing if cURL multi is unavailable

#### **Per-Site Media ID Tracking**

- Media upload IDs are now tracked individually per remote site
- Each site receives its correct associated media ID for featured image
- Prevents media ID mismatches in multi-site deployments

#### **Events Manager API Schema Transformation**

- New `transform_to_em_api_schema()` method converts form data to Events Manager API format
- Maps ACF custom fields to Events Manager API field names
- Supports full event data structure including location, dates, times, presenter info, ticket pricing
- Enables seamless integration with Events Manager plugin on remote sites

### Changed

#### **API Endpoint Migration to Events Manager**

- Updated media upload endpoint from `/wp-json/sbhc/v2/media_upload` to `/wp-json/events-manager/v1/media`
- Switched API integration from custom SBHC endpoint to standard Events Manager plugin REST API
- All remote site requests now target Events Manager API instead of deprecated custom endpoint
- Error messages updated to reference "Events Manager plugin" for clarity

#### **API Response Handling for Events Manager**

- Updated response parsing to handle Events Manager API format
- Changed from wrapped response format (`data['success']` with `data['data']`) to direct attachment object
- Now expects `id`, `url`, `mime_type` fields directly in response
- Added validation for `id` field presence instead of `success` flag

---

## [1.1.3] - 2026-07-23

### Changed

#### **Event Location Field Deferred**

- Temporarily deferred Locations field implementation pending feature evaluation
- Field has been disabled in form pending further requirements definition

---

## [1.1.2] - 2026-07-17

### Fixed

#### **Duplicate Event Submissions**

- Resolved issue where rapid clicks on submit button could result in events being posted multiple times
- Added submission state flag (`isSubmitting`) to prevent concurrent form submissions
- Flag blocks new submission requests until current request completes (success or error)
- Ensures only one submission can process at a time regardless of user interaction speed
- Non-breaking change with no impact on existing functionality

---

## [1.1.1] - 2026-07-15

### Added

#### **Auto-Update End Time on Start Time Selection**

- When users select a start time, the end time now automatically updates to exactly +1 hour
- Handles day rollover seamlessly (e.g., selecting 23:00 start time automatically sets end time to 00:00)
- Saves users from manual end time selection in common scenarios
- Improves form completion speed and reduces user errors

### Improved

#### **Optimized Time Picker Generation**

- Replaced 96 hardcoded time options with dynamic JavaScript generation
- Reduces HTML file size and improves maintainability
- Time intervals remain configurable (currently 15-minute increments)
- Faster page load with no loss of functionality

#### **Enhanced Date Selection Experience**

- Date picker now auto-applies selection without requiring users to click "Apply" button
- Time picker removed from date selection to keep interface focused
- Streamlines the date input workflow

#### **Consistent Success Reporting Format**

- All success scenarios now display in consistent format with statistics and site details
- Both "all success" and "partial success" states show total sites processed and individual site Post IDs
- Improves user confidence in multi-site submissions

---

## [1.1.0] - 2026-07-08

### Added

#### **Comprehensive Transaction Visibility for Multi-Site Distribution**

- When sending events to multiple websites, users now see complete results for every submission
- Previously, if one site failed, users would only see the error and never learn which sites succeeded
- Now displays clear breakdown showing exactly which sites succeeded and which failed

#### **Partial Success Reporting**

- Added new "Partial Success" state to handle mixed results (when some sites succeed and others fail)
- Displays helpful summary: "2 of 3 sites succeeded"
- Users immediately understand the scope of successful submissions

#### **Actual Website Names in Results**

- Event distribution results now display real website names (e.g., "Stetson Hills Main Campus") instead of technical site identifiers
- Makes it obvious which campus/location succeeded or failed
- Reduces confusion when managing multiple campuses or facilities

#### **Post ID Tracking for Successful Submissions**

- Successfully created events now show their Post ID on the remote site
- Format: "Stetson Hills Main Campus (Post ID: 1234)"
- Helps verify event creation and simplifies tracking

#### **Summary Statistics Dashboard**

- Multi-site submission results include easy-to-read counts:
  - Total sites targeted
  - Number succeeded
  - Number failed
- Users get the big picture without reading individual details

### Improved

#### **User-Friendly Error Messages**

- Error messages now include website names for clarity
- Actionable information helps administrators identify issues quickly
- Example: "Downtown Community Center: Authentication failed" instead of technical ID

#### **Fixed Visual Display Issues**

- Corrected layout problem where successful/failed site lists were not displaying properly in the modal
- Lists now display cleanly with proper formatting
- Professional appearance with better readability

#### **Better Submission Status Feedback**

- Modal messages now clearly indicate outcome:
  - Full success: "All sites processed successfully"
  - Partial: "Processing complete: 2 succeeded, 1 failed"
  - Failure: "Some errors occurred while posting"
- Users understand submission results before closing the dialog

### Technical Details (Developers)

- Backend response now includes metadata for each submission:
  - `partial_success`: Boolean flag identifying mixed results
  - `stats`: Object with counts (total, succeeded, failed)
  - `responses`: Array of all successful submissions with site info
  - `errors`: Array of all failed submissions
- Frontend uses safe property access to prevent compatibility issues
- Optimized DOM rendering to prevent layout issues

### Backward Compatibility

✅ **100% Backward Compatible**

- All existing functionality works exactly as before
- Single-site submissions unaffected
- All-success and all-fail scenarios display identically
- New partial success feature activates only in mixed scenarios (which previously had limited reporting)

### Known Limitations

⚠️ **Scale Limit**: Current implementation reliably supports up to 12-15 websites per submission. For larger scale (50+ sites), additional optimization is recommended. See documentation.

## [1.0.3] - 2026-07-06

### Changed

- Improved remote API error handling for media uploads and event creation.
- Added user-friendly error messages for common connection and HTTP failures.
- Enhanced frontend diagnostics by returning detailed responses from remote client sites.

### Fixed

- Improved reporting of authentication, permission, timeout, SSL, DNS, and server errors.
- Better distinction between connection failures and HTTP response errors.

### Compatibility

- Maintained backward compatibility with the existing response format.
- Added an optional `responses` field to successful API responses for diagnostics.

## [1.0.2] - 2026-07-04

### Added

- **Frontend Form Script** (`public/partials/event-controller-form-script.php`)
  - Comprehensive JavaScript form validation and submission via AJAX
  - Date/time range picker integration (moment.js + daterangepicker)
  - TinyMCE editor integration for event descriptions (summary and short_summary)
  - Multi-site checkbox selection for event distribution
  - File upload support for featured images
  - Dynamic objective field management (add/remove objectives)
  - Real-time form validation with visual error indicators
  - Modal feedback for submission status
  - Loader spinner during submission

- **Backend Form Handler** (`includes/class-event-controller-form-handler.php`)
  - Dedicated AJAX handler class for secure form submission processing
  - ACF integration for retrieving site credentials (credentials are server-side only)
  - Multi-site event distribution capability
  - Remote media upload to client sites via custom REST endpoint
  - Remote event creation via custom REST endpoint
  - Basic authentication for inter-site API calls
  - Comprehensive error tracking and reporting

### Changed

- **HTTP Status Code Handling**
  - Updated remote API response validation to accept HTTP 201 (Created) in addition to HTTP 200 (OK)
  - Fixes issues where remote sites correctly return 201 for successful POST requests
  - Applied to both `upload_media_to_remote()` and `post_event_to_remote()` methods
  - Changed: `if ( 200 !== (int) $status )` → `if ( 200 !== (int) $status && 201 !== (int) $status )`

### Fixed

- Fixed parse error in event-controller-form-script.php (unmatched braces)
- Fixed error reporting: "summit_bhc_staging: Server returned status 201" now passes validation

### Improved

- **Code Quality & Maintainability** (`public/partials/event-controller-form-script.php`)
  - Extracted 9 helper functions to eliminate code duplication
  - Reduced main submit handler from 163 to 67 lines (60% reduction)
  - Extracted `initDatePicker()` to consolidate daterangepicker initialization
  - Extracted `initTinyMCEEditor()` to consolidate TinyMCE initialization
  - Extracted `setupCheckboxToggle()` to eliminate duplicate toggle patterns
  - Extracted `validateRequiredFields()`, `collectObjectives()`, `collectEventData()`, `collectSelectedSites()` for cleaner data collection
  - Extracted `resetForm()` for post-submission form reset logic
  - Extracted `handleSubmissionSuccess()` and `handleSubmissionError()` for AJAX callback handlers
  - **All behavior preserved** - 100% functionality identical to previous version
  - Improved readability without any breaking changes

### Security Improvements

- **Critical**: Moved ACF site credentials handling to server-side only
  - Site URLs, usernames, and application passwords are no longer exposed in public JavaScript
  - All credentials retrieved server-side via AJAX with nonce verification
  - Prevents exposure of sensitive configuration in browser dev tools or page source

### Files Added

- `public/partials/event-controller-form-script.php` (NEW)
- `includes/class-event-controller-form-handler.php` (NEW)

## [1.0.1] - 2026-07-02

### Added

- Security nonce generation for AJAX requests

### Changed

- **Refactored Event Form Handler**: Converted global functions to class-based architecture (`Event_Controller_Form`)
  - Improved code organization and maintainability
  - Better separation of concerns with dedicated methods for registration, enqueueing, and rendering

### Fixed

- Fixed undefined `$args` variable in `wp_localize_script()` call
- Removed unused ACF variables (`$u`, `$p`, `$url`) that were being fetched but never used
- Improved output escaping in ACF loops with `esc_html()` and `esc_attr()`
- Cleaned up redundant buffer operations (`ob_get_contents()` and `ob_get_clean()`)
- Hard-coded modal content now properly generates from ACF data

### Improved

- Scripts now only enqueue when the `[events-form]` shortcode is used (performance optimization)
- Scripts registered once per page load instead of on every class instantiation
- Better error handling with early permission check in `render_shortcode()`
- Enhanced WordPress best practices adherence with proper hook callbacks using array syntax
- Removed hard-coded modal paragraphs in favor of dynamic generation from `site_details` ACF field

### Files Modified

- `public/partials/event-controller-post-event.php`

## [1.0.0] - Initial Release

- Initial plugin release with event posting functionality
