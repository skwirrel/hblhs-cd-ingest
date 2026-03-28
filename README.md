# HBLHS CD Archive Ingest

A kiosk application for digitising an audio CD collection. Designed for the **Hebden Bridge Local History Society (HBLHS)** archive, it guides a non-technical operator through ripping and encoding each disc, validates the disc against the archive catalogue, and writes the output to a USB hard drive.

Built with a PHP backend and an Alpine.js browser frontend. Runs on a dedicated Linux laptop in Chrome kiosk mode — no command-line interaction required during normal use.

---

## Documentation

Full specifications are in the `docs/` folder. Read them in this order:

| Document | Description |
|---|---|
| [`docs/cd-ingest-system-overview.md`](docs/cd-ingest-system-overview.md) | Purpose, architecture, technology choices, output layout, configuration overview. **Start here.** |
| [`docs/cd-ingest-api-spec.md`](docs/cd-ingest-api-spec.md) | All API endpoints, request/response formats, the rip state file schema, configuration reference. |
| [`docs/cd-ingest-frontend-spec.md`](docs/cd-ingest-frontend-spec.md) | State machine, screen specifications, polling behaviour, UI design rules. |

---

## Quick Start

```bash
# 1. One-time setup (installs dirs, downloads Alpine.js, creates config.ini)
./setup.sh

# 2. Fetch the catalogue CSV from Cloudinary
php scripts/fetch_catalogue.php

# 3. Start the server
php -S localhost:8080 -t public router.php

# 4. Open in browser
# http://localhost:8080
```

Check `config.ini` before first use — in particular verify `drive` (`/dev/sr0`) and `output_dir`.

---

## Project Layout

```
cdIngester/
│
├── README.md               ← you are here
├── config.ini              ← runtime configuration (created by setup.sh)
├── setup.sh                ← one-time setup script
├── router.php              ← PHP built-in server router; dispatches all requests
│
├── public/                 ← document root (served by the PHP built-in server)
│   ├── index.php           ← HTML shell; injects window.APP_CONFIG for the frontend
│   ├── app.js              ← Alpine.js component: all UI state and logic
│   ├── app.css             ← single stylesheet
│   └── vendor/
│       └── alpinejs.min.js ← Alpine.js (downloaded by setup.sh; not committed)
│
├── api/                    ← JSON API handlers (all return application/json)
│   ├── drive/
│   │   ├── status.php      ← GET  /api/drive/status  — drive state + disc info
│   │   └── eject.php       ← POST /api/drive/eject   — eject the disc
│   ├── catalogue/
│   │   ├── lookup.php      ← GET  /api/catalogue/lookup   — look up a Location ID
│   │   └── refresh.php     ← POST /api/catalogue/refresh  — re-fetch catalogue CSV
│   ├── rip/
│   │   ├── start.php       ← POST /api/rip/start   — begin a rip
│   │   ├── status.php      ← GET  /api/rip/status  — poll rip progress
│   │   └── cancel.php      ← POST /api/rip/cancel  — request cancellation
│   ├── reset.php           ← POST /api/reset  — force-kill worker and reset state
│   └── history.php         ← GET  /api/history — list completed rips
│
├── lib/                    ← shared PHP library code
│   ├── config.php          ← loadConfig() — reads and resolves config.ini
│   └── response.php        ← jsonOk/jsonError helpers, readStateFile/writeStateFile,
│                              locationDirName(), resolveStaleRip(), debugLog()
│
├── scripts/                ← CLI and background scripts
│   ├── cdstat.pl           ← Perl ioctl script: queries CD drive status (CDROM_DRIVE_STATUS)
│   ├── fetch_catalogue.php ← downloads the catalogue CSV from Cloudinary
│   └── rip_worker.php      ← background rip+encode worker; spawned by api/rip/start.php
│
├── docs/                   ← specifications (see Documentation section above)
│
└── data/                   ← runtime data (created by setup.sh; not committed)
    ├── catalogue.csv       ← local catalogue cache (fetched by fetch_catalogue.php)
    ├── rip_state.json      ← shared state file between rip_worker.php and status endpoint
    ├── output/             ← completed rips (one subdirectory per disc)
    │   └── arc_1_m_a3f4b2c1/
    │       ├── track01.mp3
    │       ├── track02.mp3
    │       └── meta.json
    ├── temp/               ← in-progress work directories (cleaned up after each rip)
    └── logs/
        └── debug.log       ← written when debug = true in config.ini
```

---

## Configuration

All configuration lives in `config.ini` at the project root. It is created with defaults by `setup.sh` and should be reviewed before first use.

```ini
[general]
base_dir = /www/cdIngester   # absolute path to this directory
debug = false                # set true to enable debug logging and UI panel

[device]
drive = /dev/sr0             # CD drive device path

[ripping]
cdparanoia_options = "--never-skip=3 -X"  # passed verbatim to cdparanoia

[encoding]
lame_options = --preset voice             # passed verbatim to lame

[paths]                      # all relative to base_dir unless absolute
output_dir = data/output
temp_dir   = data/temp
log_dir    = data/logs
state_file = data/rip_state.json
catalogue_csv = data/catalogue.csv
```

The UI-relevant subset of config is injected into the page at load time as `window.APP_CONFIG`. The frontend never reads `config.ini` directly.

---

## Dependencies

All must be installed via the system package manager (e.g. `apt`):

| Package | Purpose |
|---|---|
| `php` | Backend server and rip worker |
| `cdparanoia` | Accurate audio ripping with error correction |
| `lame` | MP3 encoding |
| `perl` | Drive status ioctl (`scripts/cdstat.pl`) |
| `wget` | Used by `setup.sh` to download Alpine.js |
