#!/bin/sh
# Start read-only inventory viewer. No token needed.
# Usage: scripts/start-viewer.sh [port]   (default 8000, or VIEWER_PORT env)
set -eu
cd "$(dirname "$0")/.."
PORT="${1:-${VIEWER_PORT:-8000}}"
command -v php >/dev/null 2>&1 || { echo "php not found" >&2; exit 1; }
echo "viewer: http://127.0.0.1:${PORT}/viewer.php"
exec php -S "127.0.0.1:${PORT}"
