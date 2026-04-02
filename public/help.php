<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help — HBLHS CD Archive Ingest</title>
    <link rel="stylesheet" href="/app.css">
</head>
<body>

    <header class="app-header">
        <h1>HBLHS CD Archive Ingest — Help</h1>
        <div class="header-right">
            <a href="/" class="header-btn">&#8592; Back to main screen</a>
        </div>
    </header>

    <main class="help-content">

        <p class="help-intro">
            This system rips audio CDs track by track, encodes them to MP3, and saves them
            to the archive. The steps below explain the full process.
        </p>

        <!-- ── Step 1 ───────────────────────────────────────────── -->
        <div class="help-section">
            <h2>Step 1 &mdash; Insert the disc</h2>
            <p>Place the CD in the drive with the label facing up. The main screen will
            show a message while it reads the disc. Once detected, it will show the number
            of tracks found and ask you to enter the Location ID.</p>
            <p>If the tray is open, close it and the system will detect the disc
            automatically &mdash; you do not need to press anything.</p>
        </div>

        <!-- ── Step 2 ───────────────────────────────────────────── -->
        <div class="help-section">
            <h2>Step 2 &mdash; Enter the Location ID</h2>
            <p>The Location ID is the reference code printed on the disc case
            (for example, <strong>ARC 1 M</strong>). Type it in and press
            <strong>Enter</strong> or click <strong>Look up</strong>.</p>

            <h3>Disc already in the catalogue</h3>
            <p>The system will show the disc title and description. Check that
            these match the disc you have inserted, then press <strong>Enter</strong>
            or click <strong>Yes &mdash; start ripping</strong> to continue.
            If the details do not match, click <strong>Wrong disc &mdash; go back</strong>
            and re-enter the correct ID.</p>

            <h3>New disc (not yet in the catalogue)</h3>
            <p>If the Location ID is not found, you will be asked to enter
            details for the disc before it can be ripped. All fields are optional:</p>
            <ul>
                <li><strong>Author / Artist</strong> &mdash; performer, conductor,
                ensemble, or creator, e.g. <em>Senior Choir</em>.</li>
                <li><strong>Title</strong> &mdash; a short description of the
                recording, e.g. <em>Annual Concert 2003</em>.</li>
                <li><strong>Date of recording</strong> &mdash; an approximate date is
                fine, e.g. <em>circa 2002</em> or <em>Summer 1998</em>.</li>
            </ul>
            <p>Press <strong>Tab</strong> or <strong>Enter</strong> to move between
            fields. Click <strong>Continue</strong> to proceed to confirmation.
            These details are saved to a local catalogue so the disc will be
            recognised automatically next time.</p>
        </div>

        <!-- ── Step 3 ───────────────────────────────────────────── -->
        <div class="help-section">
            <h2>Step 3 &mdash; Ripping and encoding</h2>
            <p>The system rips each track from the disc and then encodes it to MP3.
            Two progress bars are shown:</p>
            <ul>
                <li>The <strong>track bar</strong> shows progress through the current
                track, and updates its label to show whether it is ripping or encoding.</li>
                <li>The <strong>disc bar</strong> shows overall progress across all tracks
                (shown only for discs with more than one track).</li>
            </ul>
            <p>This process cannot be interrupted once it starts, but you can
            <strong>cancel</strong> at any time using the Cancel button at the bottom of
            the screen. Any tracks already completed will be discarded.</p>
            <p>A bad-sector warning will appear if the disc has areas that cannot be read
            cleanly. Ripping will continue regardless and as much audio as possible will
            be recovered, but the recording should be inspected afterwards.</p>
        </div>

        <!-- ── Step 4 ───────────────────────────────────────────── -->
        <div class="help-section">
            <h2>Step 4 &mdash; Complete</h2>
            <p>When all tracks have been processed the disc is ejected automatically
            and a summary screen is shown. You can then:</p>
            <ul>
                <li>Insert the next disc &mdash; the system will detect it and move
                on automatically.</li>
                <li>Click <strong>Process another disc</strong> to return to the
                waiting screen manually.</li>
                <li>Click <strong>Stop session</strong> to end and see a session
                summary.</li>
            </ul>
        </div>

        <!-- ── Problems ─────────────────────────────────────────── -->
        <div class="help-section">
            <h2>When something goes wrong</h2>

            <h3>Processing failed</h3>
            <p>If the rip cannot be completed the screen will show a
            <strong>Processing Failed</strong> message explaining what went wrong
            (e.g. too many read errors, encode failure, or the output drive being full).
            Any audio that was recovered is saved to the output folder for inspection.</p>
            <p>You can <strong>Try again</strong> to re-attempt the same disc, or
            <strong>Abandon and continue</strong> to move on to the next disc.</p>

            <h3>Rip cancelled</h3>
            <p>If you cancelled the rip, no data is saved for that disc.
            Click <strong>Try again</strong> to re-insert and retry, or
            <strong>Continue</strong> to move on.</p>

            <h3>Disc not ejecting</h3>
            <p>If the disc does not eject after completing, press the physical eject
            button on the drive.</p>
        </div>

        <!-- ── Header bar ────────────────────────────────────────── -->
        <div class="help-section">
            <h2>The top bar</h2>

            <h3>Session counter</h3>
            <p>Shows how many discs have been processed since the session started.
            If any discs failed, the count of failures is shown alongside.</p>

            <h3>Disk space indicator</h3>
            <p>Shows how full the output drive is as a percentage. The display turns
            <strong class="help-warn">amber</strong> at 80% and
            <strong class="help-crit">red</strong> at 90%. If it turns red, stop the
            session and free up space on the output drive before continuing.</p>

            <h3>Download all / Download new</h3>
            <p>These buttons export the locally-acquired disc catalogue (discs that
            were not in the main catalogue when they were ripped) as a CSV file.</p>
            <ul>
                <li><strong>Download new</strong> &mdash; exports only records that have
                not been downloaded before, then marks them so they are not included in
                future &ldquo;new&rdquo; downloads. Use this for regular exports.</li>
                <li><strong>Download all</strong> &mdash; exports every record in the
                local catalogue without changing anything. Use this for a full backup
                or to re-export previously downloaded records.</li>
            </ul>
        </div>

    </main>

</body>
</html>
