# Changelog

All notable changes to Event Controller will be documented in this file.

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
