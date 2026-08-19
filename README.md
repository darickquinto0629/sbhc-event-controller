# Event Controller

![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)
![Version](https://img.shields.io/badge/version-1.2.2-brightgreen)

Centralize event management and syndicate events to multiple remote WordPress sites in real-time with a single form submission.

## Overview

Event Controller is a powerful WordPress plugin designed for multi-site event management. It enables administrators to create events and automatically distribute them across multiple remote WordPress installations with a single submission. Perfect for networks, franchises, and organizations managing events across several websites.

## Features

✨ **Multi-Site Event Distribution**

- Publish events to multiple remote WordPress sites simultaneously
- Streamlined single-form submission process
- Real-time event synchronization

📋 **Comprehensive Event Management**

- Event title, date, time, and location management
- Timezone support across US regions and territories
- Featured image upload capability
- Event type and location type selection (Virtual/Physical/Hybrid)

📝 **Rich Event Details**

- Event summary and short summary fields
- Learning objectives with dynamic addition
- Presenter and ticket pricing information
- Event contact information (name, number, email, registration link)

🔒 **Security & Performance**

- Admin-only access control
- AJAX nonce verification ready
- Class-based architecture for better performance
- Scripts enqueued only when needed

🎨 **Professional UI**

- Bootstrap 5 integration
- Date range picker for easy date selection
- TinyMCE WYSIWYG editor for rich text content
- Modal workflows for site selection and submission status

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- cURL enabled (for remote API calls)
- ACF (Advanced Custom Fields) Pro for `site_details` field group configuration

## Installation

### From GitHub

1. Download the plugin as a ZIP file from the repository
2. Extract the ZIP file to your WordPress plugins directory: `/wp-content/plugins/`
3. Activate the plugin via **Plugins** menu in WordPress admin
4. Configure your remote site details in the plugin settings

### Manual Installation

```bash
git clone https://github.com/summitbhc/event-controller.git event-controller
cd /path/to/wordpress/wp-content/plugins
# Place the event-controller folder here
```

## Configuration

### Setup Remote Sites

Remote site credentials are stored in ACF and retrieved securely during event submission.

1. Configure ACF field group on the main site (see **ACF Field Setup** below)
2. Add remote WordPress site URLs and credentials to the ACF repeater
3. Each remote site must have:
   - Events Manager plugin installed and activated
   - Application password created for the event submission user
   - Events Manager REST API enabled (`/wp-json/events-manager/v1/events`)
4. Test the connection to each remote site

### ACF Field Setup (Required)

You must configure an ACF field group to store remote site credentials:

**Field Group:** `site_details` (location: Options Page)
**Type:** Repeater
**Subfields:**

- `site_name` (Text) - Name of the remote site
- `site_url` (URL) - Remote WordPress URL (e.g., `https://example.com`)
- `application_password_name` (Text) - Username for Basic Auth
- `application_password` (Password) - Application password or API token

### Remote Site ACF Setup (Required)

Each remote site must have an ACF field group to receive location data:

**Field Group:** `event_location` (or custom name, mapped in Events Manager)
**Type:** Group
**Subfields:**

- `location_name` (Text)
- `country` (Text)
- `address_1` (Text)
- `address_2` (Text)
- `city` (Text)
- `state` (Text)
- `zip_code` (Text)

## Usage

### Creating Events

1. Navigate to the page where the `[events-form]` shortcode is placed
2. **Select Website(s):** Click "Select Site" and choose which remote sites to publish to
3. **Upload Featured Image:** Add a featured image for the event
4. **Fill Event Details:** Complete all required fields:
   - Event Title (required)
   - Event Location (required)
   - Start Date & Time (required)
   - End Date & Time (required)
   - Event Timezone (required)
5. **Add Learning Objectives:** Click "Add Objective" to add multiple learning objectives
6. **Fill Contact Information:** Provide event contact details
7. **Post Event:** Click the "Post Event" button

### Shortcode

Place the following shortcode on any page or post:

```
[events-form]
```

The form will only display for administrators.

## API Integration

### Architecture

Event Controller uses a secure client-server architecture for multi-site event distribution:

**Frontend Component** (`public/partials/event-controller-form-script.php`)

- Handles form UI and client-side validation
- Submits form data via AJAX to the server
- Never exposes sensitive credentials or site details

**Backend Component** (`includes/class-event-controller-form-handler.php`)

- Processes AJAX requests with nonce verification
- Retrieves site credentials securely from ACF
- Uploads featured images to each remote site
- Submits events to remote Events Manager REST API endpoint
- Authenticates using Basic Auth with application passwords
- Implements idempotency key to prevent duplicate submissions
- Caches results to handle nginx proxy retries

### Remote API Endpoint

Events are submitted to the following REST API endpoint on each remote site:

```
POST /wp-json/events-manager/v1/events
```

**Authentication:** Basic Auth (username + application password)

**Content-Type:** `application/json`

### Security Features

✅ **Credential Protection**

- Site URLs, usernames, and application passwords are never exposed in client-side JavaScript
- All sensitive data retrieved and managed server-side only
- Credentials never appear in browser dev tools or page source

✅ **Authentication & Verification**

- WordPress nonce verification on all AJAX endpoints
- Basic authentication (Base64 encoded) for Events Manager API calls
- Application password support for remote site access

✅ **Data Validation & Idempotency**

- Client-side validation for user experience
- Server-side validation for security
- HTTPS URL validation and SSL certificate verification
- Input sanitization on all form fields
- Idempotency key (SHA256 hash) prevents duplicate submissions
- Retry detection and result caching handle nginx proxy timeouts

### Event Data Format (Events Manager API)

Events are submitted to remote sites with the following structure:

```php
[
    'event_name'          => string,           // Event title
    'event_start_date'    => string,           // YYYY-MM-DD
    'event_end_date'      => string,           // YYYY-MM-DD
    'event_start_time'    => string,           // HH:MM:SS
    'event_end_time'      => string,           // HH:MM:SS
    'event_timezone'      => string,           // US timezone
    'event_type'          => 'single',         // Event type
    'post_status'         => 'publish',        // Always publish
    'featured_image'      => integer,          // Media ID on remote site

    // Custom ACF fields
    'summary'             => string,
    'short_summary'       => string,
    'presenter'           => string,
    'ticket_price'        => string,
    'event_location_type' => string,           // Virtual, Physical, Hybrid
    'contact_name'        => string,
    'contact_number'      => string,
    'contact_address'     => string,
    'registration_link'   => string,
    'objectives'          => array,

    // Location as ACF group field
    'location' => [
        'location_name' => string,
        'country'       => 'United States',
        'address_1'     => string,
        'address_2'     => string,
        'city'          => string,
        'state'         => string,
        'zip_code'      => string,
    ]
]
```

### Event Processing on Remote Sites

When an event is submitted to a remote site:

1. **Media Upload** - Featured image uploaded first, media ID returned
2. **Event Creation** - Event created via Events Manager REST API
3. **ACF Field Storage** - Custom fields stored in ACF field groups
4. **Location Data** - Location group data maps to remote site's location field group

The `em_api_event_save` hook on remote sites processes the submission and stores all data.

## Changelog

### Version 1.0.2 - July 4, 2026

**Added**

- New Frontend Form Script (`public/partials/event-controller-form-script.php`)
  - Complete JavaScript form handling with validation
  - Date/time range picker integration
  - TinyMCE editor for event descriptions
  - Dynamic objective field management
  - Real-time form validation and error display

- New Backend Form Handler (`includes/class-event-controller-form-handler.php`)
  - Dedicated AJAX endpoint for secure form submission
  - Multi-site event distribution capability
  - Remote media upload functionality
  - Remote event posting via custom REST endpoints

**Changed**

- Improved HTTP status code handling to accept both 200 (OK) and 201 (Created) responses from remote APIs
- Enhanced security architecture with server-side credential management

**Fixed**

- Fixed form script parsing errors
- Fixed "Server returned status 201" errors on successful remote POST requests
- Improved code formatting and structure

**Security Improvements**

- ⚠️ **Critical**: Refactored credential management to server-side only
  - Site credentials no longer exposed in client-side JavaScript
  - All authentication handled securely via AJAX with nonce verification

### Version 1.0.1 - July 2, 2026

**Added**

- Security nonce generation for AJAX requests

**Changed**

- Refactored Event Form Handler into class-based architecture
- Improved code organization and separation of concerns
- Enhanced plugin description and documentation

**Fixed**

- Undefined `$args` variable in `wp_localize_script()` call
- Removed unused ACF variables
- Improved output escaping in ACF loops
- Cleaned up redundant buffer operations

**Improved**

- Performance: Scripts now only enqueue when shortcode is used
- Better WordPress best practices adherence
- Dynamic modal generation from ACF data

### Version 1.0.0 - Initial Release

- Initial plugin release with event posting functionality

## Troubleshooting

### "You need to be logged in as an Admin" message

- Ensure you're logged in with administrator privileges
- Check user capabilities in WordPress

### Remote site connection fails

- Verify the remote site URL is accessible
- Check application password credentials
- Ensure cURL is enabled on your server
- Verify firewall rules allow outbound requests

### Featured image not uploading

- Check WordPress file upload limits in `php.ini`
- Verify the uploads directory has proper permissions
- Ensure the remote site accepts file uploads

### Events not appearing on remote sites

- Check that application passwords have proper REST API permissions
- Verify the remote site WordPress version (5.0+)
- Check PHP error logs for API errors
- Ensure post type exists on remote sites

## Development

### Directory Structure

```
event-controller/
├── admin/                 # Admin-specific functionality
├── includes/              # Core plugin classes
├── public/                # Public-facing functionality
│   └── partials/          # Form and display templates
├── languages/             # Translation files
├── event-controller.php   # Main plugin file
├── CHANGELOG.md          # Version history
├── LICENSE.txt           # GPL-2.0+ License
└── README.md             # This file
```

### Key Classes

- `Event_Controller` - Main plugin class
- `Event_Controller_Loader` - Hook management
- `Event_Controller_Public` - Public-facing hooks
- `Event_Controller_Form` - Event form handler and submission

## Contributing

We welcome contributions! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Support

For issues, questions, or suggestions:

- **GitHub Issues:** [Create an issue](https://github.com/summitbhc/event-controller/issues)

## Security

If you discover a security vulnerability, please email darickquinto@gmail.com instead of using the issue tracker.

## License

This plugin is licensed under the GNU General Public License v2.0 or later.

See [LICENSE.txt](LICENSE.txt) for more details.

## Authors

- Darick L. Quinto

---
