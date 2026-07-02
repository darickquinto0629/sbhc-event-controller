# Changelog

All notable changes to Event Controller will be documented in this file.

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
