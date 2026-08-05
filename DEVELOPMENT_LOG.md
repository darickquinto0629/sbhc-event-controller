# Development Log

Technical documentation of fixes, architecture decisions, and debugging context.

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
