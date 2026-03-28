# CD Ingest System — API Specification

**Version:** 1.0 (implemented)
**Transport:** HTTP/1.1 over localhost only
**Base URL:** `http://localhost:{port}/api`
**Content-Type:** All requests and responses use `application/json` unless otherwise noted.

---

## General Conventions

### Response Envelope

All responses follow a standard envelope:

```json
{
    "ok": true,
    "data": { ... }
}
```

On error:

```json
{
    "ok": false,
    "error": {
        "code": "MACHINE_READABLE_CODE",
        "message": "Human-readable description."
    }
}
```

HTTP status codes are used conventionally: `200` for success, `400` for bad input, `404` for not found, `409` for a conflict (e.g. rip already in progress), `500` for server-side errors.

### State Enum

The rip process is always in one of the following states. This value appears in several endpoints.

| State | Meaning |
|---|---|
| `idle` | No rip in progress. System is waiting. |
| `ripping` | A rip is actively in progress. |
| `complete` | The most recent rip completed successfully. |
| `damaged` | The most recent rip finished with disc read errors. Partial files are preserved. |
| `cancelled` | The operator cancelled the most recent rip. |

### Directory Naming

Output directories are named using a **slug + hash** scheme to ensure both human readability and uniqueness:

- **Slug:** Location ID lowercased, all non-alphanumeric characters replaced with underscores, consecutive underscores collapsed.
- **Hash:** First 8 characters of the MD5 hash of the **spaces-stripped, lowercased** form of the Location ID. This ensures that spacing variants (e.g. "ARC 1 M" and "ARC1M") always produce the same hash and resolve to the same directory.
- **Format:** `{slug}_{hash}` e.g. `arc_1_m_a3f4b2c1`

Damaged rip directories additionally include a datetime suffix:
`{slug}_{hash}_damaged_{YYYYMMDDTHHmmss}` e.g. `arc_1_m_a3f4b2c1_damaged_20260324T143022`

This allows multiple damaged attempts at the same disc to coexist without overwriting one another.

### Metadata File

Every completed or damaged output directory contains a `meta.json` file:

```json
{
    "metadata_version": 1,
    "location_id": "ARC 1 M",
    "directory_name": "arc_1_m_a3f4b2c1",
    "subject": "Archaeology",
    "description": "Records from the Portable Antiquity Scheme...",
    "rip_started_at": "2026-03-24T14:30:22Z",
    "rip_completed_at": "2026-03-24T14:38:05Z",
    "status": "ok",
    "track_count": 4,
    "bad_sector_count": 0,
    "rip_command": "cdparanoia --never-skip=3 -d /dev/sr0",
    "encode_command": "lame --preset voice",
    "rip_log": "... full combined output from ripping and encoding ...",
    "in_catalogue": true
}
```

| `status` value | Meaning |
|---|---|
| `ok` | Rip and encode completed successfully, all tracks written as MP3. |
| `damaged` | Rip completed with disc read errors on one or more tracks. Partial or zero-padded files may be present. |
| `cancelled` | Operator cancelled the rip. Partial files may be present. |

`rip_command` records the exact cdparanoia invocation used (including the configured options string). `encode_command` records the exact LAME invocation used. Both are taken directly from config at the time of processing, providing a permanent record of the tools and options applied to each disc. `rip_log` captures the combined output of both ripping and encoding stages.

A directory without a `meta.json`, or whose `meta.json` has `status` other than `ok`, is not considered a finished rip.

`metadata_version` is an integer incremented whenever the schema changes, allowing future tooling to handle older records gracefully.

---

### Rip State File

The rip worker process communicates progress to the status endpoint via a shared file at the path specified by `state_file` in `config.ini`. This file is written by the rip worker and read by `GET /api/rip/status` and `POST /api/rip/cancel`. No locking is required — reads are non-blocking and a slightly stale value is acceptable.

**Schema:**

```json
{
    "state": "ripping",
    "location_id": "ARC 1 M",
    "progress_pct": 42,
    "track_progress_pct": 67,
    "tracks_done": 1,
    "tracks_total": 4,
    "current_track": 2,
    "current_track_phase": "ripping",
    "bad_sectors": 0,
    "log_tail": "... last few lines of combined rip/encode output ...",
    "pid": 12345,
    "cancel_requested": false
}
```

| Field | Type | Description |
|---|---|---|
| `state` | string | Current state (see State Enum). |
| `location_id` | string or null | Location ID of the disc being ripped. `null` when idle. |
| `progress_pct` | int | Overall percentage complete, 0–100. Computed from actual sector counts: `(sectors_ripped_so_far + ripFraction × current_track_sectors) / total_sectors × 100`. During encoding of a track, stays at the post-rip level for that track (encoding is not counted in audio progress). |
| `track_progress_pct` | int | Progress through the current track being ripped, 0–100. Derived from the growing WAV file size vs. the predicted size for that track. `0` when not actively ripping a track. |
| `tracks_done` | int | Tracks fully ripped and encoded. |
| `tracks_total` | int | Total tracks on the disc. |
| `current_track` | int | Track currently being processed. `0` when idle. |
| `current_track_phase` | string | `"ripping"` or `"encoding"`. Empty string when idle. |
| `bad_sectors` | int | Cumulative bad sector count across all tracks so far. |
| `log_tail` | string | Last few lines of combined rip and encode output. Empty string when idle. |
| `pid` | int or null | PID of the rip worker process. Used for stale-rip detection. `null` when idle or after process has exited. |
| `cancel_requested` | bool | Set to `true` by `POST /api/rip/cancel`. The worker polls this flag every ~200ms and handles cancellation gracefully. |

When `state` is `idle`, all numeric fields are `0`, all string fields are empty strings, `location_id` is `null`, `pid` is `null`, and `cancel_requested` is `false`.

The state file is initialised to an idle record at application start and reset to idle after a rip completes, fails, or is cancelled. The `state` field transitions: `idle` → `ripping` → (`complete` | `damaged` | `cancelled`), then back to `idle` once the frontend has acknowledged the terminal state via the next `WAITING_FOR_DISC` transition (the backend resets the state file when a new rip starts).

---

### Stale Rip Detection

A shared helper `resolveStaleRip()` (in `lib/response.php`) is called at every API entry point that gates on rip state: `api/drive/status.php`, `api/rip/start.php`, and `api/rip/status.php`.

The helper checks whether the stored worker PID is still alive using `posix_kill($pid, 0)` (signal 0 — checks process existence without sending a signal). If the worker process is gone but the state is still `ripping`, the state is automatically reset to `idle`.

If the POSIX extension is unavailable, the fallback is `filemtime` of the state file: if the file has not been updated for more than 60 seconds, the rip is treated as stale and state is reset to `idle`.

---

## Endpoints

---

### Drive

#### `GET /api/drive/status`

Polls the current state of the optical drive. This is the primary polling endpoint during the idle/waiting phase. The caller should poll this at approximately 2-second intervals when no rip is in progress.

Drive state is determined via the Linux `ioctl` command `0x5326` (`CDROM_DRIVE_STATUS`), called via a Perl one-liner:

```perl
sysopen(my $fd, $ARGV[0], 2048) or die("unable to open device");
print ioctl($fd, 0x5326, 1);
close($fd);
```

This returns one of five integer values, which the backend maps to the `drive_status` enum:

| ioctl value | Constant | `drive_status` |
|---|---|---|
| `0` | `CDS_NO_INFO` | `no_info` |
| `1` | `CDS_NO_DISC` | `no_disc` |
| `2` | `CDS_TRAY_OPEN` | `tray_open` |
| `3` | `CDS_DRIVE_NOT_READY` | `not_ready` |
| `4` | `CDS_DISC_OK` | `disc_ok` |

`not_ready` indicates the drive is spinning up. The frontend should display a "please wait" state and continue polling until `disc_ok` is reached.

When `drive_status` is `disc_ok`, the backend runs `cdparanoia -Q` to query the disc and parse track and duration information.

**Request:** None.

**Response:**

```json
{
    "ok": true,
    "data": {
        "drive_status": "disc_ok",
        "track_count": 4,
        "total_duration": "41:26",
        "drive_busy": false
    }
}
```

| Field | Type | Description |
|---|---|---|
| `drive_status` | string | One of `no_info`, `no_disc`, `tray_open`, `not_ready`, `disc_ok`. |
| `track_count` | int | Number of audio tracks detected. `0` unless `drive_status` is `disc_ok`. |
| `total_duration` | string | Total audio duration of the disc in `MM:SS` format, parsed from `cdparanoia -Q` output. Empty string if not available or drive status is not `disc_ok`. |
| `drive_busy` | bool | `true` if a rip is currently in progress. |

---

#### `POST /api/drive/eject`

Issues an eject command to the drive. Called automatically by the system after a successful rip, and also triggered by the cancel flow.

**Request:** None.

**Response:**

```json
{
    "ok": true,
    "data": {
        "ejected": true
    }
}
```

**Error codes:**

| Code | Meaning |
|---|---|
| `DRIVE_BUSY` | A rip is currently in progress. Cancel it before ejecting. |
| `EJECT_FAILED` | OS-level eject command returned an error. |

---

### Catalogue

#### `GET /api/catalogue/lookup`

Looks up a Location ID in the locally cached catalogue CSV. Used to validate the operator's input and retrieve the description for on-screen confirmation.

**Query Parameters:**

| Parameter | Required | Description |
|---|---|---|
| `id` | Yes | The Location ID as entered by the operator. Matching is case-insensitive and strips spaces from both the query and the CSV values before comparing. For example, "ARC1M" matches "ARC 1 M". |

**Response (found):**

```json
{
    "ok": true,
    "data": {
        "found": true,
        "location": "ARC 1 M",
        "subject": "Archaeology",
        "description": "Records from the Portable Antiquity Scheme database submitted by Brian Howcroft, 2012."
    }
}
```

The `location` field returns the canonical form of the Location ID as stored in the CSV (with original spacing preserved). The frontend updates `locationIdInput` to this canonical form after a successful lookup.

**Response (not found):**

```json
{
    "ok": true,
    "data": {
        "found": false
    }
}
```

Note: A not-found result is **not** an error condition. The frontend uses it to trigger the unknown-ID confirmation flow. `ok` remains `true`.

---

#### `POST /api/catalogue/refresh`

Re-fetches the catalogue CSV from the remote Cloudinary URL and overwrites the local cache. Intended for use via a settings or admin panel, not part of the normal rip workflow. Requires internet access.

**Request:** None.

**Response:**

```json
{
    "ok": true,
    "data": {
        "record_count": 847,
        "fetched_at": "2026-03-24T14:00:00Z",
        "source_url": "https://res.cloudinary.com/..."
    }
}
```

**Error codes:**

| Code | Meaning |
|---|---|
| `FETCH_FAILED` | Remote URL could not be reached or returned a non-200 response. |
| `PARSE_FAILED` | Downloaded file could not be parsed as valid CSV. Existing local cache is retained. |

---

### Rip

#### `POST /api/rip/start`

Initiates a rip of the currently loaded disc. Spawns `scripts/rip_worker.php` as a background process. The worker reads a rip info JSON file (written by this endpoint) on startup, then orchestrates the full rip-and-encode loop, writing progress to the shared state file. On completion, files are moved atomically into the output directory. On failure, files are moved to a dated damaged directory.

Before spawning the worker, this endpoint runs `cdparanoia -Q` to query the disc and parse per-track sector counts. These are written into the rip info JSON so the worker can compute audio-accurate progress percentages.

**Request body:**

```json
{
    "location_id": "ARC 1 M",
    "confirmed_unknown": false
}
```

| Field | Type | Description |
|---|---|---|
| `location_id` | string | The Location ID as entered and confirmed by the operator. |
| `confirmed_unknown` | bool | Must be `true` if the Location ID was not found in the catalogue and the operator has confirmed by re-entering it. Defaults to `false`. |

**Behaviour for unknown Location IDs:**

- First call with `confirmed_unknown: false` and an unknown ID → returns error `UNKNOWN_LOCATION_UNCONFIRMED`. Frontend prompts the operator to re-enter the ID to confirm.
- Second call with `confirmed_unknown: true` → proceeds with rip, and `in_catalogue: false` is noted in `meta.json`.

**Response:**

```json
{
    "ok": true,
    "data": {
        "state": "ripping",
        "location_id": "ARC 1 M",
        "directory_name": "arc_1_m_a3f4b2c1",
        "track_count": 4
    }
}
```

**Error codes:**

| Code | Meaning |
|---|---|
| `NO_DISC` | No disc is present or the drive is not ready. |
| `ALREADY_RIPPING` | A rip is already in progress. |
| `ALREADY_COMPLETE` | A rip with `status: ok` already exists for this Location ID. Frontend must show "This disc has already been processed", eject the disc, and return to `WAITING_FOR_DISC`. Re-ripping is not permitted. |
| `UNKNOWN_LOCATION_UNCONFIRMED` | Location ID not found in catalogue and `confirmed_unknown` was not `true`. |
| `MISSING_LOCATION_ID` | No `location_id` provided in request body. |

---

#### `GET /api/rip/status`

Returns the current state of the rip process. This is the primary polling endpoint during an active rip. The caller should poll at approximately 1-second intervals while `state` is `ripping`, and may poll less frequently otherwise.

The backend reads from the shared state file written by the rip worker — this endpoint is intentionally fast and non-blocking.

**Request:** None.

**Response:**

```json
{
    "ok": true,
    "data": {
        "state": "ripping",
        "location_id": "ARC 1 M",
        "progress_pct": 42,
        "track_progress_pct": 67,
        "tracks_done": 1,
        "tracks_total": 4,
        "current_track": 2,
        "current_track_phase": "ripping",
        "bad_sectors": 0,
        "log_tail": "... last few lines of ripping/encoding output ..."
    }
}
```

| Field | Type | Description |
|---|---|---|
| `state` | string | Current rip state (see State Enum above). |
| `location_id` | string or null | Location ID of the current or most recent rip. `null` if system has never ripped anything this session. |
| `progress_pct` | int | Overall percentage complete across all tracks, computed from audio sector counts. `0`–`100`. During encoding of a track, stays at the post-rip level for that track. |
| `track_progress_pct` | int | Progress through the current track being ripped, 0–100. Derived from growing WAV file size vs. predicted size. `0` when not ripping a track. |
| `tracks_done` | int | Number of tracks fully ripped and encoded. |
| `tracks_total` | int | Total number of tracks on the disc. |
| `current_track` | int | Track currently being processed. `0` if idle. |
| `current_track_phase` | string | Either `"ripping"` or `"encoding"`. Indicates which phase the current track is in. Empty string if idle. |
| `bad_sectors` | int | Cumulative bad sector count reported by cdparanoia across all tracks so far. |
| `log_tail` | string | The last few lines of ripping/encoding output, for display in a debug/info panel. |

Tracks are processed sequentially: each track is fully ripped to a temporary WAV file, immediately encoded to MP3, and the WAV deleted before the next track begins. This keeps temporary disk usage to a minimum — never more than one WAV file at a time.

When `state` is `idle`, all numeric fields will be `0`, `current_track_phase` will be an empty string, and `log_tail` will be an empty string.

If this endpoint is called while the frontend is in `RIPPING` or `CANCELLING` and the response returns `state: idle`, this indicates that a stale rip was auto-reset. The frontend should treat this as a signal to transition to `WAITING_FOR_DISC`.

---

#### `POST /api/rip/cancel`

Requests cancellation of the running rip. Rather than killing the worker process directly, this endpoint writes `cancel_requested: true` to the shared state file. The worker polls this flag every ~200ms and handles cancellation gracefully: it terminates the running cdparanoia process, cleans up, and writes `cancelled` to the state. Returns immediately; the frontend should confirm completion by polling `/api/rip/status` until `state` transitions to `cancelled`.

**Request:** None.

**Response:**

```json
{
    "ok": true,
    "data": {
        "state": "cancelling"
    }
}
```

**Error codes:**

| Code | Meaning |
|---|---|
| `NOT_RIPPING` | No rip is currently in progress; nothing to cancel. |

---

#### `POST /api/reset`

Force-resets the system. SIGKILLs the stored worker PID (if any), deletes the temporary work directory, and writes a fresh idle state. Used by the frontend "Force reset" button, which is available in the `RIPPING` and `CANCELLING` UI states.

This is an escape hatch for situations where the worker is genuinely stuck and the graceful cancel mechanism cannot proceed.

**Request:** None.

**Response:**

```json
{
    "ok": true,
    "data": {
        "state": "idle"
    }
}
```

---

### History

#### `GET /api/history`

Returns a list of all rips recorded in the output directory, in reverse chronological order by `rip_completed_at`. Each entry is drawn from the `meta.json` file in that rip's directory. Useful for the "already processed" check and as an operator audit log.

**Query Parameters:**

| Parameter | Required | Default | Description |
|---|---|---|---|
| `limit` | No | `50` | Maximum number of records to return. |
| `offset` | No | `0` | Offset for pagination. |
| `damaged_only` | No | `false` | If `true`, returns only damaged or incomplete rips. |

**Response:**

```json
{
    "ok": true,
    "data": {
        "total": 124,
        "limit": 50,
        "offset": 0,
        "records": [
            {
                "metadata_version": 1,
                "location_id": "ARC 1 M",
                "directory_name": "arc_1_m_a3f4b2c1",
                "subject": "Archaeology",
                "description": "Records from the Portable Antiquity Scheme...",
                "rip_completed_at": "2026-03-24T14:38:05Z",
                "status": "ok",
                "track_count": 4,
                "bad_sector_count": 0,
                "in_catalogue": true
            }
        ]
    }
}
```

---

## Polling Summary

| Scenario | Endpoint | Interval |
|---|---|---|
| Waiting for disc to be inserted | `GET /api/drive/status` | 2 seconds |
| Waiting for new disc after completion | `GET /api/drive/status` | 2 seconds |
| Rip in progress | `GET /api/rip/status` | 1 second |
| Waiting for cancel to complete | `GET /api/rip/status` | 1 second |

---

## Frontend State Machine Overview

The following states drive the UI; each transition is triggered either by operator action or by a polling response.

```
STARTUP
  → [initialised]
WAITING_FOR_DISC                ← polls GET /api/drive/status every 2s
  → [drive_status: disc_ok]
WAITING_FOR_ID                  ← inactivity timer running; bleeping if idle too long
  → [disc removed]              → WAITING_FOR_DISC (reset timer and beep)
  → [ID submitted, found]       → CONFIRM_KNOWN_ID
  → [ID submitted, not found]   → CONFIRM_UNKNOWN_ID
CONFIRM_KNOWN_ID                ← shows description; operator must actively confirm
  → [confirmed]                 → RIPPING
  → [wrong disc]                → eject → WAITING_FOR_DISC
CONFIRM_UNKNOWN_ID              ← warns operator; must re-type ID to confirm
  → [re-entry matches]          → RIPPING (in_catalogue: false)
  → [cancelled]                 → eject → WAITING_FOR_DISC
RIPPING                         ← polls GET /api/rip/status every 1s
                                   shows track N of M, phase (ripping|encoding),
                                   two progress bars (per-track and overall),
                                   bad sector count, log tail
  → [status: ok]                → COMPLETE
  → [status: damaged]           → DAMAGED
  → [cancel pressed]            → CANCELLING
  → [status: idle]              → WAITING_FOR_DISC (stale rip auto-reset)
  → [force reset pressed]       → POST /api/reset → WAITING_FOR_DISC
CANCELLING                      ← polls GET /api/rip/status every 1s; no interaction
  → [status: cancelled]         → CANCELLED
  → [status: idle]              → WAITING_FOR_DISC (stale rip auto-reset)
  → [force reset pressed]       → POST /api/reset → WAITING_FOR_DISC
COMPLETE                        ← disc auto-ejected by backend; polls GET /api/drive/status every 2s
  → [drive_status: disc_ok]     → WAITING_FOR_ID (auto-transition)
  → [Process another]           → WAITING_FOR_DISC
  → [Stop]                      → STOPPED
DAMAGED                         ← partial files preserved in dated damaged directory
  → [Try again]                 → CONFIRM_KNOWN_ID (ID pre-filled, disc not ejected)
  → [Abandon]                   → eject → WAITING_FOR_DISC
  → [Stop]                      → STOPPED
CANCELLED
  → [Try again]                 → CONFIRM_KNOWN_ID (ID pre-filled, disc not ejected)
  → [Stop]                      → STOPPED
STOPPED                         ← shows session summary (processed count, damaged count)
  → [Start new session]         → WAITING_FOR_DISC
```

### Inactivity Beep Behaviour

The beep logic is driven entirely by the frontend using `window.APP_CONFIG` values. No backend involvement.

- On entering `WAITING_FOR_ID`: start inactivity timer
- Any keypress in the ID field: reset timer to zero; stop bleeping if active
- Timer reaches `inactivityBeepHoldoffSeconds` without a keypress: begin bleeping at `beepIntervalSeconds` intervals
- Operator resumes typing: stop bleeping immediately, reset timer
- ID submitted successfully: cancel timer and beep entirely

Beeps are synthesised using the Web Audio API — a short sine wave burst at `beepFrequencyHz` for `beepDurationMs` milliseconds. No audio file required.

### Polling Discipline

Only one polling loop should be active at any time. All intervals must be explicitly cleared on every state transition to prevent ghost polls accumulating.

| State | Active poll | Interval |
|---|---|---|
| `WAITING_FOR_DISC` | `GET /api/drive/status` | 2 seconds |
| `WAITING_FOR_ID` | `GET /api/drive/status` | 2 seconds (disc-removed guard) |
| `COMPLETE` | `GET /api/drive/status` | 2 seconds (new disc guard) |
| `RIPPING` | `GET /api/rip/status` | 1 second |
| `CANCELLING` | `GET /api/rip/status` | 1 second |
| All other states | None | — |

### Session Counter

An in-memory count of discs processed and damaged this session is maintained by the frontend. Incremented on entering `COMPLETE` or `DAMAGED` respectively. Displayed unobtrusively throughout the session and summarised on the `STOPPED` screen.

---

## Configuration Reference

All paths in the config file may be absolute or relative. If relative, they are resolved against `base_dir`.

```ini
[general]
base_dir = /opt/cd-ingest
debug = false

[device]
drive = /dev/sr0
cdstat_script = scripts/cdstat.pl

[paths]
output_dir = data/output
temp_dir = data/temp
catalogue_csv = data/catalogue.csv
log_dir = data/logs
state_file = data/rip_state.json

[server]
port = 8080
doc_root = public

[ui]
inactivity_beep_holdoff_seconds = 5
beep_interval_seconds = 2
beep_duration_ms = 200
beep_frequency_hz = 880

[encoding]
format = mp3
lame_options = --preset voice

[ripping]
cdparanoia_options = "--never-skip=3"

[catalogue]
source_url = https://res.cloudinary.com/hhdifljso/raw/upload/v1697296410/HBLHS_Web_catalogue_20_9_23_LO_6351647b68.csv
auto_refresh_on_start = true
```

`debug`: When `true`, all API requests/responses and worker activity are logged to `data/logs/debug.log`. A debug panel is shown at the bottom of the UI (fixed footer) displaying current state, drive status, rip progress, and the last debug message.

`cdparanoia_options`: Options string passed verbatim to every cdparanoia rip invocation. The value must be quoted in the INI file because it contains `=`. Currently `"--never-skip=3"`, which causes cdparanoia to fill bad sectors with zeros and move on after 3 failed retries without progress, rather than retrying indefinitely.

---

## Application Bootstrap

### `GET /`

The application entrypoint is **not a static file**. It is served by a PHP script (`index.php`) which renders the HTML shell and injects a UI configuration block at page load time.

This approach avoids a separate HTTP round trip for config, eliminates any race condition between UI initialisation and config availability, and ensures Alpine.js has all required values before any component is mounted.

The injected block appears in the `<head>` of the document before any application scripts:

```html
<script>
    window.APP_CONFIG = <?php echo json_encode($uiConfig); ?>;
</script>
```

Only the UI-relevant subset of the INI config is exposed. Backend-only values (device path, temp directory, catalogue URL, Perl script path, etc.) are never included. The injected object contains:

```json
{
    "inactivityBeepHoldoffSeconds": 5,
    "beepIntervalSeconds": 2,
    "beepDurationMs": 200,
    "beepFrequencyHz": 880,
    "debug": false
}
```

The `[encoding]` and `[ripping]` sections are not exposed to the frontend — they are backend-only configuration. The `debug` boolean is exposed so the frontend can show or hide the debug panel accordingly.

INI snake_case keys are translated to camelCase at the PHP layer so the JavaScript side is idiomatic.

Alpine.js components access configuration via `window.APP_CONFIG` during initialisation, e.g.:

```javascript
this.inactivityBeepHoldoffSeconds = window.APP_CONFIG.inactivityBeepHoldoffSeconds;
```

### PHP Router Behaviour

The PHP built-in server is started with a router script. Request handling is as follows:

| Request path | Behaviour |
|---|---|
| `/api/*` | Dispatched to the appropriate API handler. |
| Matches a real file under `doc_root` | File served directly (CSS, JS, fonts, etc.). |
| Anything else | `index.php` is served, which renders the app shell with injected config. |

This means `index.php` is the only PHP file that produces HTML. All other PHP files are API handlers that produce JSON exclusively.

---

*End of API Specification v1.0*
