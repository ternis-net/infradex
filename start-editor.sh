#!/bin/sh
# Start inventory editor. Creates .edit-token on first run, then uses it.
# Usage: ./start-editor.sh [port]   (default 8001, or EDITOR_PORT env)
set -eu
cd "$(dirname "$0")"
PORT="${1:-${EDITOR_PORT:-8001}}"
command -v php >/dev/null 2>&1 || { echo "php not found" >&2; exit 1; }
if [ ! -f .edit-token ]; then
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 32 > .edit-token
    else
        php -r 'echo bin2hex(random_bytes(32));' > .edit-token
    fi
    chmod 600 .edit-token
    echo "created .edit-token (gitignored). Paste its content into the editor token field."
fi
export INFRADEx_EDIT_TOKEN="$(cat .edit-token)"
echo "editor: http://127.0.0.1:${PORT}/editor.php"
exec php -S "127.0.0.1:${PORT} ../editor.php"
