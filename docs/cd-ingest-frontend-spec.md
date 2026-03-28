# CD Ingest System — Frontend Specification

**Version:** 1.0 (implemented)
**Companion document:** CD Ingest System — API Specification v1.0
**Target environment:** Chrome in kiosk mode (`--app`, `--kiosk` flags), localhost, single operator, no internet access required at runtime.

---

## Overview

This document specifies the frontend for the CD Ingest System — a browser-based operator console used to rip and encode audio CDs from a local history archive into MP3 files. The UI runs in a dedicated Chrome kiosk window and is the sole interface the operator interacts with. It must be clear, robust, and hard to accidentally misuse.

The frontend is a **single-page application** built with **Alpine.js** and plain HTML/CSS. There is no build process — all files are served directly by the PHP backend. The application is driven by a single state machine; at any given moment exactly one state is active and the UI renders accordingly.

---

## Technology Stack

| Concern | Technology | Notes |
|---|---|---|
| UI framework | Alpine.js (CDN) | No build process. Load from local vendor file, not CDN, as internet access is not guaranteed at runtime. |
| Styling | Plain CSS | Single stylesheet. No framework. |
| Audio | Web Audio API | Built-in to Chrome. Used for synthesising beep tones. No audio files required. |
| HTTP | `fetch()` | All API calls use the Fetch API with JSON. |
| State | Alpine.js component data | All application state lives in a single top-level Alpine component. |
| Config | `window.APP_CONFIG` | Injected by PHP at page load. See API spec — Application Bootstrap section. |

**Alpine.js must be vendored locally.** Download the minified production build and place it at `public/vendor/alpinejs.min.js`. Do not load from a CDN.

---

## File Structure

```
public/
├── index.php          # PHP-rendered entrypoint; injects APP_CONFIG
├── app.js             # Single Alpine.js component (all state and logic)
├── app.css            # Single stylesheet
└── vendor/
    └── alpinejs.min.js
```

`index.php` outputs the full HTML shell, injects `window.APP_CONFIG` into a `<script>` tag in `<head>`, then loads `vendor/alpinejs.min.js` and `app.js` in that order at the end of `<body>`.

`app.js` exports a single function — `appData()` — registered as an Alpine component on the root element. All state, computed properties, and methods live here. There are no sub-components.

---

## Configuration

All runtime configuration is available in `window.APP_CONFIG` before any scripts execute. The component reads this once during `init()` and stores values locally.

```javascript
// In Alpine init():
this.cfg = window.APP_CONFIG;
// Access as: this.cfg.beepFrequencyHz etc.
```

| Key | Type | Description |
|---|---|---|
| `inactivityBeepHoldoffSeconds` | int | Seconds of inactivity in the ID entry field before bleeping begins. |
| `beepIntervalSeconds` | int | Interval between repeated beeps once bleeping has started. |
| `beepDurationMs` | int | Duration of each beep in milliseconds. |
| `beepFrequencyHz` | int | Frequency of beep tone in Hz. |
| `debug` | bool | When `true`, show the debug panel at the bottom of the UI. |

---

## Application State

The application is always in exactly one of the following states. The state is stored as a string in `this.state`.

| State | Description |
|---|---|
| `STARTUP` | Initial state on page load. Briefly active during initialisation. |
| `WAITING_FOR_DISC` | No disc present. System is polling the drive. |
| `WAITING_FOR_ID` | Disc detected. Operator must enter Location ID. Inactivity timer running. |
| `CONFIRM_KNOWN_ID` | Location ID found in catalogue. Operator must confirm description. |
| `CONFIRM_UNKNOWN_ID` | Location ID not found in catalogue. Operator must re-enter ID to confirm. |
| `RIPPING` | Rip and encode in progress. Progress being polled. |
| `CANCELLING` | Cancel requested. Waiting for backend to confirm. |
| `COMPLETE` | Rip completed successfully. |
| `DAMAGED` | Rip finished with disc read errors. Partial files preserved. |
| `CANCELLED` | Rip was cancelled by the operator. |
| `STOPPED` | Session ended deliberately. Summary shown. |

### State Data

The following data properties are maintained alongside `state`:

```javascript
{
    // Config (read once from window.APP_CONFIG)
    cfg: {},

    // Drive
    driveStatus: 'no_disc',     // Last known drive_status from API
    discTrackCount: 0,          // Track count from drive status, shown on WAITING_FOR_ID
    discDuration: '',           // Total duration string from drive status, shown on WAITING_FOR_ID

    // ID entry
    locationIdInput: '',         // Value of the ID input field (updated to canonical form after lookup)
    locationIdConfirm: '',       // Re-entry field value (CONFIRM_UNKNOWN_ID only)
    catalogueEntry: null,        // { location, subject, description } or null

    // Rip progress
    ripState: 'idle',            // state field from GET /api/rip/status
    ripLocationId: null,
    progressPct: 0,              // Overall disc progress (audio-duration-accurate)
    trackProgressPct: 0,         // Per-track progress (0–100)
    tracksDone: 0,
    tracksTotal: 0,
    currentTrack: 0,
    currentTrackPhase: '',       // 'ripping' | 'encoding'
    badSectors: 0,
    logTail: '',

    // Session
    sessionProcessed: 0,
    sessionDamaged: 0,

    // Inactivity beep
    _inactivityTimer: null,
    _beepInterval: null,
    _audioCtx: null,

    // Polling handles
    _drivePollInterval: null,
    _ripPollInterval: null,
}
```

---

## State Transitions

### Transition Map

```
STARTUP
  → init() complete                          → WAITING_FOR_DISC

WAITING_FOR_DISC
  → drive_status == 'disc_ok'               → WAITING_FOR_ID

WAITING_FOR_ID
  → drive_status != 'disc_ok'               → WAITING_FOR_DISC
  → ID submitted → lookup found             → CONFIRM_KNOWN_ID
  → ID submitted → lookup not found         → CONFIRM_UNKNOWN_ID
  → "Cancel — eject disc" clicked           → eject() → WAITING_FOR_DISC

CONFIRM_KNOWN_ID
  → operator confirms                        → RIPPING
  → operator clicks "Wrong disc"            → eject() → WAITING_FOR_DISC

CONFIRM_UNKNOWN_ID
  → both inputs match, operator confirms     → RIPPING (in_catalogue: false)
  → operator clicks "Cancel"                → eject() → WAITING_FOR_DISC

RIPPING
  → ripState == 'ok'                        → sessionProcessed++ → COMPLETE
  → ripState == 'damaged'                   → sessionDamaged++ → DAMAGED
  → operator clicks "Cancel"               → POST /api/rip/cancel → CANCELLING
  → ripState == 'idle'                      → WAITING_FOR_DISC (stale rip auto-reset)
  → operator clicks "Force reset"          → POST /api/reset → WAITING_FOR_DISC

CANCELLING
  → ripState == 'cancelled'                 → CANCELLED
  → ripState == 'idle'                      → WAITING_FOR_DISC (stale rip auto-reset)
  → operator clicks "Force reset"          → POST /api/reset → WAITING_FOR_DISC

COMPLETE
  → drive_status == 'disc_ok' (new disc)    → WAITING_FOR_ID
  → operator clicks "Process another"      → WAITING_FOR_DISC
  → operator clicks "Stop"                 → STOPPED

DAMAGED
  → operator clicks "Try again"            → CONFIRM_KNOWN_ID (ID pre-filled)
  → operator clicks "Abandon and continue" → eject() → WAITING_FOR_DISC
  → operator clicks "Stop"                 → STOPPED

CANCELLED
  → operator clicks "Try again"            → CONFIRM_KNOWN_ID (ID pre-filled)
  → operator clicks "Continue"             → WAITING_FOR_DISC
  → operator clicks "Stop"                 → STOPPED

STOPPED
  → operator clicks "Start new session"    → WAITING_FOR_DISC
```

### Transition Rules

- Every state transition must call `this._clearAllPolling()` before setting the new state and starting any new polling.
- State is always set by calling `this._enterState(newState)`, never by writing `this.state` directly. `_enterState()` handles polling setup and teardown.
- No transition should be triggered while a previous transition's async operations are still in flight. Use a `_busy` flag if necessary to prevent double-firing.

---

## Polling

Two polling loops exist. Only one should be active at any time. Both are managed by `_enterState()`.

### Drive Polling

**Active in:** `WAITING_FOR_DISC`, `WAITING_FOR_ID`, `COMPLETE`
**Endpoint:** `GET /api/drive/status`
**Interval:** 2 seconds

```javascript
_startDrivePoll() {
    this._drivePollInterval = setInterval(async () => {
        const data = await this._api('/api/drive/status');
        if (!data) return;
        this.driveStatus = data.drive_status;
        this.discTrackCount = data.track_count || 0;
        this.discDuration = data.total_duration || '';
        this._handleDriveStatusChange();
    }, 2000);
}
```

`_handleDriveStatusChange()` applies the following logic based on current state:

| Current state | `drive_status` | Action |
|---|---|---|
| `WAITING_FOR_DISC` | `disc_ok` | → `WAITING_FOR_ID` |
| `WAITING_FOR_DISC` | anything else | Update `driveStatus`, show appropriate sub-message |
| `WAITING_FOR_ID` | not `disc_ok` | Reset ID input, stop beep/timer → `WAITING_FOR_DISC` |
| `COMPLETE` | `disc_ok` | → `WAITING_FOR_ID` |

### Rip Polling

**Active in:** `RIPPING`, `CANCELLING`
**Endpoint:** `GET /api/rip/status`
**Interval:** 1 second

```javascript
_startRipPoll() {
    this._ripPollInterval = setInterval(async () => {
        const data = await this._api('/api/rip/status');
        if (!data) return;
        this.ripState = data.state;
        this.progressPct = data.progress_pct;
        this.trackProgressPct = data.track_progress_pct;
        this.tracksDone = data.tracks_done;
        this.tracksTotal = data.tracks_total;
        this.currentTrack = data.current_track;
        this.currentTrackPhase = data.current_track_phase;
        this.badSectors = data.bad_sectors;
        this.logTail = data.log_tail;
        this._handleRipStatusChange();
    }, 1000);
}
```

`_handleRipStatusChange()` applies:

| Current state | `ripState` | Action |
|---|---|---|
| `RIPPING` | `ok` | `sessionProcessed++` → `COMPLETE` |
| `RIPPING` | `damaged` | `sessionDamaged++` → `DAMAGED` |
| `RIPPING` | `idle` | → `WAITING_FOR_DISC` (stale rip auto-reset) |
| `CANCELLING` | `cancelled` | → `CANCELLED` |
| `CANCELLING` | `idle` | → `WAITING_FOR_DISC` (stale rip auto-reset) |

### Clearing Polling

```javascript
_clearAllPolling() {
    clearInterval(this._drivePollInterval);
    clearInterval(this._ripPollInterval);
    this._drivePollInterval = null;
    this._ripPollInterval = null;
}
```

---

## Inactivity Beep System

The beep system is entirely frontend-managed. It operates only while in `WAITING_FOR_ID`.

### Behaviour

1. On entering `WAITING_FOR_ID`: call `_resetInactivityTimer()`
2. On any keypress in the Location ID input field: call `_resetInactivityTimer()`
3. If `inactivityBeepHoldoffSeconds` elapses without a keypress: call `_startBeeping()`
4. On any keypress while beeping: call `_stopBeeping()`, then `_resetInactivityTimer()`
5. On leaving `WAITING_FOR_ID` for any reason: call `_stopBeeping()` and `_clearInactivityTimer()`

### Implementation

```javascript
_resetInactivityTimer() {
    this._stopBeeping();
    clearTimeout(this._inactivityTimer);
    this._inactivityTimer = setTimeout(() => {
        this._startBeeping();
    }, this.cfg.inactivityBeepHoldoffSeconds * 1000);
},

_startBeeping() {
    this._emitBeep();
    this._beepInterval = setInterval(() => {
        this._emitBeep();
    }, this.cfg.beepIntervalSeconds * 1000);
},

_stopBeeping() {
    clearInterval(this._beepInterval);
    this._beepInterval = null;
},

_clearInactivityTimer() {
    clearTimeout(this._inactivityTimer);
    this._inactivityTimer = null;
},

_emitBeep() {
    if (!this._audioCtx) {
        this._audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    const oscillator = this._audioCtx.createOscillator();
    const gainNode = this._audioCtx.createGain();
    oscillator.connect(gainNode);
    gainNode.connect(this._audioCtx.destination);
    oscillator.type = 'sine';
    oscillator.frequency.value = this.cfg.beepFrequencyHz;
    gainNode.gain.setValueAtTime(0.3, this._audioCtx.currentTime);
    gainNode.gain.exponentialRampToValueAtTime(
        0.001,
        this._audioCtx.currentTime + (this.cfg.beepDurationMs / 1000)
    );
    oscillator.start(this._audioCtx.currentTime);
    oscillator.stop(this._audioCtx.currentTime + (this.cfg.beepDurationMs / 1000));
},
```

**Note on AudioContext:** Chrome requires a user gesture before creating an AudioContext. The operator clicking or typing to start the session satisfies this. The AudioContext is created lazily on first beep and reused thereafter. If `_emitBeep()` is called before any user gesture has occurred it will silently fail — this is acceptable as the beep's purpose is to attract attention when the operator has walked away, which implies they interacted earlier.

---

## API Helper

All API calls go through a single helper method to centralise error handling:

```javascript
async _api(path, options = {}) {
    try {
        const res = await fetch(path, {
            headers: { 'Content-Type': 'application/json' },
            ...options
        });
        const json = await res.json();
        if (!json.ok) {
            this._handleApiError(json.error);
            return null;
        }
        return json.data;
    } catch (err) {
        this._handleApiError({ code: 'NETWORK_ERROR', message: err.message });
        return null;
    }
},

_handleApiError(error) {
    // Log to console for debugging; surface to user only for actionable errors
    console.error('API error:', error.code, error.message);
    // Specific codes that warrant UI feedback are handled by callers
},
```

Callers check for `null` return and act accordingly. Most errors during polling are transient and should be silently retried on the next interval. Errors during explicit user actions (submitting an ID, starting a rip) should surface a message to the operator.

---

## Screen Specifications

Each state has a corresponding screen. All screens share a common layout shell.

### Common Layout

```
┌─────────────────────────────────────────┐
│  HBLHS CD Archive Ingest                │  ← fixed header, always visible
│  [Session: 4 processed, 1 damaged]      │  ← session counter, top-right
├─────────────────────────────────────────┤
│                                         │
│         [ state-specific content ]      │
│                                         │
├─────────────────────────────────────────┤
│  [ debug panel — fixed footer ]         │  ← visible only when debug: true
└─────────────────────────────────────────┘
```

The session counter is displayed as unobtrusive small text in the header. It is always visible across all states so the operator has a running tally. Shows "0 processed" until the first disc completes.

When `cfg.debug` is `true`, a fixed footer panel is shown at the bottom of every screen. It displays current state, drive status, rip progress, and the last debug message. It is not shown when `debug` is `false`.

---

### STARTUP

Displayed only briefly during `init()`. Shows a simple "Starting up…" message. No interaction required. Transitions automatically to `WAITING_FOR_DISC`.

---

### WAITING_FOR_DISC

**Purpose:** Idle/home state. Waits for a disc to be inserted.

**Content:**
- Large, clear status message based on `driveStatus`:

| `driveStatus` | Message shown |
|---|---|
| `tray_open` | "Please insert a disc and close the tray" |
| `no_disc` | "Please insert a disc" |
| `not_ready` | "Reading disc, please wait…" |
| `no_info` | "Drive status unknown — please wait" |

- A **"Stop session"** button in a non-prominent position (e.g. bottom of screen). Transitions to `STOPPED`.

**Behaviour:** Drive poll active. No other interaction while waiting.

---

### WAITING_FOR_ID

**Purpose:** Disc is present. Prompt operator to identify it.

**Content:**
- Heading: "Disc detected — please enter the Location ID"
- A disc info badge (green, inline) showing track count and total audio duration once disc details are available, e.g. "20 tracks · 41:26". Populated from `discTrackCount` and `discDuration` (set by the drive poll).
- Large text input field, autofocused, labelled "Location ID"
- Two buttons:
  - **"Look up"** (primary) — submits the entered ID
  - **"Cancel — eject disc"** (secondary) — ejects the disc and returns to `WAITING_FOR_DISC`
- Submit also triggered by pressing Enter
- Small helper text: "Enter the Location ID printed on the disc case"

**Inactivity beep:** Active. Timer starts when state is entered, resets on every keypress, bleeps if operator goes idle.

**Behaviour:**
- On submit: call `GET /api/catalogue/lookup?id={value}`
  - If `found: true` → populate `catalogueEntry`, update `locationIdInput` to the canonical `location` value returned → `CONFIRM_KNOWN_ID`
  - If `found: false` → `CONFIRM_UNKNOWN_ID`
  - If API error → show inline error message, remain in state, do not clear input
- Drive poll remains active as disc-removal guard. If disc removed mid-entry: clear input, stop beep, → `WAITING_FOR_DISC`
- Input should be trimmed of leading/trailing whitespace before submission but otherwise passed through as-is to the API.

---

### CONFIRM_KNOWN_ID

**Purpose:** Show catalogue entry for the operator to verify before committing to a rip.

**Content:**
- Heading: "Please confirm this is the correct disc"
- Display in a clear, prominent block:
  - **Location:** `{catalogueEntry.location}`
  - **Subject:** `{catalogueEntry.subject}`
  - **Description:** `{catalogueEntry.description}`
- Two buttons:
  - **"Yes — start ripping"** (primary, prominent)
  - **"Wrong disc — go back"** (secondary)

**Behaviour:**
- "Yes": call `POST /api/rip/start` with `{ location_id, confirmed_unknown: false }`.
  - On success → `RIPPING`
  - On `ALREADY_COMPLETE`: show message "This disc has already been processed." Call `POST /api/drive/eject`, then transition to `WAITING_FOR_DISC`.
  - On other errors: show message, remain in state.
- "Wrong disc": call `POST /api/drive/eject` → `WAITING_FOR_DISC`

---

### CONFIRM_UNKNOWN_ID

**Purpose:** Location ID was not recognised. Force a deliberate confirmation before proceeding.

**Content:**
- Heading: **"Warning — Location ID not found"** (visually prominent, e.g. amber/warning colour)
- Message: "The ID '{locationIdInput}' was not found in the catalogue. If you are sure this is correct, please type the ID again below to confirm."
- Second input field: "Re-enter Location ID to confirm"
- Two buttons:
  - **"Confirm and start ripping"** — disabled until both inputs match exactly (case-sensitive)
  - **"Cancel — go back"**

**Behaviour:**
- "Confirm": call `POST /api/rip/start` with `{ location_id, confirmed_unknown: true }` → `RIPPING`
- "Cancel": call `POST /api/drive/eject` → `WAITING_FOR_DISC`
- The confirm button should become enabled reactively as the operator types, with no need to press a separate "check" button.
- Matching is case-sensitive and exact — both fields must be byte-for-byte identical.

---

### RIPPING

**Purpose:** Show rip and encode progress. Allow cancellation.

**Content:**
- Heading: "Processing disc — {catalogueEntry.location}"
- Description shown small below heading for reference
- **Two progress bars:**
  1. **Track bar** (top): filled to `trackProgressPct`% — progress through the current track being ripped. Labelled with the percentage on the right.
  2. **Disc bar** (below): filled to `progressPct`% — overall disc progress by audio duration. Labelled with the percentage on the right. A label above this bar reads "Disc — N of M tracks done" (using `tracksDone` and `tracksTotal`).
- Track status line: "Track {currentTrack} of {tracksTotal} — {currentTrackPhase}"
  - `currentTrackPhase` displayed as "Ripping" or "Encoding" (capitalised, human-friendly)
- Bad sector indicator: hidden if `badSectors == 0`; shown as an amber warning if non-zero: "⚠ {badSectors} bad sector(s) detected"
- Collapsible log panel: "Show detail" toggle; contains `logTail` in a monospace `<pre>` block. Collapsed by default.
- Two buttons:
  - **"Cancel"** (secondary/destructive styling). Requires a single click — no double-confirm, as the cancel flow itself is reversible (they can try again).
  - **"Force reset"** (ghost style). Calls `POST /api/reset` and transitions to `WAITING_FOR_DISC`. For use when the process is genuinely stuck.

**Behaviour:**
- Rip poll active. Progress fields updated on every poll.
- On `ripState == 'ok'` → `sessionProcessed++` → `COMPLETE`
- On `ripState == 'damaged'` → `sessionDamaged++` → `DAMAGED`
- On `ripState == 'idle'` → `WAITING_FOR_DISC` (stale rip auto-reset)
- On cancel: call `POST /api/rip/cancel` → `CANCELLING`
- On force reset: call `POST /api/reset` → `WAITING_FOR_DISC`

---

### CANCELLING

**Purpose:** Brief interstitial while the worker handles the cancel request.

**Content:**
- Message: "Cancelling — please wait…"
- **"Force reset"** button (ghost style). Available for situations where the worker does not respond to the cancel flag. Calls `POST /api/reset` and transitions to `WAITING_FOR_DISC`.

**Behaviour:**
- Rip poll remains active. Transitions to `CANCELLED` when `ripState == 'cancelled'`.
- If poll returns `ripState == 'idle'` → `WAITING_FOR_DISC` (stale rip auto-reset).
- If poll returns any other terminal state (`ok`, `damaged`) handle those transitions normally — the cancel may have arrived after the rip completed.

---

### COMPLETE

**Purpose:** Confirm successful processing. Prompt for next action.

**Content:**
- Large, prominent success message: **"DISC HAS BEEN PROCESSED"**
- Summary:
  - Location ID
  - Track count
  - Any bad sectors (if non-zero, note them even on success)
- Three buttons:
  - **"Process another disc"** — transitions to `WAITING_FOR_DISC`
  - **"Stop session"** — transitions to `STOPPED`
- Small note: "Or simply insert the next disc to continue automatically"

**Behaviour:**
- Drive poll active. If `drive_status == 'disc_ok'` detected (new disc inserted without pressing a button) → `WAITING_FOR_ID`.
- Backend will have already ejected the completed disc; the tray should be open. The drive poll will show `tray_open` until the operator inserts the next disc and closes it.

---

### DAMAGED

**Purpose:** Inform operator that the rip encountered disc read errors.

**Content:**
- Large, prominent warning: **"DISC COULD NOT BE FULLY READ"** (visually distinct — red or strong warning styling)
- Summary:
  - Location ID
  - Bad sector count
  - Message: "A partial recording has been saved and marked for inspection."
- Collapsible log panel (same as RIPPING state), expanded by default on this screen.
- Three buttons:
  - **"Try again"** — does not eject; returns to `CONFIRM_KNOWN_ID` with Location ID pre-filled
  - **"Abandon and continue"** — ejects disc → `WAITING_FOR_DISC`
  - **"Stop session"** → `STOPPED`

**Behaviour:**
- No polling active.
- "Try again" pre-fills `locationIdInput` with the damaged rip's Location ID, and populates `catalogueEntry` from the previous lookup (no need for another API call). Transitions to `CONFIRM_KNOWN_ID`.

---

### CANCELLED

**Purpose:** Confirm cancellation. Allow retry or continuation.

**Content:**
- Large message: **"DISC HAS NOT BEEN PROCESSED"**
- Sub-message: "The rip was cancelled. No data has been saved for this disc."
- Three buttons:
  - **"Try again"** — does not eject; returns to `CONFIRM_KNOWN_ID` with Location ID pre-filled
  - **"Continue"** — ejects disc → `WAITING_FOR_DISC`
  - **"Stop session"** → `STOPPED`

**Behaviour:** Identical retry logic to `DAMAGED`.

---

### STOPPED

**Purpose:** End of session. Show summary.

**Content:**
- Heading: "Session complete"
- Summary:
  - "Discs processed: {sessionProcessed}"
  - "Discs with errors: {sessionDamaged}" (omit line if zero)
- If `sessionDamaged > 0`: note "Discs with errors have been saved to the output folder for inspection."
- One button: **"Start new session"** → `WAITING_FOR_DISC` (resets `sessionProcessed` and `sessionDamaged` to 0)

---

## Visual Design

The application is a functional operator tool, not a consumer product. Design accordingly: clarity and legibility over aesthetics.

### Principles

- Large, readable text throughout. Operators may be standing or at arm's length from the screen.
- Status messages must be unambiguous. Avoid jargon ("ripping", "encoding" are acceptable as they appear in operator training; "cdparanoia", "LAME" should not appear outside the log panel).
- Destructive or warning states (`DAMAGED`, `CONFIRM_UNKNOWN_ID`) must be visually distinct from normal states — use colour and iconography, not just text.
- Success states (`COMPLETE`) should feel clearly positive.
- The cancel button in `RIPPING` should be styled to be clearly available but not accidentally tapped — secondary styling, not hidden.

### Colour Palette (suggested)

| Purpose | Suggestion |
|---|---|
| Background | Near-white or light grey |
| Primary action | Dark blue or dark green |
| Secondary action | Mid-grey |
| Ghost / force action | Outlined, low-emphasis |
| Warning / unknown ID | Amber |
| Error / damaged | Red |
| Success | Green |
| Disc info badge | Green (inline pill) |

The exact palette is at the implementer's discretion as long as the above semantic distinctions are maintained.

### Typography

- Use a system font stack for simplicity and reliability in a kiosk context.
- Status messages: minimum 24px.
- Primary action buttons: minimum 18px, generous padding.
- Log panel / technical detail: monospace, smaller (14px is fine).

### Layout

- Single centred column, max-width ~700px, vertically centred in the viewport.
- No navigation, no menus, no links. The operator should never need to think about where to click next.
- The session counter in the header is the only persistent UI element across all states (aside from the debug footer when enabled).

---

## Error Handling

### API Errors

| Scenario | Behaviour |
|---|---|
| Network error during poll | Log to console; retry on next interval. Do not change state. |
| Network error during user action | Show inline error message near the triggering element. Do not change state. |
| `ALREADY_COMPLETE` on rip start | Show message "This disc has already been processed." Eject the disc and transition to `WAITING_FOR_DISC`. |
| `UNKNOWN_LOCATION_UNCONFIRMED` | Should not occur if frontend logic is correct; log as unexpected. |
| Any unexpected 5xx | Show message: "An unexpected error occurred. Please note the details and contact support." Include error code. |

### Drive Errors

| Scenario | Behaviour |
|---|---|
| `EJECT_FAILED` | Show non-blocking warning: "The disc tray could not be ejected automatically — please eject manually." Transition to target state regardless. |
| `no_info` drive status | Show "Drive status unknown — please wait" and continue polling. |

---

## Initialisation Sequence

`init()` is called automatically by Alpine.js on component mount. It must:

1. Read `window.APP_CONFIG` into `this.cfg`.
2. Validate that all required config keys are present; log a warning for any that are missing and fall back to hardcoded defaults.
3. Transition to `WAITING_FOR_DISC`.

No API calls are made during `init()`. The first API call is the drive poll that begins in `WAITING_FOR_DISC`.

---

## Kiosk Considerations

The system is delivered in Chrome launched with `--kiosk --app=http://localhost:{port}`. This has the following implications:

- No address bar, no browser controls, no ability to navigate away.
- The operator cannot refresh the page. A hard crash of the Alpine component therefore leaves the UI in a broken state. All state transitions must be wrapped in try/catch at the `_enterState` level to prevent silent failures.
- The screen will not sleep during a rip — prevent this by calling the Wake Lock API where supported: `navigator.wakeLock.request('screen')` on entering `RIPPING`, released on leaving. Wrap in try/catch as it may not be available.
- `window.APP_CONFIG` is always present as it is injected server-side. If it is somehow absent (e.g. PHP error during page render), `init()` must detect this and display a clear error rather than silently misbehaving.

---

## Accessibility

The application is used by a single trained operator on a dedicated machine. Full WCAG compliance is not required. However:

- All interactive elements must be keyboard-accessible (Tab / Enter / Space).
- The Location ID input must be autofocused when `WAITING_FOR_ID` is entered.
- Status messages that change dynamically should use `aria-live="polite"` so screen reader users (if relevant) receive updates without focus moving.
- The session counter in the header should be `aria-live="off"` — it updates frequently and should not interrupt.

---

## Not In Scope

The following are explicitly out of scope for this frontend:

- History / audit log browsing (no screen for `GET /api/history` — this endpoint exists for backend use and future tooling).
- Catalogue refresh UI (available via `POST /api/catalogue/refresh` but not exposed in the operator console — this is an admin operation).
- Settings or configuration editing.
- Multi-user or multi-session support.
- Responsive / mobile layout.

---

*End of Frontend Specification v1.0*
