<?php
require_once __DIR__ . '/../lib/config.php';

try {
    $config   = loadConfig();
    $uiConfig = $config['ui'];
    $hasError = false;
} catch (RuntimeException $e) {
    $hasError     = true;
    $errorMessage = $e->getMessage();
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HBLHS CD Archive Ingest</title>
    <link rel="stylesheet" href="/app.css">
<?php if (!$hasError): ?>
    <script>
        window.APP_CONFIG = <?php echo json_encode($uiConfig, JSON_PRETTY_PRINT); ?>;
    </script>
<?php endif; ?>
</head>
<body>
<?php if ($hasError): ?>
    <div class="config-error">
        <h1>Configuration Error</h1>
        <p><?php echo htmlspecialchars($errorMessage); ?></p>
        <p>Check that <code>config.ini</code> exists and is readable, then restart the server.</p>
    </div>
<?php else: ?>

    <div x-data="appData()" x-init="init()">

        <!-- ── Header ─────────────────────────────────────────────── -->
        <header class="app-header">
            <h1>HBLHS CD Archive Ingest</h1>
            <div class="header-right">
                <div class="header-actions">
                    <button class="header-btn" @click="downloadCsv('all')">Download all</button>
                    <button class="header-btn" @click="downloadCsv('new')">Download new</button>
                    <a href="/help" class="header-btn">Help</a>
                </div>
                <div class="session-counter" aria-live="off">
                    <span x-text="sessionProcessed"></span> processed<span
                        x-show="sessionFailed > 0">, <span x-text="sessionFailed"></span> with errors</span>
                </div>
                <div class="disk-usage" x-show="diskUsedPct !== null"
                    :class="{ 'disk-usage-warn': diskUsedPct >= 80, 'disk-usage-crit': diskUsedPct >= 90 }"
                    aria-live="off">
                    <span x-text="diskUsedPct + '%'"></span> full
                </div>
            </div>
        </header>

        <!-- ── Main content ───────────────────────────────────────── -->
        <main class="app-main">

            <!-- STARTUP -->
            <div x-show="state === 'STARTUP'" class="screen">
                <p class="status-message">Starting up…</p>
            </div>

            <!-- WAITING_FOR_DISC -->
            <div x-show="state === 'WAITING_FOR_DISC'" class="screen">
                <p class="status-message" aria-live="polite" x-text="driveStatusMessage()"></p>
                <div class="actions-foot">
                    <button class="btn btn-ghost" @click="stopSession()">Stop session</button>
                </div>
            </div>

            <!-- WAITING_FOR_ID -->
            <div x-show="state === 'WAITING_FOR_ID'" class="screen">
                <h2>Disc detected — please enter the Location ID</h2>
                <div class="disc-info" x-show="discTrackCount > 0">
                    <span x-text="discTrackCount"></span> track<span x-text="discTrackCount !== 1 ? 's' : ''"></span><span x-show="discDuration"> &middot; <span x-text="discDuration"></span></span>
                </div>
                <div class="form-group">
                    <label for="location-id-input">Location ID</label>
                    <input
                        id="location-id-input"
                        type="text"
                        x-model="locationIdInput"
                        @keydown="_resetInactivityTimer()"
                        @keydown.enter.prevent="submitLocationId()"
                        placeholder="e.g. ARC 1 M"
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="characters"
                        spellcheck="false"
                    >
                    <p class="hint">Enter the Location ID printed on the disc case</p>
                    <p class="inline-error" x-show="idLookupError" x-text="idLookupError" aria-live="polite"></p>
                </div>
                <div class="actions">
                    <button class="btn btn-primary" @click="submitLocationId()" :disabled="!locationIdInput.trim()">Look up</button>
                    <button class="btn btn-ghost" @click="cancelIdEntry()">Cancel — eject disc</button>
                </div>
            </div>

            <!-- CONFIRM_KNOWN_ID -->
            <div x-show="state === 'CONFIRM_KNOWN_ID'" class="screen"
                 @keydown.window.enter="state === 'CONFIRM_KNOWN_ID' && !_busy && confirmAndRip()">
                <h2>Please confirm this is the correct disc</h2>
                <div class="catalogue-card" x-show="catalogueEntry">
                    <dl>
                        <dt>Location</dt><dd x-text="catalogueEntry && catalogueEntry.location"></dd>
                        <dt>Subject</dt><dd x-text="catalogueEntry && catalogueEntry.subject"></dd>
                        <dt>Description</dt><dd x-text="catalogueEntry && catalogueEntry.description"></dd>
                    </dl>
                </div>
                <p class="inline-error" x-show="ripStartError" x-text="ripStartError" aria-live="polite"></p>
                <div class="actions">
                    <button id="confirm-rip-btn" class="btn btn-primary" @click="confirmAndRip()" :disabled="_busy">Yes — start ripping</button>
                    <button class="btn btn-secondary" @click="wrongDisc()">Wrong disc — go back</button>
                </div>
            </div>

            <!-- ACQUIRE_CD -->
            <div x-show="state === 'ACQUIRE_CD'" class="screen">
                <h2>New disc — please enter details</h2>
                <p class="body-text muted">Location ID: <strong x-text="locationIdInput"></strong></p>
                <div class="form-group">
                    <label for="acquire-title">Title <span style="color:var(--error)">*</span></label>
                    <input
                        id="acquire-title"
                        type="text"
                        x-model="acquireTitle"
                        @keydown="_resetInactivityTimer()"
                        @keydown.enter.prevent="document.getElementById('acquire-people')?.focus()"
                        @keydown.tab.prevent="document.getElementById('acquire-people')?.focus()"
                        placeholder="e.g. Annual Concert"
                        autocomplete="off"
                        autocorrect="off"
                        spellcheck="false"
                    >
                </div>
                <div class="form-group">
                    <label for="acquire-people">People</label>
                    <input
                        id="acquire-people"
                        type="text"
                        x-model="acquirePeople"
                        @keydown="_resetInactivityTimer()"
                        @keydown.enter.prevent="document.getElementById('acquire-date')?.focus()"
                        @keydown.tab.prevent="document.getElementById('acquire-date')?.focus()"
                        placeholder="e.g. Senior Choir"
                        autocomplete="off"
                        autocorrect="off"
                        spellcheck="false"
                    >
                </div>
                <div class="form-group">
                    <label for="acquire-date">Date of recording</label>
                    <input
                        id="acquire-date"
                        type="text"
                        x-model="acquireDate"
                        @keydown="_resetInactivityTimer()"
                        @keydown.enter.prevent="submitAcquisition()"
                        @keydown.tab.prevent="submitAcquisition()"
                        placeholder="e.g. circa 2002"
                        autocomplete="off"
                        autocorrect="off"
                        spellcheck="false"
                    >
                </div>
                <p class="inline-error" x-show="acquireError" x-text="acquireError" aria-live="polite"></p>
                <div class="actions">
                    <button class="btn btn-primary" @click="submitAcquisition()" :disabled="_busy || !acquireTitle.trim()">Continue</button>
                    <button class="btn btn-ghost" @click="cancelAcquisition()">Cancel — eject disc</button>
                </div>
            </div>

            <!-- RIPPING -->
            <div x-show="state === 'RIPPING'" class="screen">
                <h2>Processing disc — <span x-text="ripLocationId"></span></h2>
                <p class="body-text muted" x-show="catalogueEntry" x-text="catalogueEntry && catalogueEntry.description"></p>

                <p class="track-status" aria-live="polite" x-show="currentTrackPhase">
                    <span x-text="currentTrackPhase === 'ripping' ? 'Ripping' : 'Encoding'"></span>
                    track <span x-text="currentTrack"></span> of <span x-text="tracksTotal"></span>
                </p>
                <div class="progress-row">
                    <div class="progress-wrap">
                        <div class="progress-bar" :style="'width:' + trackProgressPct + '%'"></div>
                    </div>
                    <span class="progress-pct-label" x-text="trackProgressPct + '%'"></span>
                </div>
                <div x-show="tracksTotal > 1">
                    <p class="track-status disc-status">
                        Disc — <span x-text="tracksDone"></span> of <span x-text="tracksTotal"></span> tracks done
                    </p>
                    <div class="progress-row">
                        <div class="progress-wrap">
                            <div class="progress-bar" :style="'width:' + progressPct + '%'"></div>
                        </div>
                        <span class="progress-pct-label" x-text="progressPct + '%'"></span>
                    </div>
                </div>

                <div class="alert alert-warning" x-show="badSectors > 0" aria-live="polite">
                    ⚠ <span x-text="badSectors"></span> bad sector(s) detected
                </div>

                <details class="log-panel">
                    <summary>Show detail</summary>
                    <pre x-text="logTail"></pre>
                </details>

                <div class="actions-foot">
                    <button class="btn btn-danger" @click="cancelRip()" :disabled="_busy">Cancel</button>
                    <button class="btn btn-ghost" @click="forceReset()" :disabled="_busy">Force reset</button>
                </div>
            </div>

            <!-- CANCELLING -->
            <div x-show="state === 'CANCELLING'" class="screen">
                <p class="status-message">Cancelling — please wait…</p>
                <div class="actions-foot">
                    <button class="btn btn-ghost" @click="forceReset()">Force reset</button>
                </div>
            </div>

            <!-- COMPLETE -->
            <div x-show="state === 'COMPLETE'" class="screen">
                <div class="banner banner-success">
                    <span class="banner-icon">✓</span>
                    <h2>DISC HAS BEEN PROCESSED</h2>
                </div>
                <dl class="summary-list">
                    <dt>Location</dt><dd x-text="ripLocationId"></dd>
                    <dt>Tracks</dt><dd x-text="tracksTotal"></dd>
                    <template x-if="badSectors > 0">
                        <div class="summary-row-warning">
                            <dt>Bad sectors</dt>
                            <dd x-text="badSectors + ' noted'"></dd>
                        </div>
                    </template>
                </dl>
                <p class="hint">Or simply insert the next disc to continue automatically</p>
                <div class="actions">
                    <button class="btn btn-primary" @click="processAnother()">Process another disc</button>
                    <button class="btn btn-secondary" @click="stopSession()">Stop session</button>
                </div>
            </div>

            <!-- FAILED -->
            <div x-show="state === 'FAILED'" class="screen">
                <div class="banner banner-error">
                    <span class="banner-icon">✕</span>
                    <h2>PROCESSING FAILED</h2>
                </div>
                <dl class="summary-list">
                    <dt>Location</dt><dd x-text="ripLocationId"></dd>
                    <template x-if="badSectors > 0">
                        <div class="summary-row-warning">
                            <dt>Bad sectors</dt><dd x-text="badSectors"></dd>
                        </div>
                    </template>
                </dl>
                <p class="inline-error" x-show="failureMessage" x-text="failureMessage" aria-live="polite"></p>

                <details class="log-panel" open>
                    <summary>Show detail</summary>
                    <pre x-text="logTail"></pre>
                </details>

                <div class="actions">
                    <button class="btn btn-primary" @click="tryAgain()">Try again</button>
                    <button class="btn btn-secondary" @click="abandonAndContinue()">Abandon and continue</button>
                    <button class="btn btn-ghost" @click="stopSession()">Stop session</button>
                </div>
            </div>

            <!-- CANCELLED -->
            <div x-show="state === 'CANCELLED'" class="screen">
                <h2>DISC HAS NOT BEEN PROCESSED</h2>
                <p class="body-text">The rip was cancelled. No data has been saved for this disc.</p>
                <div class="actions">
                    <button class="btn btn-primary" @click="tryAgain()">Try again</button>
                    <button class="btn btn-secondary" @click="continueAfterCancel()">Continue</button>
                    <button class="btn btn-ghost" @click="stopSession()">Stop session</button>
                </div>
            </div>

            <!-- STOPPED -->
            <div x-show="state === 'STOPPED'" class="screen">
                <h2>Session complete</h2>
                <dl class="summary-list">
                    <dt>Discs processed</dt><dd x-text="sessionProcessed"></dd>
                    <template x-if="sessionFailed > 0">
                        <div class="summary-row-warning">
                            <dt>Discs with errors</dt>
                            <dd x-text="sessionFailed"></dd>
                        </div>
                    </template>
                </dl>
                <p class="body-text warning-text" x-show="sessionFailed > 0">
                    Discs with errors have been saved to the output folder for inspection.
                </p>
                <div class="actions">
                    <button class="btn btn-primary" @click="startNewSession()">Start new session</button>
                </div>
            </div>

        </main>

        <!-- ── Debug panel (only shown when debug=true in config.ini) ── -->
        <template x-if="cfg.debug">
            <div class="debug-panel">
                <span class="debug-badge">DEBUG</span>
                <span><b>state:</b> <span x-text="state"></span></span>
                <span><b>drive:</b> <span x-text="driveStatus"></span></span>
                <span x-show="ripLocationId">
                    <b>rip:</b> <span x-text="ripLocationId"></span>
                    — <span x-text="ripState"></span>
                    <span x-show="progressPct > 0">(<span x-text="progressPct"></span>%)</span>
                </span>
                <span x-show="badSectors > 0" class="debug-warn">
                    <b>bad sectors:</b> <span x-text="badSectors"></span>
                </span>
                <span x-show="_lastDebugMsg" class="debug-msg" x-text="_lastDebugMsg"></span>
            </div>
        </template>

    </div><!-- /x-data -->

    <script src="/vendor/alpinejs.min.js" defer></script>
    <script src="/app.js"></script>
<?php endif; ?>
</body>
</html>
