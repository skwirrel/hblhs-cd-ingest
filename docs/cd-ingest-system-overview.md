# CD Ingest System — System Overview

**Version:** 1.0 (implemented)
**Read this document first, then:** CD Ingest System — API Specification v1.0, CD Ingest System — Frontend Specification v1.0

---

## Purpose

This system digitises a collection of several hundred audio CDs held by the **Hebden Bridge Local History Society (HBLHS)** archive. The CDs contain recordings of local people speaking — oral history interviews, reminiscences, and related material. They are primary source documents of significant historical value.

The goal is to produce a compressed digital copy of every CD that:

- Can be stored in its entirety on a single USB hard drive
- Is accessible without specialist equipment
- Is permanently associated with the catalogue record for that disc
- Records enough metadata about the ripping process to be trusted as an accurate copy

The original CDs are retained as the archival master copies. The digital files are an access layer, not a replacement.

---

## Physical Setup

The system runs on a **dedicated Linux laptop** configured specifically for this purpose. It is lent to the archive for the duration of the digitisation project and returned afterwards. The laptop is not a general-purpose machine.

On startup the laptop:

1. Logs in automatically (passwordless login)
2. Starts the PHP backend server as a systemd user service
3. Launches Chrome in kiosk mode with the ingest application as the home page

The operator sits at the laptop, inserts CDs one at a time, and works through the collection. The application guides them through each disc and handles all the technical work. No command-line interaction is required or expected.

The output files are written to a USB hard drive attached to the laptop. The target location is configurable.

---

## What the System Does

For each CD the system:

1. Detects when a disc has been inserted
2. Prompts the operator to enter the **Location ID** — a reference code printed on the disc case that corresponds to an entry in the HBLHS archive catalogue
3. Looks the Location ID up in a local copy of the catalogue using case-insensitive, space-normalised matching, and displays the description for the operator to verify
4. Rips each audio track from the disc one at a time using **cdparanoia**, which performs error correction to maximise accuracy on ageing or lightly scratched discs. Before ripping, cdparanoia is queried for per-track sector counts so the worker can track progress accurately
5. Encodes each track to **MP3** using **LAME** immediately after ripping, using the `--preset voice` setting which is optimised for spoken word at a low bitrate
6. Writes the MP3 files into a named output directory alongside a `meta.json` file recording the Location ID, catalogue description, rip timestamp, track count, bad sector count, and the exact commands used
7. Ejects the disc and waits for the next one

The rip and encode happen track by track — each track is ripped to a temporary WAV file, immediately encoded to MP3, and the WAV deleted before moving to the next track. At no point does the system hold more than one uncompressed track on disk.

If a disc cannot be fully read, the partial output is preserved in a clearly named damaged directory for later inspection rather than discarded. If a track has read errors but still produces a WAV file, encoding continues so the reviewer receives as much audio as possible.

---

## Why These Technology Choices

**MP3 over lossless formats:** The recordings are spoken word. The nuances of audio that lossless formats preserve — the full dynamic range, the precise high-frequency content — are not meaningful in this context. MP3 at `--preset voice` produces files that are faithful to the speech content, widely playable on any device without specialist software, and small enough that the entire collection fits comfortably on a single modest hard drive. The original CDs remain available if a higher-quality copy is ever needed.

**cdparanoia over simpler ripping tools:** Archive CDs may be old and lightly scratched. cdparanoia uses jitter correction and re-reading strategies to extract the most accurate possible audio from imperfect discs, and reports bad sectors explicitly. For archival work this is preferable to a fast ripper that silently glosses over read errors. The `--never-skip=3` option is used: after 3 failed retries on a sector without progress, cdparanoia fills the bad sector with zeros and moves on rather than retrying indefinitely, ensuring damaged discs complete rather than stalling.

**PHP backend with built-in server:** The system has exactly one user at a time and runs on localhost. A full web server stack would be unnecessary complexity. PHP's built-in `-S` server is entirely sufficient and requires no installation or configuration beyond PHP itself being present.

**Alpine.js frontend:** The UI is a single-page operator console with a well-defined state machine. Alpine.js provides reactive data binding and component lifecycle without requiring a build process, a node_modules directory, or any tooling beyond a text editor. The entire frontend is a handful of static files served directly by PHP.

**Chrome kiosk mode:** The operator is not a technical user. Presenting a full browser with an address bar and menus would invite confusion. Chrome's `--kiosk --app` mode presents the application full-screen with no browser controls, creating a clean appliance-like experience.

---

## System Architecture

```
┌─────────────────────────────────────────────────────┐
│  Linux Laptop                                        │
│                                                      │
│  ┌──────────────┐        ┌──────────────────────┐   │
│  │   Chrome     │  HTTP  │   PHP Built-in        │   │
│  │   (kiosk)    │◄──────►│   Server (-S)         │   │
│  │              │        │   localhost:{port}    │   │
│  │   Alpine.js  │        │                      │   │
│  │   app.js     │        │   index.php  (HTML)  │   │
│  │              │        │   /api/* handlers    │   │
│  └──────────────┘        └──────────┬───────────┘   │
│                                     │                │
│                          ┌──────────▼───────────┐   │
│                          │  rip_worker.php       │   │
│                          │  (background script) │   │
│                          │  cdparanoia + lame   │   │
│                          └──────────┬───────────┘   │
│                                     │                │
│   ┌──────────┐           ┌──────────▼───────────┐   │
│   │ CD Drive │◄──────────│  /dev/sr0            │   │
│   └──────────┘   ioctl   └──────────────────────┘   │
│                                     │                │
│   ┌──────────────────────────────────▼───────────┐  │
│   │  USB Hard Drive                               │  │
│   │  output/{location_slug}_{hash}/               │  │
│   │    track01.mp3                                │  │
│   │    track02.mp3                                │  │
│   │    meta.json                                  │  │
│   └───────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────┘
```

### Components

**Chrome / Alpine.js frontend**
The operator-facing UI. A single-page application implementing a state machine that guides the operator through the disc processing workflow. Communicates with the backend exclusively via JSON API calls over localhost. See the Frontend Specification for full detail.

**PHP backend**
A PHP router script handles all requests. API endpoints (`/api/*`) are dispatched to individual PHP handler scripts that perform actions or return state. The root endpoint serves `index.php`, which renders the HTML shell and injects runtime configuration as a JavaScript global. The backend spawns the rip worker as a background process and communicates progress via a shared state file on disk.

**Rip worker (`scripts/rip_worker.php`)**
A PHP background script that orchestrates the full rip-and-encode loop for a disc. It is spawned by `api/rip/start.php` as a background process, reads a rip info JSON file on startup (passed as a CLI argument) and deletes it immediately after reading. It runs cdparanoia track by track, encodes each WAV to MP3 with LAME, and writes progress updates to the shared state file (`rip_state.json`) every ~200ms. Cancel requests are delivered by the API writing a flag to the state file; the worker polls this flag and handles cancellation gracefully.

**Catalogue data**
The HBLHS archive catalogue is available as a CSV file hosted at a known Cloudinary URL. A local copy is fetched and cached on the laptop at setup time (and can be refreshed on demand). The application looks up Location IDs against this local copy — no internet access is required during normal operation. Matching is case-insensitive and strips spaces, so variants such as "ARC1M" and "ARC 1 M" resolve to the same record.

**Drive status detection**
The optical drive's tray state is queried using the Linux `ioctl` command `0x5326` (`CDROM_DRIVE_STATUS`), called via a Perl one-liner. This gives four reliable states: tray open, no disc, spinning up, and disc ready. The frontend polls this at two-second intervals when waiting for a disc.

---

## Output File Layout

```
{output_dir}/
│
├── arc_1_m_a3f4b2c1/              ← successful rip
│   ├── track01.mp3
│   ├── track02.mp3
│   └── meta.json
│
├── ora_4_b_2f9c1a3e/              ← another successful rip
│   ├── track01.mp3
│   └── meta.json
│
└── soc_2_a_c4d8b1f0_damaged_20260324T143022/   ← damaged rip (dated)
    ├── track01.mp3                              ← partial, may be incomplete
    └── meta.json                               ← status: "damaged"
```

Directory names are formed from a **slug** of the Location ID (lowercased, non-alphanumeric characters replaced with underscores) combined with the first 8 characters of the MD5 hash of the Location ID. The hash is computed from the **spaces-stripped, lowercased** form of the Location ID, so spacing variants (e.g. "ARC 1 M" and "ARC1M") always resolve to the same directory. This ensures human readability while guaranteeing uniqueness even if two Location IDs produce the same slug after normalisation.

A rip is only considered complete when its directory contains a `meta.json` with `"status": "ok"`. Damaged and cancelled rips are preserved for inspection but never treated as finished.

---

## The Catalogue

The HBLHS archive catalogue is the authoritative list of all items held by the archive, including the CDs being digitised. It is maintained by the society and published as a CSV file via their website.

The CSV has three relevant columns: `Subject`, `Location`, and `Description`. The Location field contains the reference code printed on each disc case — this is the "Location ID" the operator enters into the system.

A local copy of the catalogue CSV is cached on the laptop. The application uses this for two purposes:

1. **Validation:** confirming that the Location ID the operator has entered actually exists in the catalogue
2. **Verification:** displaying the catalogue description so the operator can confirm they have the right disc before ripping begins

Lookup matching is case-insensitive and strips spaces from both the query and the CSV values before comparing. The canonical form (with original spacing as stored in the CSV) is returned and displayed to the operator. After a successful lookup, `locationIdInput` in the frontend is updated to this canonical form.

If an operator enters a Location ID that is not in the catalogue, the system does not block them — some discs may not yet be catalogued — but it requires them to re-enter the ID as a deliberate confirmation before proceeding.

---

## Configuration

All system configuration lives in a single INI file. Most paths can be specified as relative to a `base_dir`, making the system relocatable without editing every path individually.

The configuration covers: the CD device path, the Perl cdstat script location, all file paths (output, temp, catalogue, logs, state file), server port, UI behaviour (beep timing), encoding options, catalogue source URL, ripping options, and a debug flag.

The UI-relevant subset of configuration is injected into the page by PHP at load time as `window.APP_CONFIG` — the frontend never reads the INI file directly.

See the Configuration Reference section of the API Specification for the full INI schema.

---

## Deployment Notes

The following setup steps are required on the laptop before first use. They are one-time operations performed by the developer before handing the machine to the archive.

1. Install PHP, Perl, cdparanoia, and LAME via the system package manager.
2. Clone or copy the application to `/opt/cd-ingest/` (or the chosen `base_dir`).
3. Download the Alpine.js minified build to `public/vendor/alpinejs.min.js`.
4. Run the catalogue fetch script to populate the local CSV cache.
5. Configure `config.ini` with correct paths, device, and output directory.
6. Create the systemd user service to start the PHP server on login.
7. Create the `~/.config/autostart/` entry to launch Chrome in kiosk mode on login.
8. Configure passwordless auto-login for the dedicated user account.
9. Attach and mount the output USB hard drive; verify the mount point matches `output_dir` in config.
10. Run through one test disc end-to-end to confirm the full pipeline works.

The autostart Chrome command should include `--disable-session-crashed-bubble --noerrdialogs` to prevent Chrome startup dialogs appearing in kiosk mode on an unclean previous shutdown.

---

## Constraints and Assumptions

- **Single user, single session.** The system is designed for one operator at one machine. There is no authentication, no concurrent access handling, and no multi-session support.
- **Local network only.** The application runs on localhost. It is never exposed to a network.
- **Internet not required at runtime.** The catalogue CSV is cached locally. All vendor JS is served locally. The only operation requiring internet is the optional catalogue refresh.
- **One disc at a time.** The rip pipeline processes a single disc sequentially. There is no queue and no parallelism.
- **Chrome only.** The frontend targets Chrome specifically (kiosk mode, Wake Lock API, Web Audio API). Other browsers are not supported and need not be tested.
- **The operator is not technical.** The UI must never require the operator to understand what is happening under the hood. Error messages must be in plain English with clear next steps.

---

*End of System Overview v1.0*
