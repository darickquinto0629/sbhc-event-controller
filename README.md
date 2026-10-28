# Event Controller

![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)
![Version](https://img.shields.io/badge/version-1.9.1-brightgreen)

Centralize event management and reliably syndicate events to dozens of remote WordPress sites through a durable, retry-aware background job queue.

## Overview

Event Controller is a WordPress plugin for multi-site event management, built to scale to ~50 remote sites per submission. Administrators create an event once from a dedicated WordPress admin screen; the plugin creates a durable job, hands each remote site off to a background worker, and shows live per-site progress - all independent of whether the admin's browser stays open. Perfect for networks, franchises, and organizations managing events across many websites.

**This is a rebuilt architecture.** The plugin originally submitted events synchronously (one AJAX request, in-process parallel cURL, no retry) directly from a front-end shortcode. That approach could not reliably handle dozens of sites, partial failures, or interrupted requests. It has been replaced with a queued, worker-driven pipeline - see [Architecture](#architecture) below and `EVENT-CONTROLLER-ARCHITECTURE-REVIEW.md` for the full technical review this rebuild was based on.

## Features

✨ **Reliable Multi-Site Event Distribution**

- Submits events to remote WordPress sites through a persistent job queue, not a single long-running request
- Survives closing the browser, a proxy timeout, or a PHP worker crash mid-delivery - the job keeps processing in the background
- Automatic retry with backoff for transient failures, and a per-site circuit breaker so one dead site doesn't block delivery to the rest
- Ambiguous outcomes (e.g. a timeout where the remote site may have already processed the request) are reconciled with the remote site before ever retrying, to avoid creating duplicate events

📊 **Live Job Monitoring**

- Admin screen showing overall progress, per-site status, attempt counts, remote event IDs, and error detail, updating automatically
- "Retry failed sites" and "Cancel job" actions
- Structured, queryable logs per job/site instead of a flat server log

📋 **Comprehensive Event Management**

- Event title, date, time, and location management
- Timezone support across US regions and territories
- Featured image upload capability
- Event type and location type selection (Virtual/Physical)

📝 **Rich Event Details**

- Event summary and short summary fields
- Learning objectives with dynamic addition
- Presenter and ticket pricing information
- Event contact information (name, number, email, registration link)

🔒 **Security & Performance**

- Admin-only access control (capability-gated, not just nonce-gated)
- REST API authentication via WordPress' built-in cookie + nonce system
- Standalone - Action Scheduler (the background job engine) is bundled inside the plugin; no WooCommerce or other plugin dependency required

🎨 **Professional UI**

- Bootstrap 5 integration
- Date range picker for easy date selection
- TinyMCE WYSIWYG editor for rich text content

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- ACF (Advanced Custom Fields) Pro for `site_details` field group configuration
- No external dependencies required at runtime - Action Scheduler is bundled inside the plugin (`includes/vendor/action-scheduler/`)

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

Event submission is done from the WordPress admin, not a front-end page.

1. In wp-admin, go to **Event Controller > Post Event**
2. **Select Website(s):** Check which remote sites to publish to
3. **Upload Featured Image:** Add a featured image for the event (required), plus the alternative text describing it (required - stored as the image's alt text on each remote site)
4. **Fill Event Details:** Complete all required fields:
   - Event Title (required)
   - Start Date & Time (required)
   - End Date & Time (required)
   - Event Timezone (required)
   - Event Location Type (Virtual/Physical) - Physical requires an address
5. **Add Learning Objectives:** Click "Add Objective" to add multiple learning objectives
6. **Fill Contact Information:** Provide event contact details
7. **Post Event:** Submitting takes you straight to a live job status page - no need to keep the tab open while delivery finishes

### Monitoring Jobs

Go to **Event Controller > Jobs** to see recent submissions, or open a specific job to see:

- Overall progress bar and succeeded/processing/retrying/failed counts
- A per-site table with status, attempt count, remote event ID, next retry time, and error detail
- **Retry failed sites** - re-queues only the sites that failed
- **Cancel job** - stops any sites still in progress

### Legacy Shortcode

The original `[events-form]` front-end shortcode is disabled (registration commented out, not deleted) as of this rebuild. It is kept in the codebase only as a rollback path and is not part of normal usage.

## Architecture

### Overview

Event Controller uses a durable job queue rather than a single synchronous request:

```
Admin submits event (wp-admin)
        |
        v
POST /wp-json/event-controller/v1/jobs   <- returns immediately with a job_id
        |
        v
One job row + one "site job" row per selected site (durable, MySQL)
        |
        v
Action Scheduler dispatches one background action per site
        |
        v
EC_Delivery_Worker: upload media (if any) -> post event -> mark complete/failed
        |
        v
Failures are classified and either retried with backoff, deferred behind a
per-site circuit breaker, or (for ambiguous outcomes) reconciled against the
remote site before ever retrying
        |
        v
Admin "Jobs" screen polls job status and shows live per-site progress
```

The admin's browser only needs to stay open long enough to see the job get created - delivery to all selected sites happens in the background regardless of whether the tab is closed afterward.

### Key Components

| Component                | Responsibility                                                                                                                                   |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `EC_Job_Manager`         | Creates jobs, de-duplicates identical submissions, persists uploaded media, rolls up job status                                                  |
| `EC_Site_Registry`       | Loads remote site credentials from the ACF `site_details` repeater                                                                               |
| `EC_Delivery_Worker`     | Background worker: claims one site job per Action Scheduler action, runs the media-then-event delivery, enforces an execution time/memory budget |
| `EC_Events_API_Client`   | Single-site HTTP client for the Events Manager media/event endpoints                                                                             |
| `EC_Payload_Transformer` | Builds the Events Manager API payload from the submitted event data                                                                              |
| `EC_Error_Classifier`    | Classifies a delivery failure as permanent, retryable, or ambiguous                                                                              |
| `EC_Retry_Policy`        | Exponential backoff with jitter (up to 5 attempts)                                                                                               |
| `EC_Circuit_Breaker`     | Temporarily stops attempting delivery to a site that's failing repeatedly                                                                        |
| `EC_Reconciler`          | Checks with the remote site before retrying an ambiguous outcome, to avoid creating a duplicate event                                            |
| `EC_Logger`              | Structured, per-job/per-site logging with level gating and automatic retention purge                                                             |
| `EC_REST_Controller`     | REST API: create/list/get/retry/cancel jobs                                                                                                      |

### REST API

All routes require the `manage_options` capability and WordPress' built-in `X-WP-Nonce` authentication.

| Method | Route                                           | Purpose                                       |
| ------ | ----------------------------------------------- | --------------------------------------------- |
| `POST` | `/wp-json/event-controller/v1/jobs`             | Create a job (submits the event for delivery) |
| `GET`  | `/wp-json/event-controller/v1/jobs`             | List recent jobs                              |
| `GET`  | `/wp-json/event-controller/v1/jobs/{id}`        | Get a job's status and per-site detail        |
| `POST` | `/wp-json/event-controller/v1/jobs/{id}/retry`  | Re-queue only the sites that failed           |
| `POST` | `/wp-json/event-controller/v1/jobs/{id}/cancel` | Cancel a job still in progress                |

### Remote API Endpoint

Each remote site receives requests at:

```
POST /wp-json/events-manager/v1/media    (if a featured image is attached)
POST /wp-json/events-manager/v1/events
```

**Authentication:** Basic Auth (username + application password)
**Content-Type:** `application/json` (event) / `multipart/form-data` (media)

### Security Features

✅ **Credential Protection**

- Site URLs, usernames, and application passwords are never exposed in client-side JavaScript
- All sensitive data retrieved and managed server-side only
- Credentials are never written to logs, even at the most verbose logging level

✅ **Authentication & Verification**

- REST endpoints require the `manage_options` capability, checked in addition to WordPress' built-in nonce verification
- Basic authentication (Base64 encoded) for Events Manager API calls

✅ **Data Validation, Idempotency & Duplicate Prevention**

- Client-side validation for user experience, server-side validation for security
- HTTPS URL validation and SSL certificate verification
- A payload-derived idempotency key means submitting the same event twice returns the existing job instead of creating a duplicate
- Ambiguous delivery outcomes are reconciled with the remote site before any retry, rather than assumed safe to resend

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
    'featured_image_alt'  => string,           // Alt text for the featured image

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

See [CHANGELOG.md](CHANGELOG.md) for full version history. Summary of the major rebuild:

- **1.3.0 - 1.6.0:** Introduced the durable job queue, background delivery worker (Action Scheduler, bundled standalone), real media/event delivery, and error classification/retry/circuit breaker/reconciliation
- **1.7.0:** Moved event submission from the front-end shortcode to a WordPress admin screen with a live per-site progress view
- **1.8.0:** Structured, queryable logging with automatic retention
- **1.0.x - 1.2.x:** Original synchronous, front-end shortcode-based implementation (superseded by the rebuild above)

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
├── admin/                  # Admin UI: Post Event form, Jobs list/detail
│   └── partials/
├── includes/
│   ├── api/                # EC_Payload_Transformer, EC_Events_API_Client
│   ├── db/                 # EC_Schema (ec_jobs, ec_site_jobs, ec_job_log)
│   ├── jobs/                # EC_Job_Manager, EC_Delivery_Worker, EC_Site_Job_Repository
│   ├── retry/               # EC_Error_Classifier, EC_Retry_Policy, EC_Circuit_Breaker, EC_Reconciler
│   ├── rest/                # EC_REST_Controller
│   ├── vendor/action-scheduler/  # Bundled Action Scheduler (standalone, no Composer needed at runtime)
│   ├── class-ec-logger.php
│   ├── class-ec-site-registry.php
│   └── class-event-controller-form-handler.php  # Legacy AJAX handler, kept dormant as a rollback path
├── public/                 # Legacy front-end shortcode (disabled, kept for rollback)
│   └── partials/
├── languages/               # Translation files
├── event-controller.php    # Main plugin file
├── EVENT-CONTROLLER-ARCHITECTURE-REVIEW.md  # The technical review this rebuild was based on
├── CHANGELOG.md             # Version history
├── DEVELOPMENT_LOG.md       # Detailed technical notes per development phase
├── LICENSE.txt              # GPL-2.0+ License
└── README.md                # This file
```

### Key Classes

- `Event_Controller` - Main plugin class
- `Event_Controller_Loader` - Hook management
- `Event_Controller_Admin` - Registers the admin menu, enqueues assets, renders the Post Event/Jobs pages
- `EC_Job_Manager` / `EC_Delivery_Worker` - Job creation and background delivery
- `EC_REST_Controller` - REST API for job create/list/get/retry/cancel

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
