# Changelog

All notable changes to Event Controller will be documented in this file.

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
