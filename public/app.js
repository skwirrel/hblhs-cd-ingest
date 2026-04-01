/* ============================================================
   CD Ingest System — Alpine.js application
   Single component implementing the full operator state machine.
   ============================================================ */

function appData() {
    return {

        // ── Config (read once from window.APP_CONFIG) ─────────
        cfg: {},

        // ── Application state ─────────────────────────────────
        state: 'STARTUP',

        // ── Drive ─────────────────────────────────────────────
        driveStatus:    'no_disc',
        discTrackCount: 0,
        discDuration:   '',

        // ── ID entry ──────────────────────────────────────────
        locationIdInput:  '',
        locationIdConfirm: '',
        catalogueEntry:   null,   // { location, subject, description }
        idLookupError:    '',

        // ── Rip progress ──────────────────────────────────────
        ripState:          'idle',
        ripLocationId:     null,
        progressPct:       0,
        trackProgressPct:  0,   // 0-100 within the current track's rip phase
        tracksDone:        0,
        tracksTotal:       0,
        currentTrack:      0,
        currentTrackPhase: '',  // 'ripping' | 'encoding'
        badSectors:        0,
        logTail:           '',
        ripStartError:     '',

        // ── Session counters ──────────────────────────────────
        sessionProcessed: 0,
        sessionDamaged:   0,

        // ── Inactivity beep internals ─────────────────────────
        _inactivityTimer: null,
        _beepInterval:    null,
        _audioCtx:        null,

        // ── Polling handles ───────────────────────────────────
        _drivePollInterval: null,
        _ripPollInterval:   null,

        // ── Wake Lock ─────────────────────────────────────────
        _wakeLock: null,

        // ── Guard against double-firing transitions ───────────
        _busy: false,

        // ── Debug ─────────────────────────────────────────────
        _lastDebugMsg: '',

        // ===========================================================
        // Initialisation
        // ===========================================================

        init() {
            // Guard: APP_CONFIG must exist (injected server-side)
            if (!window.APP_CONFIG) {
                document.body.innerHTML =
                    '<div style="padding:3rem;font-family:sans-serif;color:#991b1b">' +
                    '<h1>Configuration Error</h1>' +
                    '<p>window.APP_CONFIG is missing — PHP may have failed to render the page. ' +
                    'Check the server log and restart.</p></div>';
                return;
            }

            this.cfg = window.APP_CONFIG;
            this._debug('init', { debug: this.cfg.debug });

            // Validate and backfill defaults for required keys
            const defaults = {
                inactivityBeepHoldoffSeconds: 5,
                beepIntervalSeconds: 2,
                beepDurationMs: 200,
                beepFrequencyHz: 880,
            };
            for (const [key, val] of Object.entries(defaults)) {
                if (this.cfg[key] === undefined) {
                    console.warn('APP_CONFIG missing key:', key, '— using default:', val);
                    this.cfg[key] = val;
                }
            }

            this._enterState('WAITING_FOR_DISC');
        },

        // ===========================================================
        // State machine
        // ===========================================================

        _enterState(newState) {
            this._debug('→ state', { from: this.state, to: newState });
            try {
                // ── Teardown current state ────────────────────
                this._clearAllPolling();
                this._stopBeeping();
                this._clearInactivityTimer();
                this._releaseWakeLock();

                this.state = newState;

                // ── Setup new state ───────────────────────────
                switch (newState) {

                    case 'WAITING_FOR_DISC':
                        this.locationIdInput   = '';
                        this.locationIdConfirm = '';
                        this.idLookupError     = '';
                        this.ripStartError     = '';
                        this._busy             = false;
                        this._startDrivePoll();
                        break;

                    case 'WAITING_FOR_ID':
                        this.locationIdInput   = '';
                        this.locationIdConfirm = '';
                        this.idLookupError     = '';
                        this.ripStartError     = '';
                        this._busy             = false;
                        this._startDrivePoll();
                        this._resetInactivityTimer();
                        this.$nextTick(() => {
                            const el = document.getElementById('location-id-input');
                            if (el) el.focus();
                        });
                        break;

                    case 'CONFIRM_KNOWN_ID':
                        this.ripStartError = '';
                        this._busy         = false;
                        break;

                    case 'CONFIRM_UNKNOWN_ID':
                        this.locationIdConfirm = '';
                        this.ripStartError     = '';
                        this._busy             = false;
                        this.$nextTick(() => {
                            const el = document.getElementById('location-id-confirm');
                            if (el) el.focus();
                        });
                        break;

                    case 'RIPPING':
                        this._busy = false;
                        this._startRipPoll();
                        this._acquireWakeLock();
                        break;

                    case 'CANCELLING':
                        this._busy = false;
                        this._startRipPoll();
                        break;

                    case 'COMPLETE':
                        // Delay drive poll start — the worker ejects the disc
                        // after setting state to 'complete', so an immediate poll
                        // can see disc_ok and bounce straight to WAITING_FOR_ID
                        // before the operator sees the COMPLETE screen.
                        setTimeout(() => {
                            if (this.state === 'COMPLETE') this._startDrivePoll();
                        }, 5000);
                        break;
                }
            } catch (err) {
                console.error('Error during _enterState(' + newState + '):', err);
            }
        },

        // ===========================================================
        // Drive polling
        // ===========================================================

        _startDrivePoll() {
            this._drivePollInterval = setInterval(async () => {
                const data = await this._api('/api/drive/status');
                if (!data) return;
                this.driveStatus = data.drive_status;
                if (data.drive_status === 'disc_ok' && data.track_count > 0) {
                    this.discTrackCount = data.track_count;
                    this.discDuration   = data.total_duration || '';
                } else {
                    this.discTrackCount = 0;
                    this.discDuration   = '';
                }
                this._handleDriveStatusChange();
            }, 2000);
        },

        _handleDriveStatusChange() {
            this._debug('drive', { status: this.driveStatus, state: this.state });
            if (this.state === 'WAITING_FOR_DISC' && this.driveStatus === 'disc_ok') {
                this._enterState('WAITING_FOR_ID');
            } else if (this.state === 'WAITING_FOR_ID' && this.driveStatus !== 'disc_ok') {
                this._enterState('WAITING_FOR_DISC');
            } else if (this.state === 'COMPLETE' && this.driveStatus === 'disc_ok') {
                this._enterState('WAITING_FOR_ID');
            }
        },

        driveStatusMessage() {
            switch (this.driveStatus) {
                case 'tray_open':  return 'Please insert a disc and close the tray';
                case 'no_disc':    return 'Please insert a disc';
                case 'not_ready':  return 'Reading disc, please wait\u2026';
                case 'no_info':    return 'Drive status unknown \u2014 please wait';
                default:           return 'Please insert a disc';
            }
        },

        // ===========================================================
        // Rip polling
        // ===========================================================

        _startRipPoll() {
            this._ripPollInterval = setInterval(async () => {
                const data = await this._api('/api/rip/status');
                if (!data) return;

                this.ripState          = data.state;
                this.ripLocationId     = data.location_id;
                this.progressPct       = data.progress_pct;
                this.trackProgressPct  = data.track_progress_pct ?? 0;
                this.tracksDone        = data.tracks_done;
                this.tracksTotal       = data.tracks_total;
                this.currentTrack      = data.current_track;
                this.currentTrackPhase = data.current_track_phase;
                this.badSectors        = data.bad_sectors;
                this.logTail           = data.log_tail;

                this._handleRipStatusChange();
            }, 1000);
        },

        _handleRipStatusChange() {
            this._debug('rip poll', { ripState: this.ripState, pct: this.progressPct, track: this.currentTrack });
            if (this.state === 'RIPPING') {
                if (this.ripState === 'complete') {
                    this.sessionProcessed++;
                    this._enterState('COMPLETE');
                } else if (this.ripState === 'damaged') {
                    this.sessionDamaged++;
                    this._enterState('DAMAGED');
                } else if (this.ripState === 'idle') {
                    // Worker died and state was auto-reset by the status endpoint
                    this._debug('stale rip — auto-recovering');
                    this._enterState('WAITING_FOR_DISC');
                }
            } else if (this.state === 'CANCELLING') {
                if (this.ripState === 'cancelled') {
                    this._enterState('CANCELLED');
                } else if (this.ripState === 'complete') {
                    // Cancel arrived after rip finished
                    this.sessionProcessed++;
                    this._enterState('COMPLETE');
                } else if (this.ripState === 'damaged') {
                    this.sessionDamaged++;
                    this._enterState('DAMAGED');
                } else if (this.ripState === 'idle') {
                    this._debug('stale cancelling — auto-recovering');
                    this._enterState('WAITING_FOR_DISC');
                }
            }
        },

        // ===========================================================
        // Polling management
        // ===========================================================

        _clearAllPolling() {
            clearInterval(this._drivePollInterval);
            clearInterval(this._ripPollInterval);
            this._drivePollInterval = null;
            this._ripPollInterval   = null;
        },

        // ===========================================================
        // Inactivity beep
        // ===========================================================

        _resetInactivityTimer() {
            this._stopBeeping();
            clearTimeout(this._inactivityTimer);
            this._inactivityTimer = setTimeout(() => {
                this._startBeeping();
            }, this.cfg.inactivityBeepHoldoffSeconds * 1000);
        },

        _clearInactivityTimer() {
            clearTimeout(this._inactivityTimer);
            this._inactivityTimer = null;
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

        _emitBeep() {
            try {
                if (!this._audioCtx) {
                    this._audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                const osc  = this._audioCtx.createOscillator();
                const gain = this._audioCtx.createGain();
                osc.connect(gain);
                gain.connect(this._audioCtx.destination);
                osc.type = 'sine';
                osc.frequency.value = this.cfg.beepFrequencyHz;
                const t = this._audioCtx.currentTime;
                const d = this.cfg.beepDurationMs / 1000;
                gain.gain.setValueAtTime(0.3, t);
                gain.gain.exponentialRampToValueAtTime(0.001, t + d);
                osc.start(t);
                osc.stop(t + d);
            } catch (e) {
                // AudioContext unavailable before user gesture — silent fail
            }
        },

        // ===========================================================
        // Wake Lock (prevents screen sleep during rip)
        // ===========================================================

        async _acquireWakeLock() {
            try {
                if ('wakeLock' in navigator) {
                    this._wakeLock = await navigator.wakeLock.request('screen');
                }
            } catch (e) {
                // Wake lock not available — acceptable
            }
        },

        _releaseWakeLock() {
            if (this._wakeLock) {
                this._wakeLock.release().catch(() => {});
                this._wakeLock = null;
            }
        },

        // ===========================================================
        // API helper
        // ===========================================================

        async _api(path, options = {}) {
            try {
                const res  = await fetch(path, {
                    headers: { 'Content-Type': 'application/json' },
                    ...options,
                });
                const json = await res.json();
                if (!json.ok) {
                    this._handleApiError(json.error);
                    return null;
                }
                this._debug('api ok', { path });
                return json.data;
            } catch (err) {
                this._handleApiError({ code: 'NETWORK_ERROR', message: err.message });
                return null;
            }
        },

        _handleApiError(error) {
            console.error('API error:', error.code, error.message);
            this._debug('api err', { code: error.code, msg: error.message });
        },

        // ===========================================================
        // User actions
        // ===========================================================

        // ── WAITING_FOR_ID ────────────────────────────────────────

        async cancelIdEntry() {
            await this._apiEjectWithFallback();
            this._enterState('WAITING_FOR_DISC');
        },

        async submitLocationId() {
            const id = this.locationIdInput.trim();
            if (!id) return;

            this._debug('lookup', { id });
            this.idLookupError = '';

            const data = await this._api('/api/catalogue/lookup?id=' + encodeURIComponent(id));
            if (data === null) {
                this.idLookupError = 'Could not reach the server. Please try again.';
                return;
            }

            this._debug('lookup result', { found: data.found, location: data.location });
            if (data.found) {
                // Replace the user's input with the canonical form from the DB
                // (correct spacing) so all subsequent operations use the DB value.
                this.locationIdInput = data.location;
                this.catalogueEntry = {
                    location:    data.location,
                    subject:     data.subject,
                    description: data.description,
                };
                this._enterState('CONFIRM_KNOWN_ID');
            } else {
                this.catalogueEntry = null;
                this._enterState('CONFIRM_UNKNOWN_ID');
            }
        },

        // ── CONFIRM_KNOWN_ID ──────────────────────────────────────

        async confirmAndRip() {
            if (this._busy) return;
            this._busy = true;
            this.ripStartError = '';
            this._debug('confirmAndRip', { id: this.locationIdInput.trim() });

            try {
                const res  = await fetch('/api/rip/start', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        location_id:       this.locationIdInput.trim(),
                        confirmed_unknown: false,
                    }),
                });
                const json = await res.json();

                if (json.ok) {
                    this.ripLocationId = json.data.location_id;
                    this.tracksTotal   = json.data.track_count;
                    this._enterState('RIPPING');
                } else if (json.error.code === 'ALREADY_COMPLETE') {
                    // Disc already processed — eject and send back to waiting
                    this.ripStartError = 'This disc has already been processed.';
                    await this._api('/api/drive/eject', { method: 'POST' });
                    // Brief pause so operator can read the message
                    setTimeout(() => this._enterState('WAITING_FOR_DISC'), 2500);
                } else {
                    this.ripStartError = json.error.message;
                    this._busy = false;
                }
            } catch (err) {
                this.ripStartError = 'Could not reach the server. Please try again.';
                this._busy = false;
            }
        },

        async wrongDisc() {
            await this._apiEjectWithFallback();
            this._enterState('WAITING_FOR_DISC');
        },

        // ── CONFIRM_UNKNOWN_ID ────────────────────────────────────

        async confirmUnknownAndRip() {
            if (this._busy) return;
            if (this.locationIdInput !== this.locationIdConfirm) return;

            this._busy = true;
            this.ripStartError = '';

            try {
                const res  = await fetch('/api/rip/start', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        location_id:       this.locationIdInput.trim(),
                        confirmed_unknown: true,
                    }),
                });
                const json = await res.json();

                if (json.ok) {
                    this.ripLocationId = json.data.location_id;
                    this.tracksTotal   = json.data.track_count;
                    this._enterState('RIPPING');
                } else {
                    this.ripStartError = json.error.message;
                    this._busy = false;
                }
            } catch (err) {
                this.ripStartError = 'Could not reach the server. Please try again.';
                this._busy = false;
            }
        },

        async cancelUnknown() {
            await this._apiEjectWithFallback();
            this._enterState('WAITING_FOR_DISC');
        },

        // ── RIPPING ───────────────────────────────────────────────

        async cancelRip() {
            if (this._busy) return;
            this._busy = true;
            this._debug('cancelRip');
            try {
                await this._api('/api/rip/cancel', { method: 'POST' });
                this._enterState('CANCELLING');
            } finally {
                this._busy = false;
            }
        },

        // ── COMPLETE ──────────────────────────────────────────────

        processAnother() {
            this._enterState('WAITING_FOR_DISC');
        },

        // ── DAMAGED / CANCELLED ───────────────────────────────────

        tryAgain() {
            // Pre-fill ID; keep catalogueEntry from the rip that failed
            if (this.ripLocationId) {
                this.locationIdInput = this.ripLocationId;
            }
            this._enterState('CONFIRM_KNOWN_ID');
        },

        async abandonAndContinue() {
            await this._apiEjectWithFallback();
            this._enterState('WAITING_FOR_DISC');
        },

        async continueAfterCancel() {
            await this._apiEjectWithFallback();
            this._enterState('WAITING_FOR_DISC');
        },

        // ── Force reset (escape from stuck RIPPING / CANCELLING) ─

        async forceReset() {
            this._debug('forceReset');
            const data = await this._api('/api/reset', { method: 'POST' });
            if (data !== null) {
                this._enterState('WAITING_FOR_DISC');
            }
        },

        // ── STOPPED ───────────────────────────────────────────────

        stopSession() {
            this._enterState('STOPPED');
        },

        startNewSession() {
            this.sessionProcessed = 0;
            this.sessionDamaged   = 0;
            this._enterState('WAITING_FOR_DISC');
        },

        // ===========================================================
        // Internal helpers
        // ===========================================================

        /**
         * Debug helper — logs to console and updates the debug overlay message.
         * No-op when cfg.debug is falsy.
         */
        _debug(message, context = null) {
            if (!this.cfg || !this.cfg.debug) return;
            const ts  = new Date().toISOString().replace('T', ' ').substring(0, 19);
            const ctx = context ? ' ' + JSON.stringify(context) : '';
            console.debug('[CD:' + ts + '] ' + message + ctx);
            this._lastDebugMsg = message + (context ? ' ' + JSON.stringify(context) : '');
        },

        /**
         * Eject the disc, but if the eject fails just log a warning
         * and show a non-blocking message — never block a state transition
         * due to an eject error.
         */
        async _apiEjectWithFallback() {
            const data = await this._api('/api/drive/eject', { method: 'POST' });
            if (data === null) {
                // _handleApiError already logged; surface nothing extra to operator —
                // the WAITING_FOR_DISC message will prompt manual action if needed.
                console.warn('Eject failed — operator may need to eject manually.');
            }
        },

    };
}
