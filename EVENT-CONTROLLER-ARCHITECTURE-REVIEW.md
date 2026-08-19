# Event Controller — Architecture, Reliability & Scalability Review

**Plugin:** Event Controller
**Version reviewed:** 1.2.2
**Branch:** `fixed-sending-duplicates-with-error-logs` (merged to `main`)
**Date:** 2026-08-19
**Objective:** Evolve the plugin into a reliable distributed event-delivery system capable of safely sending events to ~50 remote WordPress sites.

> **Status: REVIEW ONLY — no code has been modified.**
> Implementation should begin only after this architecture is approved.

> **Note on the existing report:** `Event-Controller-50-Sites-Stability-Report.html` is **stale**. Its headline issue ("Sequential Processing Bottleneck") was already resolved by the `curl_multi` parallel work. This document supersedes it.

---

## Table of Contents

1. [Current Architecture](#1-current-architecture)
2. [Current Problems (Ranked)](#2-current-problems-ranked)
3. [Architecture Options](#3-architecture-options)
4. [Recommended Architecture](#4-recommended-architecture)
5. [Data Model](#5-data-model)
6. [Job Lifecycle](#6-job-lifecycle)
7. [Failure Handling](#7-failure-handling)
8. [Duplicate Prevention](#8-duplicate-prevention)
9. [Admin UI & Observability](#9-admin-ui--observability)
10. [Implementation Plan](#10-implementation-plan)
11. [Migration Strategy](#11-migration-strategy)
12. [Files to Create / Change](#12-files-to-create--change)
13. [Summary & Decisions Required](#13-summary--decisions-required)

---

## 1. Current Architecture

### 1.1 Execution trace

```
Admin page with [events-form]  (render gated by manage_options)
        │
        ▼
wp_localize_script → eventControllerData { ajax_url, nonce:'event_controller_submit' }
        │  ⚠ enqueued on wp_enqueue_scripts for EVERY front-end page, EVERY user
        ▼
JS: validate → collectEventData() → FormData(action, nonce, selected_sites, event_data, file)
        │  ⚠ $.ajax has no timeout set
        ▼
admin-ajax.php → handle_form_submission()      [class-event-controller-form-handler.php]
        │
        ├─ wp_verify_nonce()                    ⚠ no current_user_can()
        ├─ json_decode(selected_sites), json_decode(event_data)
        ├─ idempotency_key = sha256(json{sites, data})
        ├─ add_option('event_controller_lock_'.$key)   ← atomic lock
        │        └─ if FAILS → wait_for_result(): sleep(1) poll ×120 → echo cached JSON
        │
        ├─ FOREACH site → get_site_credentials_by_id()  ⚠ full ACF repeater scan per site
        │
        ├─ PHASE 1  upload_media_parallel()   curl_multi, ALL sites, 30s/handle, 120s total
        │        └─ media_ids[site_id]
        ├─ PHASE 2  post_event_parallel()     curl_multi, ALL sites, 30s/handle, 120s total
        │        └─ transform_to_em_api_schema() per site
        │
        └─ finally: update_option(result), wp_cache_set(300s), release_lock()
                 ▼
           wp_send_json_success|error   (wp_die)
```

### 1.2 Remote integration

| Item              | Value                                           |
| ----------------- | ----------------------------------------------- |
| Media endpoint    | `POST {site}/wp-json/events-manager/v1/media`   |
| Event endpoint    | `POST {site}/wp-json/events-manager/v1/events`  |
| Auth              | Basic Auth (application passwords)              |
| Credential source | ACF options repeater `site_details`             |
| Remote processing | `em_api_event_save` hook maps custom/ACF fields |

### 1.3 What is genuinely good here

The team has already solved several hard problems correctly. **These must be carried forward:**

- **Payload-derived idempotency key** — survives nonce rotation. This is the right choice and most implementations get it wrong.
- **`add_option()` as an atomic lock** — correct use of a MySQL unique-key primitive.
- **Two-phase media → event split with per-site media IDs** — correct. A shared media ID across sites would be a data-integrity bug.
- **Bounded `curl_multi_select($h, 1)` + wall-clock guard** — avoids both the classic 100% CPU busy-loop _and_ the infinite block.
- **Response caching** so a proxy retry returns the original result instead of re-executing.
- **Credentials resolved strictly server-side**, never serialized to the browser.
- **Structured cURL/HTTP error → message mapping** with genuinely useful operator-facing text.

---

## 2. Current Problems (Ranked)

### CRITICAL

#### C1 — Privilege escalation on the AJAX endpoint

`handle_form_submission()` checks the nonce but **never checks a capability**. `enqueue_scripts()` localizes a valid `event_controller_submit` nonce on **every front-end page for every logged-in user**, including Subscribers.

Any authenticated user can POST to `event_controller_submit_event` and publish arbitrary events to all 50 remote production sites.

The `manage_options` gate exists only on the _shortcode render_, which is not a security boundary.

**Evidence:**

- `includes/class-event-controller-form-handler.php` — constructor registers the action; handler performs nonce check only.
- `public/class-event-controller-public.php` — `wp_localize_script()` runs unconditionally on `wp_enqueue_scripts`.
- `public/partials/event-controller-post-event.php` — the only `current_user_can()` in the plugin.

#### C2 — Permanent lock deadlock; no lock TTL

The lock is released only in `finally`. A fatal error, OOM kill, `max_execution_time` kill, or proxy-triggered process termination bypasses `finally`. The lock option then persists **forever**.

Every subsequent submission of that exact event takes the retry branch, blocks a PHP worker for 120s, and returns `Request processing timeout`. **That event can never be sent again without manual database surgery.**

There is no expiry, no owner PID, and no stale-lock reclaim.

#### C3 — `wait_for_result()` cannot see the result without a persistent object cache

The polling loop calls `get_option($result_key)`. On the first miss WordPress records the key in the per-request `notoptions` cache; subsequent `get_option()` calls **in that same PHP process return `false` without ever querying the database again**.

`wp_cache_get()` is equally useless — with no Redis/Memcached, the object cache is per-request only.

So on a site without a persistent object cache, the retry branch is **guaranteed** to burn a PHP worker for the full 120s and then fail — even though the original request succeeded. The user sees a failure for an event that was actually delivered.

#### C4 — Ambiguous outcomes are recorded as failures (at-least-once with no reconciliation)

If a remote site creates the event but the response is lost (cURL 28 at 30s, a 502 from _its_ proxy, a connection reset), the local system records `failed`.

There is no mechanism to determine whether the write landed. Any human retry duplicates the event.

**This is the single largest correctness gap in the plugin.**

#### C5 — The job is owned by the browser

No `ignore_user_abort()`, no persistence of progress.

Closing the tab, an nginx `proxy_read_timeout`, or PHP being interrupted at site 25 leaves sites 26–50 permanently unsent, with **no record anywhere** that they were supposed to receive the event. Partial delivery is silent and unrecoverable.

---

### HIGH

#### H1 — Memory blow-up at 50 sites

`upload_media_parallel()` calls `build_multipart_body()` **inside** the per-site loop, and that function calls `file_get_contents()` every time.

50 sites × a 4 MB image ≈ **200 MB+** of simultaneous PHP strings, plus cURL's own copy of each `CURLOPT_POSTFIELDS` buffer.

There is also a dead `$file_content = file_get_contents()` at the top of the function adding one more copy.

> The existing code comment warning to keep images under 1 MB is a _symptom_, not a fix.

#### H2 — Timeout budget cannot fit inside the proxy window

Worst case is 120s (media) + 120s (events) = **240s**, before counting credential lookup. The WPMUDEV/nginx proxy cuts at roughly 30–60s.

The architecture is structurally guaranteed to be killed mid-flight at scale. The idempotency lock is a mitigation for a problem the architecture itself creates.

#### H3 — No retry mechanism at all

One transient 503 permanently fails that site for that submission. The only remedy is a full manual resubmission, which re-posts to the sites that already succeeded → **duplicates**.

#### H4 — Result options are written with autoload and never deleted

`update_option()` on a new key defaults to autoload. Every submission permanently adds a row containing the full response payload that is loaded into memory on **every page request site-wide, forever**. This degrades the entire site over time.

#### H5 — Worker-pool exhaustion under proxy retries

The retry branch `sleep()`s for up to 120s while holding a php-fpm worker. Proxy retries plus concurrent users can consume the pool and take the whole site down.

#### H6 — `strpos()` cURL error mapping is wrong

```php
strpos( $curl_error, '7' )   // matches ANY error text containing the character "7"
```

e.g. `"...port 7000..."` or cURL error 47. Errors are frequently misclassified.

This will directly corrupt any future retryable/non-retryable decision, so it must be fixed before the retry system is built.

---

### MEDIUM

| ID     | Issue                                                                                                                                                                                              |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **M1** | Site identity derived from the display name (`strtolower(str_replace(' ','_',$name))`). Renaming a site silently changes its ID, breaking idempotency keys and history.                            |
| **M2** | O(N²) credential lookup — `get_site_credentials_by_id()` walks the entire repeater per site: 50 sites → 2,500 `get_sub_field()` iterations.                                                        |
| **M3** | Dead code / false comment — `handle_refresh_nonce()` is defined but **never hooked**. The JS comment "nonce refresh happens in background" is untrue. Long admin sessions hit silent nonce expiry. |
| **M4** | Fake progress UI — the modal lists sites, but nothing streams; all results land at once at the end.                                                                                                |
| **M5** | Unconditional, unbounded `error_log()` (~85 calls) including full event payload JSON, regardless of `WP_DEBUG`.                                                                                    |
| **M6** | `$_POST['nonce']` used unslashed/unsanitized.                                                                                                                                                      |
| **M7** | Sequential fallback is unusable (50 × 30s = 25 minutes) if `curl_multi` is missing.                                                                                                                |
| **M8** | God class — 931 lines mixing transport, locking, caching, transformation, error mapping, and AJAX.                                                                                                 |
| **M9** | Hardcoded `'country' => 'United States'` and `'content' => ''` in the payload transformer.                                                                                                         |

### LOW

| ID     | Issue                                                       |
| ------ | ----------------------------------------------------------- |
| **L1** | Dead `$file_content` variable in `upload_media_parallel()`. |
| **L2** | No `Retry-After` header handling.                           |
| **L3** | No per-site rate limiting.                                  |

---

## 3. Architecture Options

| Criterion                             | A: AJAX+fixes | B: AJAX+WP-Cron | C: Custom queue+Cron         | **D: Action Scheduler**          | E: External worker          |
| ------------------------------------- | ------------- | --------------- | ---------------------------- | -------------------------------- | --------------------------- |
| Survives browser close                | ✕             | ✓               | ✓                            | ✓                                | ✓                           |
| Survives proxy kill                   | ✕             | ✓               | ✓                            | ✓                                | ✓                           |
| Survives mid-run PHP kill             | ✕             | ✕ (job lost)    | ✓                            | ✓                                | ✓                           |
| Per-site retry                        | ✕             | hard            | ✓                            | ✓                                | ✓                           |
| Concurrency safety (2 workers, 1 job) | n/a           | ✕               | must hand-build              | ✓ claim-based                    | ✓                           |
| Execution-limit handling              | ✕             | manual          | manual                       | ✓ built-in time budget           | ✓                           |
| Scales to 50 sites                    | ✕             | marginal        | ✓                            | ✓                                | ✓                           |
| Hosting compatibility                 | ✓             | ✓               | ✓                            | ✓                                | ✕ needs infra               |
| Observability                         | ✕             | ✕               | build it                     | ✓ admin UI + logs                | ✓                           |
| Implementation cost                   | Low           | Low             | **High**                     | **Medium**                       | Very high                   |
| Maintenance burden                    | Low           | Low             | High (you own the scheduler) | **Low** (WooCommerce-maintained) | High                        |
| Ops burden                            | None          | None            | None                         | None                             | Redis/supervisor/monitoring |

### Rejections

- **Option A** cannot satisfy the requirement "the browser should be able to close." Structurally disqualified.
- **Option B** is the trap the brief explicitly warns about: moving a 240-second function into WP-Cron just relocates the timeout. WP-Cron has **no** claim locking (a second request can fire the same event), **no** retry, and if the process dies the job vanishes entirely.
- **Option C** is correct in shape but means writing and owning a claim mechanism, backoff scheduler, stale-claim reclaimer, and admin UI — the exact category of code that has historically produced this plugin's bugs.
- **Option E** is over-engineered. 50 sites × a few events per week is a throughput of essentially zero. Adding Redis and a supervised worker to managed WordPress hosting introduces far more operational failure modes than it removes.

### ✅ Recommendation: Option D

> **Action Scheduler for scheduling/claiming/execution, plus two custom tables for domain state.**

Action Scheduler is the de-facto WordPress background-job standard (bundled in WooCommerce, ~5M installs). It provides exactly the primitives that are missing and dangerous to hand-roll:

- Atomic claim-based locking across concurrent runners
- A time-budget-aware runner loop
- Async loopback triggering with WP-Cron fallback
- WP-CLI execution
- A built-in admin log UI

Critically, **it does not solve the domain problem** — so we keep our own tables for per-site state, idempotency, and reconciliation.

> **Action Scheduler schedules _when_. Our tables record _what happened_.**

**Fallback:** If adding the dependency is rejected, **Option C** uses the same data model and lifecycle; only the runner is hand-written. The design below is deliberately written so the runner is swappable.

---

## 4. Recommended Architecture

```
┌──────────────────────────────── BROWSER ────────────────────────────────┐
│  Submit  ──POST──▶  REST /event-controller/v1/jobs   (short, < 1s)      │
│  Poll    ──GET───▶  REST /event-controller/v1/jobs/{id}   every 3s      │
│  (browser may close at any time — it observes, it does not own)         │
└──────────────────────────────────┬──────────────────────────────────────┘
                                   ▼
                     ┌─────────────────────────────┐
                     │  Job_Manager::create()      │
                     │  • capability + nonce       │
                     │  • persist image to disk    │  ← ESSENTIAL
                     │  • INSERT ec_jobs           │
                     │    (UNIQUE idempotency_key) │  ← replaces add_option lock
                     │  • INSERT 50 × ec_site_jobs │
                     │  • enqueue 50 AS actions    │
                     └──────────────┬──────────────┘
                                    │ returns job_id immediately
                                    ▼
        ═══════════ browser may now close ═══════════
                                    │
    ┌─────────── ACTION SCHEDULER RUNNER (async / cron / CLI) ─────────────┐
    │   claims actions atomically · honours time + memory budget           │
    └──────────────┬─────────────┬─────────────┬───────────────────────────┘
                   ▼             ▼             ▼        (3–5 concurrent)
             ec_deliver_site  ec_deliver_site  ec_deliver_site
                   │
                   ▼
    ┌──────────────────────────────────────────────┐
    │  Site_Delivery_Worker::run(site_job_id)      │
    │   1. Circuit breaker check → defer if OPEN   │
    │   2. Load state; skip if COMPLETE (idem.)    │
    │   3. MEDIA phase  (skip if remote_media_id)  │
    │   4. EVENT phase                             │
    │   5. Ambiguous? → RECONCILE before retry     │  ← duplicate prevention
    │   6. Classify → COMPLETE | retry | FAILED    │
    │   7. Persist; roll up parent job             │
    └──────────────────────────────────────────────┘
```

### 4.1 Component decomposition

| Class                                    | Responsibility                                       | Origin                      |
| ---------------------------------------- | ---------------------------------------------------- | --------------------------- |
| `EC_Job_Manager`                         | create/cancel/retry jobs, idempotency, rollups       | new                         |
| `EC_Site_Job_Repository`                 | all SQL for `ec_site_jobs`, atomic state transitions | new                         |
| `EC_Delivery_Worker`                     | one site job end-to-end; phase machine               | new                         |
| `EC_Events_API_Client`                   | media + event HTTP, single-site                      | **extracted from existing** |
| `EC_Payload_Transformer`                 | `transform_to_em_api_schema()`                       | **moved verbatim**          |
| `EC_Error_Classifier`                    | cURL/HTTP → retryable / permanent / **ambiguous**    | rebuilt from existing map   |
| `EC_Retry_Policy`                        | backoff + jitter + attempt caps                      | new                         |
| `EC_Circuit_Breaker`                     | per-site health                                      | new                         |
| `EC_Reconciler`                          | ambiguous-result resolution                          | new                         |
| `EC_Site_Registry`                       | load ACF repeater **once**, stable site keys         | fixes M1/M2                 |
| `EC_Logger`                              | correlation-ID structured logging                    | rebuilt from existing       |
| `EC_Admin_Screen` + `EC_REST_Controller` | UI + status API                                      | new                         |
| `Event_Controller_Form_Handler`          | **kept intact** behind a feature flag                | preserved                   |

### 4.2 Disposition of every existing mechanism

> This table exists specifically to guarantee nothing working is accidentally removed.

| Mechanism                       | Disposition                 | Why                                                                                                                                                    |
| ------------------------------- | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| SHA256 payload idempotency key  | **Preserved, promoted**     | Becomes `ec_jobs.idempotency_key UNIQUE` — same concept, now enforced by MySQL and durable                                                             |
| `add_option()` atomic lock      | **Replaced**                | The UNIQUE index gives the same atomicity with no TTL/deadlock risk (fixes C2). Concept preserved, primitive upgraded                                  |
| `wait_for_result()` polling     | **Replaced**                | Broken by `notoptions` (C3). Duplicate submit now returns the existing `job_id` instantly                                                              |
| `update_option` result cache    | **Replaced**                | The job row _is_ the durable result; also fixes autoload bloat (H4)                                                                                    |
| `wp_cache_set` result cache     | **Removed**                 | Non-persistent → provably ineffective                                                                                                                  |
| `curl_multi` parallel transport | **Preserved but demoted**   | Retained as a class, off the default path. Per-site actions give better retry granularity and bounded memory. **Do not delete until Phase 7 sign-off** |
| Per-site media IDs              | **Preserved, strengthened** | Now persisted in `ec_site_jobs.remote_media_id` → survives restart, prevents re-upload                                                                 |
| Media/event two-phase split     | **Preserved, formalised**   | Becomes explicit persisted phase states                                                                                                                |
| 30s per-request timeout         | **Preserved**               | Correct per-request value; now split as connect 10s / total 30s                                                                                        |
| 120s overall multi guard        | **Superseded**              | Replaced by the worker time budget                                                                                                                     |
| cURL/HTTP error → message map   | **Preserved, fixed**        | Same strings; `strpos` matching replaced with `curl_errno()` integers (fixes H6)                                                                       |
| Credential protection           | **Preserved, hardened**     | Add: never log credentials, redact `Authorization` headers                                                                                             |
| Nonce validation                | **Preserved, extended**     | **Plus** `current_user_can()` (fixes C1)                                                                                                               |
| `transform_to_em_api_schema()`  | **Preserved verbatim**      | Zero behaviour change; only gains `_ec_idempotency_key`                                                                                                |
| `error_log()` diagnostics       | **Restructured**            | Same events, correlation IDs, level-gated                                                                                                              |

---

## 5. Data Model

> **Custom tables. Not options, not CPTs, not transients.**

### 5.1 Why not the alternatives

| Storage                  | Verdict                                                                                             |
| ------------------------ | --------------------------------------------------------------------------------------------------- |
| `wp_options`             | ✕ No index on `option_value`, no atomic conditional update, autoload pollution (already causing H4) |
| Custom Post Type         | ✕ 50 rows per event into `wp_posts`/`wp_postmeta`; forces slow `meta_query` scans                   |
| Transients               | ✕ Can be evicted by an object cache at any moment — catastrophic for job state                      |
| `wp_cache`               | ✕ Per-request without Redis (this is exactly bug C3)                                                |
| Action Scheduler tables  | ✕ For _scheduling_, not domain data                                                                 |
| **Custom InnoDB tables** | ✅ Atomic updates, proper indexes, queryable, bounded cleanup                                       |

### 5.2 Schema

```sql
CREATE TABLE {prefix}ec_jobs (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  idempotency_key   CHAR(64)        NOT NULL,
  status            VARCHAR(20)     NOT NULL,   -- queued|processing|completed|
                                                -- completed_with_errors|failed|cancelled
  created_by        BIGINT UNSIGNED NOT NULL,
  event_title       VARCHAR(255)    NOT NULL,
  event_payload     LONGTEXT        NOT NULL,   -- JSON snapshot
  media_path        VARCHAR(255)    NULL,       -- durable copy, NOT $_FILES tmp
  media_meta        TEXT            NULL,
  total_sites       SMALLINT UNSIGNED NOT NULL,
  succeeded_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  failed_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL,
  completed_at      DATETIME NULL,
  UNIQUE KEY uk_idem (idempotency_key),
  KEY idx_status_created (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE {prefix}ec_site_jobs (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id            BIGINT UNSIGNED NOT NULL,
  site_key          VARCHAR(100)    NOT NULL,   -- STABLE id, not derived from name
  site_name         VARCHAR(255)    NOT NULL,
  site_url          VARCHAR(255)    NOT NULL,   -- snapshot at creation
  status            VARCHAR(20)     NOT NULL,   -- queued|processing|retrying|
                                                -- complete|failed|blocked|cancelled
  phase             VARCHAR(20)     NOT NULL,   -- pending|media|event|done
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  remote_media_id   BIGINT UNSIGNED NULL,
  remote_event_id   BIGINT UNSIGNED NULL,
  last_http_status  SMALLINT UNSIGNED NULL,
  last_error_code   VARCHAR(50)     NULL,
  last_error_message TEXT           NULL,
  last_attempt_at   DATETIME NULL,
  next_attempt_at   DATETIME NULL,
  completed_at      DATETIME NULL,
  UNIQUE KEY uk_job_site (job_id, site_key),      -- per-site idempotency
  KEY idx_ready (status, next_attempt_at),
  KEY idx_job (job_id, status)
) ENGINE=InnoDB;
```

**Optional:** `{prefix}ec_job_log` (`job_id`, `site_job_id`, `correlation_id`, `level`, `phase`, `message`, `context` JSON, `created_at`) with a 30-day purge.

### 5.3 Two structural points that carry most of the reliability win

**1. `uk_idem` replaces the option lock.**
Concurrent duplicate submissions race on an `INSERT`; the loser catches the duplicate-key error and returns the existing `job_id`. Atomic, durable, self-cleaning, and **impossible to deadlock** — directly eliminating C2 and C3.

**2. `media_path` is mandatory.**
`$_FILES['tmp_name']` is deleted the instant the request ends. A background worker **cannot** read it. The upload must be copied to a durable private location at job creation.

> This is the most commonly missed consequence of going asynchronous.

**Retention:** purge completed jobs and their site rows after 90 days via a recurring Action Scheduler task.

---

## 6. Job Lifecycle

```
[Send] ─▶ REST POST /jobs
           ├ current_user_can('manage_options')  ← fixes C1
           ├ verify nonce
           ├ validate payload
           ├ persist image → uploads/ec-private/{key}.jpg
           ├ INSERT ec_jobs  ── duplicate key? ─▶ return existing job_id  (200)
           ├ INSERT 50 × ec_site_jobs (status=queued)
           ├ as_enqueue_async_action('ec_deliver_site', [site_job_id]) × 50
           └─▶ 202 { job_id: 1024 }              ~200–400 ms
                    │
                    ▼
        ═══ browser may now close ═══
                    │
    ┌───────────────▼────────────── ec_deliver_site(site_job_id) ──────────────┐
    │                                                                          │
    │  status = SELECT ... FOR UPDATE                                          │
    │  ├ complete/cancelled?  ─▶ return (idempotent no-op)                     │
    │  ├ circuit OPEN?        ─▶ status=blocked, reschedule +cooldown, return  │
    │  │                          (does NOT consume an attempt)                │
    │  ├ attempts++ ; status=processing ; last_attempt_at=now                  │
    │  │                                                                       │
    │  ├─ PHASE MEDIA   (skip if remote_media_id IS NOT NULL)  ← key win       │
    │  │    POST /media  ─ ok ─▶ persist remote_media_id, phase=event          │
    │  │                └ err ─▶ classify → retry | fail                       │
    │  │                                                                       │
    │  ├─ PHASE EVENT                                                          │
    │  │    POST /events (+ _ec_idempotency_key)                               │
    │  │      ├ 200/201 ─▶ persist remote_event_id, status=complete            │
    │  │      ├ permanent (400/401/403/404/422) ─▶ status=failed, STOP         │
    │  │      ├ retryable (429/conn refused)    ─▶ backoff, reschedule         │
    │  │      └ AMBIGUOUS (timeout/reset/5xx)   ─▶ RECONCILE                   │
    │  │             ├ found remotely ─▶ status=complete (no duplicate!)       │
    │  │             └ not found      ─▶ backoff, reschedule                   │
    │  │                                                                       │
    │  ├─ circuit breaker: record success/failure for site_key                 │
    │  └─ roll up parent: succeeded/failed counts; if all terminal →           │
    │        completed | completed_with_errors | failed ; notify admin         │
    └──────────────────────────────────────────────────────────────────────────┘
```

---

## 7. Failure Handling

### 7.1 Retry classification

| Signal                                                   | Class         | Action                          |
| -------------------------------------------------------- | ------------- | ------------------------------- |
| cURL 6 (DNS), 7 (refused), 35/51/60 (TLS)                | Retryable     | backoff                         |
| **cURL 28 (timeout), 52 (empty reply), 56 (recv reset)** | **Ambiguous** | **reconcile first**             |
| HTTP 408, 429                                            | Retryable     | backoff; honour `Retry-After`   |
| HTTP 500                                                 | **Ambiguous** | reconcile first                 |
| HTTP 502, 503, 504                                       | **Ambiguous** | reconcile first                 |
| HTTP 400, 422                                            | Permanent     | fail — payload is wrong         |
| HTTP 401, 403                                            | Permanent     | fail — credentials; alert admin |
| HTTP 404                                                 | Permanent     | fail — remote plugin missing    |
| HTTP 413                                                 | Permanent     | fail — image too large          |
| 2xx with unparseable JSON                                | Ambiguous     | reconcile                       |

> The **"ambiguous"** class is the piece the current code lacks entirely, and it is where duplicates come from.
>
> Note that 500/502/504 are ambiguous, **not** simply retryable — a WordPress fatal _after_ `wp_insert_post()` returns 500 with the event already created.

### 7.2 Backoff

```
delay = min(1m × 4^(attempt-1), 6h) × jitter(0.5–1.5)
```

→ approximately **1m, 4m, 16m, 64m, 6h**

**`max_attempts = 5`** (~7h of coverage — enough to ride out a maintenance window).

> Jitter is mandatory. Without it, 50 site jobs retry in lockstep and produce synchronised thundering herds.

Circuit-breaker deferrals and 429 `Retry-After` waits must **not** increment `attempts`.

### 7.3 Circuit breaker — recommended, per site

```
CLOSED → (5 consecutive failures) → OPEN (15 min)
       → HALF_OPEN (1 probe) → CLOSED | OPEN (×2, cap 4h)
```

State stored in a single `ec_site_health` option.

**Why it matters:** without it, one dead site means 50 queued jobs × 5 attempts = **250 doomed 30-second requests** clogging the runner and delaying healthy sites. With it: one probe per 15 minutes.

When OPEN, jobs are marked `blocked` and rescheduled — they are **not** failed, and no attempt is consumed.

### 7.4 Worker execution limits

Never rely on one long-lived worker.

```php
$deadline = microtime(true) + min(20, ini_get('max_execution_time') * 0.5);
$mem_cap  = memory_limit_bytes() * 0.75;

// before each site job:
if (microtime(true) > $deadline || memory_get_usage(true) > $mem_cap) {
    return;  // remaining actions stay queued; next runner continues
}
```

Action Scheduler enforces this natively via its `time_limit` filter.

Because state lives in the DB and every site job is independent and idempotent, **stopping is always safe** — there is no "half-finished" global state. Also set `ignore_user_abort(true)` in the worker.

### 7.5 Media vs Event phases — keep them separate

Keep them separate, but **persist the phase boundary**.

Today, a failure in the event phase means a full resubmission re-uploads the image, orphaning a duplicate attachment on the remote site. With `remote_media_id` persisted, a retry resumes directly at the event phase.

> This alone removes an entire class of remote-side garbage.

### 7.6 Scenario walkthrough

| #   | Scenario                                 | Today                                                                         | Recommended                                                                                              |
| --- | ---------------------------------------- | ----------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| 1   | All 50 succeed                           | Works _if_ under proxy timeout; likely killed at scale                        | 50 independent jobs; job → `completed`; browser optional                                                 |
| 2   | 5 sites fail                             | Permanent. Resubmit re-posts to the 45 that worked → **45 duplicates**        | 45 `complete`, 5 auto-retry with backoff; only the 5 are ever touched                                    |
| 3   | Timeout _after_ remote created the event | Recorded failed. Manual retry → **duplicate**                                 | Classified **ambiguous** → reconciler finds the event → `complete` + `remote_event_id`. **No duplicate** |
| 4   | Browser closes right after Send          | PHP may be killed; unsent sites lost silently                                 | Job already persisted; workers continue; admin sees result later                                         |
| 5   | nginx terminates the AJAX request        | Proxy retries → retry branch blocks 120s → `notoptions` bug → false failure   | Request was ~300 ms. Nothing to terminate                                                                |
| 6   | PHP interrupted while processing site 25 | Sites 26–50 lost; **lock never released (C2)** → event permanently unsendable | Site 25's action is unclaimed after the stale-claim window and re-runs; 26–50 untouched in queue         |
| 7   | Worker crashes after site 20             | Same as #6                                                                    | 20 rows `complete`; next runner picks up 21+                                                             |
| 8   | Same event submitted twice               | Second call takes retry branch, blocks 120s, then usually false-fails         | `uk_idem` violation → returns existing `job_id` instantly                                                |
| 9   | Two workers grab the same job            | Possible; **would double-post**                                               | AS claim locking + `SELECT … FOR UPDATE` + `complete` short-circuit                                      |
| 10  | Site offline for hours                   | Instant permanent failure                                                     | Circuit breaker OPEN; retries out to ~7h; then `failed` with a one-click retry                           |
| 11  | Media OK, event fails                    | Whole site failed; retry re-uploads → orphan attachment                       | `remote_media_id` persisted; retry resumes at event phase only                                           |
| 12  | HTTP 500 but event _was_ created         | Recorded failed → retry duplicates                                            | 500 = ambiguous → reconcile → `complete`. **No duplicate**                                               |

---

## 8. Duplicate Prevention

Three independent layers. Layers 1 and 2 stop _local_ duplicates; only layer 3 stops _remote_ duplicates — and it is the one that does not exist today.

### Layer 1 — Job dedupe

`ec_jobs.idempotency_key UNIQUE`. Concurrent identical submissions collapse to one job row via a MySQL constraint. No TTL, no deadlock, no polling.

### Layer 2 — Per-site dedupe

`uk_job_site (job_id, site_key)` plus a `status = complete → return` short-circuit at the top of the worker.

Re-running a delivery action any number of times is a no-op. This makes worker retries, Action Scheduler retries, and cron double-fires all harmless.

### Layer 3 — Remote-side idempotency

> The hard case: **the remote processed the request but we never saw the response.**

> **DECISION: Remote companion plugin cannot be modified (confirmed 2026-08-19).**
> The `_ec_idempotency_key` remote-side check described below is **not available** for this project. It is documented here for completeness and in case remote access changes in the future, but **the Reconciler (below) is the only line of defense against remote duplicates.** Treat it as load-bearing, not a fallback.

#### Not available — would require a change to the companion remote plugin

Send `_ec_idempotency_key = sha256(job_idempotency_key + site_key)`.

In the remote `em_api_event_save` handler, before creating:

```php
$existing = get_posts([
  'post_type'   => 'event',
  'meta_key'    => '_ec_idempotency_key',
  'meta_value'  => $key,
  'post_status' => 'any',
  'numberposts' => 1,
  'fields'      => 'ids',
]);

if ( $existing ) {
    return rest_ensure_response([ 'id' => $existing[0], 'ec_deduplicated' => true ]);
}

// ... create the event, then:
add_post_meta( $id, '_ec_idempotency_key', $key, true );
```

This makes remote creation **truly idempotent**, converting the whole pipeline from _at-least-once_ to _effectively-exactly-once_.

> Revisit this if the remote plugin ever becomes modifiable — it remains the highest-value single change possible for this system, currently unavailable.

#### REQUIRED implementation: the Reconciler

On any _ambiguous_ outcome, do **not** retry blindly. First query the remote:

```
GET /wp-json/wp/v2/event?search={title}&after={created_at - 10m}&per_page=5
   (or /events-manager/v1/events with the same window)
```

Match on title + `event_start_date` + author within the attempt window:

| Result            | Action                                                         |
| ----------------- | -------------------------------------------------------------- |
| Exactly one match | Treat as success, store `remote_event_id`, mark `complete`     |
| No match          | Safe to retry                                                  |
| Multiple matches  | Mark `needs_review`, surface in admin UI, **never auto-retry** |

If reconciliation itself fails (site still down), keep the site job in `ambiguous_pending` and re-reconcile on the next backoff tick rather than re-posting.

> **Invariant:** a site job may only move from `ambiguous` to a retry **after a successful negative reconciliation**. That rule is what guarantees no duplicates.

> **Because remote-side idempotency is unavailable, this invariant is the entire safety net for ambiguous outcomes.** The reconciler's matching query (title + `event_start_date` + author within the attempt time window) must be conservative by design: a false negative (no match found when the event actually exists) causes a duplicate; a false positive (matching the wrong event) causes a job to be marked complete incorrectly. Both must be tested explicitly in Phase 4 before this system goes live — see the expanded test matrix below.

---

## 9. Admin UI & Observability

### 9.1 What to implement

**Implement now:** Job ID · live overall progress · per-site status with attempt count · error message · `remote_event_id` (linked to the remote post) · last attempt time · next retry time · "Retry failed sites" · "Cancel job" · per-site log drawer.

**Defer to Phase 8+:** full-text log search · per-site analytics · email digests.

The submit modal becomes:

> _"Job #1024 created — 50 sites queued. You can safely close this window."_

Progress via `GET /event-controller/v1/jobs/{id}` polled every 3s, backing off to 10s after 2 minutes, stopping when terminal.

### 9.2 Proposed layout

```
Event: Annual Conference                        Job #1024
[███████████████████████████░░░]  43 / 50

✓ 43 succeeded    ⟳ 2 processing    ↻ 3 retrying    ✕ 2 failed

Site                Status      Try  Remote ID   Next retry   Detail
───────────────────────────────────────────────────────────────────────
Stetson Hills       ✓ complete   1   #4821        —           1.2 s
Oak Valley          ↻ retrying   3   —            in 14 m     503 Service Unavailable
Pine Ridge          ✕ failed     1   —            —           401 Auth failed — check credentials
Cedar Park          ⛔ blocked    2   —            in 11 m     Circuit open (5 consecutive failures)

                                              [Retry failed sites]  [Cancel job]
```

> A separate **Site Health** panel showing circuit-breaker state per site pays for itself immediately — it turns _"the plugin is broken"_ into _"Pine Ridge's application password expired."_

### 9.3 Logging strategy

Correlation IDs are worth it — they are the difference between grepping 85 undifferentiated lines and reading one job's story.

**Format:** `EC-{job}-S{site}-{PHASE}-A{attempt}` → `EC-1024-S17-EVENT-A3`

```
[EC-1024-S17-EVENT-A3] WARN  http_status=504 duration=30.1s class=ambiguous
[EC-1024-S17-EVENT-A3] INFO  reconcile: querying remote for idempotency key
[EC-1024-S17-EVENT-A3] INFO  reconcile: match found remote_event_id=4821 → complete
```

Rules:

- Gate verbosity behind an `ec_log_level` option (default `warn` in production, `debug` on demand)
- **Always redact `Authorization` headers**
- Never log full payloads at `info` level
- Write to `ec_job_log`, not `error_log()`, so entries are queryable and purgeable

### 9.4 Performance: concurrency recommendation

> **Do not send to 50 sites simultaneously.**

| Concurrency                | Verdict                                                                                                                     |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| 50 at once                 | ✕ 50 × filesize in RAM; likely self-DDoS if remotes share a host/IP; one slow site holds the whole batch; no per-site retry |
| 20                         | ✕ Same failure modes, smaller magnitude                                                                                     |
| **3–5 (per-site actions)** | ✅ **Recommended.** Bounded memory, no herd, per-site isolation, natural backpressure                                       |
| 1                          | Safe but slow; acceptable fallback                                                                                          |

Because the work is now asynchronous, **wall-clock time stops being a constraint**. 50 sites at ~2–4 s each with 4 concurrent runners ≈ 30–50 s total — but nobody is waiting on it.

> Trading latency for reliability is the correct trade here.

Also switch media uploads to `CURLFile`/streamed bodies instead of building the multipart string in memory.

---

## 10. Implementation Plan

> **Revised per confirmed decisions:** no standalone Phase 0 patch — those fixes are folded into Phase 1. No remote companion-plugin phase — that phase is removed; the Reconciler absorbs its responsibility and is promoted to "critical path," not optional hardening.

| Phase | Scope                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | Behind flag  | Risk                                    |
| ----- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ | --------------------------------------- |
| **1** | Tables + activator/upgrade routine, `EC_Site_Registry` with stable site keys, durable media storage, `EC_Job_Manager::create()`, REST create/status endpoints. **Includes former Phase-0 fixes:** `current_user_can()` (C1); nonce localized only for capable users; lock TTL/stale reclaim (C2); `autoload=no` + TTL cleanup (H4); hoist `build_multipart_body()` out of the loop + drop dead `$file_content` (H1); `curl_errno()` instead of `strpos` (H6); jQuery `timeout`. No worker yet | Yes          | Low                                     |
| **2** | Action Scheduler integration, `EC_Delivery_Worker` skeleton, time/memory budget, claim safety                                                                                                                                                                                                                                                                                                                                                                                                 | Yes          | Medium                                  |
| **3** | Extract `EC_Events_API_Client` + `EC_Payload_Transformer` **verbatim**; media/event phase machine with `remote_media_id` persistence                                                                                                                                                                                                                                                                                                                                                          | Yes          | Medium                                  |
| **4** | `EC_Error_Classifier`, `EC_Retry_Policy`, **`EC_Reconciler`** (now the sole remote-dedup mechanism — see Section 8), circuit breaker                                                                                                                                                                                                                                                                                                                                                          | Yes          | **Critical — most testing effort here** |
| **5** | Admin job list + detail UI, REST polling, retry/cancel, site-health panel                                                                                                                                                                                                                                                                                                                                                                                                                     | Yes          | Low                                     |
| **6** | `EC_Logger`, `ec_job_log`, correlation IDs, retention purge                                                                                                                                                                                                                                                                                                                                                                                                                                   | Yes          | Low                                     |
| **7** | Load testing, chaos testing, flag default → on; retire `curl_multi` path after one clean release                                                                                                                                                                                                                                                                                                                                                                                              | Flip default | Medium                                  |

### 10.1 Test matrix for Phase 4 (expanded — Reconciler is now load-bearing)

All must pass before the feature flag is flipped:

- [ ] Kill the worker mid-run
- [ ] Mock returns 504 but **did** write the event → reconciler must find it and mark `complete`
- [ ] Mock returns 504 and did **not** write the event → reconciler must find nothing and allow retry
- [ ] Reconciler search returns **two** plausible matches (e.g. two similarly-titled events in the window) → must mark `needs_review`, never auto-resolve
- [ ] Reconciler search itself times out or errors → site job stays `ambiguous_pending`, retried on next backoff tick, never falls through to a blind repost
- [ ] Two events with near-identical titles/dates submitted close together to the same site → reconciler must not cross-match them
- [ ] Point 5 sites at a blackhole address
- [ ] Submit the identical event 3× concurrently
- [ ] Run 2 workers against 1 job
- [ ] Expire an application password mid-job

---

## 11. Migration Strategy

Zero big-bang. The existing handler is left **fully intact and functional** throughout.

1. **Phase 1 ships as a single combined release** (former Phase 0 fixes + job infrastructure — no standalone hotfix, per confirmed decision).
2. Add option `ec_delivery_mode` = `legacy` (default) | `queue`.
3. `handle_form_submission()` gains a **single early branch**: if mode is `queue`, delegate to `EC_Job_Manager` and return `{job_id}`; otherwise fall through to the untouched existing code.
4. The frontend branches on the response shape: `job_id` present → show the job panel; otherwise use today's modal. Both code paths coexist.
5. **Rollout:** staging (all 50 sites) → production **canary with 2–3 sites** → 10 sites → all 50.
6. **Rollback is one option flip.** No schema change, no data loss — queued jobs simply drain.
7. Remove the legacy path only after one full release cycle with zero queue-path incidents.

Tables are created in `Event_Controller_Activator::activate()` with a stored `ec_db_version` and a `plugins_loaded` upgrade check, so the schema exists before the flag can be flipped.

`uninstall.php` must drop the new tables and purge the stored media directory.

---

## 12. Files to Create / Change

### Create

```
includes/db/class-ec-schema.php
includes/db/class-ec-job-repository.php
includes/db/class-ec-site-job-repository.php
includes/jobs/class-ec-job-manager.php
includes/jobs/class-ec-delivery-worker.php
includes/jobs/class-ec-retry-policy.php
includes/jobs/class-ec-circuit-breaker.php
includes/jobs/class-ec-reconciler.php
includes/api/class-ec-events-api-client.php      ← extracted, logic unchanged
includes/api/class-ec-payload-transformer.php    ← moved verbatim
includes/api/class-ec-error-classifier.php
includes/class-ec-site-registry.php
includes/class-ec-logger.php
includes/rest/class-ec-rest-controller.php
admin/class-ec-jobs-screen.php
admin/partials/ec-job-list.php
admin/partials/ec-job-detail.php
admin/js/ec-job-monitor.js
vendor/action-scheduler/                          ← dependency
```

### Modify

| File                                               | Change                                                                                                          |
| -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `event-controller.php`                             | require new classes; bump version                                                                               |
| `includes/class-event-controller-activator.php`    | create tables, `ec_db_version`                                                                                  |
| `includes/class-event-controller-deactivator.php`  | unschedule Action Scheduler actions                                                                             |
| `includes/class-event-controller-form-handler.php` | Security fixes (C1/C2/H1/H4/H6, folded into Phase 1) + feature-flag branch (existing logic otherwise untouched) |
| `includes/class-event-controller.php`              | register admin screen + REST routes                                                                             |
| `public/class-event-controller-public.php`         | localize nonce **only for capable users** (C1)                                                                  |
| `public/partials/event-controller-form-script.php` | ajax `timeout`; `job_id` response branch                                                                        |
| `uninstall.php`                                    | drop tables, purge media dir                                                                                    |

### Remote companion plugin

**Not in scope** — remote sites are not modifiable (confirmed 2026-08-19). The `_ec_idempotency_key` change is documented in Section 8 for future reference only.

---

## 13. Summary & Decisions Required

The current implementation is a **well-patched synchronous design that has hit its structural ceiling**.

Every remaining bug — the deadlocking lock, the broken `notoptions` polling, the 240-second budget inside a 45-second proxy window, the absence of retries — traces back to one root cause:

> **The browser request owns the job.**

The recommendation is deliberately conservative. It preserves every mechanism the team has already built and debugged (idempotency keys, two-phase media/event, per-site media IDs, error mapping, credential isolation), moves them into classes, and changes only _where the work runs_ and _how outcomes are recorded_.

It adds the two things that cannot be retrofitted onto a synchronous design:

1. **Durable per-site state**
2. **Ambiguous-result reconciliation**

### Decisions — CONFIRMED (2026-08-19)

| #     | Decision                                        | Answer                                       | Consequence                                                                                                                                                                                                                     |
| ----- | ----------------------------------------------- | -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **1** | Ship Phase 0 as a standalone hotfix now?        | **No** — folded into Phase 1 instead         | C1 (missing capability check) and C2 (lock deadlock) remain live in production until Phase 1 ships. Accepted risk — keep the exposure window short.                                                                             |
| **2** | Can the remote companion plugin be modified?    | **No** — remote sites are out of our control | The `_ec_idempotency_key` remote check is unavailable. The **Reconciler is the sole mechanism** for detecting remote duplicates on ambiguous outcomes (Section 8). It is now a critical-path component, not optional hardening. |
| **3** | Is Action Scheduler acceptable as a dependency? | **Yes**                                      | Option D confirmed; `vendor/action-scheduler/` will be bundled.                                                                                                                                                                 |

---

_End of review. No files have been modified. Implementation proceeds starting with Phase 1 once scheduled._
