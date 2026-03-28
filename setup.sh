#!/usr/bin/env bash
# CD Ingest System — one-time setup script
# Run this once on the laptop before first use.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== CD Ingest Setup ==="

# ---------------------------------------------------------------------------
# 1. Check required system packages
# ---------------------------------------------------------------------------
echo ""
echo "Checking required packages..."

MISSING=()
for cmd in php perl cdparanoia lame wget; do
    if ! command -v "$cmd" &>/dev/null; then
        MISSING+=("$cmd")
    fi
done

if [ ${#MISSING[@]} -gt 0 ]; then
    echo "  ERROR: The following required packages are not installed:"
    for pkg in "${MISSING[@]}"; do
        echo "    - $pkg"
    done
    echo ""
    echo "  Install them with:"
    echo "    sudo apt-get install php perl cdparanoia lame wget"
    exit 1
else
    echo "  All required packages found."
fi

# ---------------------------------------------------------------------------
# 2. Create directory structure
# ---------------------------------------------------------------------------
echo ""
echo "Creating directory structure..."

mkdir -p "$SCRIPT_DIR/public/vendor"
mkdir -p "$SCRIPT_DIR/data/output"
mkdir -p "$SCRIPT_DIR/data/temp"
mkdir -p "$SCRIPT_DIR/data/logs"
mkdir -p "$SCRIPT_DIR/scripts"
mkdir -p "$SCRIPT_DIR/api/drive"
mkdir -p "$SCRIPT_DIR/api/catalogue"
mkdir -p "$SCRIPT_DIR/api/rip"

echo "  Done."

# ---------------------------------------------------------------------------
# 3. Download Alpine.js
# ---------------------------------------------------------------------------
ALPINE_URL="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"
ALPINE_DEST="$SCRIPT_DIR/public/vendor/alpinejs.min.js"

echo ""
if [ -f "$ALPINE_DEST" ]; then
    echo "Alpine.js already present at public/vendor/alpinejs.min.js — skipping download."
    echo "  (Delete the file and re-run this script to force a fresh download.)"
else
    echo "Downloading Alpine.js..."
    if wget -q --show-progress -O "$ALPINE_DEST" "$ALPINE_URL"; then
        FILESIZE=$(wc -c < "$ALPINE_DEST")
        echo "  Saved to public/vendor/alpinejs.min.js (${FILESIZE} bytes)."
    else
        echo "  ERROR: wget failed. Check your internet connection and try again."
        rm -f "$ALPINE_DEST"
        exit 1
    fi
fi

# ---------------------------------------------------------------------------
# 4. Initialise rip state file
# ---------------------------------------------------------------------------
STATE_FILE="$SCRIPT_DIR/data/rip_state.json"

if [ ! -f "$STATE_FILE" ]; then
    echo ""
    echo "Initialising rip state file..."
    cat > "$STATE_FILE" <<'EOF'
{
    "state": "idle",
    "location_id": null,
    "progress_pct": 0,
    "tracks_done": 0,
    "tracks_total": 0,
    "current_track": 0,
    "current_track_phase": "",
    "bad_sectors": 0,
    "log_tail": "",
    "pid": null
}
EOF
    echo "  Done."
fi

# ---------------------------------------------------------------------------
# 5. Check config.ini exists (don't overwrite if already present)
# ---------------------------------------------------------------------------
CONFIG_FILE="$SCRIPT_DIR/config.ini"

if [ ! -f "$CONFIG_FILE" ]; then
    echo ""
    echo "Creating default config.ini..."
    cat > "$CONFIG_FILE" <<EOF
[general]
base_dir = $SCRIPT_DIR
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

[ripping]
# Max extra retries per bad sector before cdparanoia skips it and moves on.
# Lower = faster failure on damaged discs; higher = more thorough recovery.
# Default 3 is a reasonable balance for archival kiosk use.
cdparanoia_options = "--never-skip=3"

[encoding]
format = mp3
lame_options = --preset voice

[catalogue]
source_url = https://res.cloudinary.com/hhdifljso/raw/upload/v1697296410/HBLHS_Web_catalogue_20_9_23_LO_6351647b68.csv
auto_refresh_on_start = true
EOF
    echo "  Created config.ini with defaults. Review and adjust before first use."
else
    echo ""
    echo "config.ini already exists — not overwritten."
fi

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
echo ""
echo "=== Setup complete ==="
echo ""
echo "Next steps:"
echo "  1. Review config.ini — in particular, verify 'drive' (/dev/sr0) and 'output_dir'."
echo "  2. Attach and mount the output USB hard drive; update 'output_dir' if needed."
echo "  3. Fetch the catalogue CSV:"
echo "       php -f scripts/fetch_catalogue.php"
echo "  4. Start the PHP server:"
echo "       php -S localhost:8080 -t public router.php"
echo "  5. Open http://localhost:8080 in Chrome to test."
