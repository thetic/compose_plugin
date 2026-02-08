#!/bin/sh
# Compose auto-update runner
# Args: <compose_yml_path> <project_name>

set -e

COMPOSE_FILE="$1"
PROJECT_NAME="$2"
PLUGIN_ROOT="/usr/local/emhttp/plugins/compose.manager"
NOTIFY="/usr/local/emhttp/webGui/scripts/notify"

if [ -z "$COMPOSE_FILE" ] || [ -z "$PROJECT_NAME" ]; then
  echo "Usage: $0 <compose_yml_path> <project_name>"
  exit 2
fi

OUT=$(mktemp)

# Run pull and capture output
docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" pull > "$OUT" 2>&1 || RC=$?

CONTENT=$(cat "$OUT")

# Determine if any images were updated
if echo "$CONTENT" | grep -E "Downloaded|Pull complete|Status: Downloaded|Downloaded newer image" >/dev/null 2>&1; then
  # Run recreate/up
  docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" up -d >> "$OUT" 2>&1 || RC=$?
  MSG="Stack '$PROJECT_NAME' was updated successfully."
  echo "$MSG"
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Stack updated: $PROJECT_NAME" -d "$MSG"
  fi
else
  MSG="No updates for stack '$PROJECT_NAME'."
  echo "$MSG"
fi

if [ -n "$RC" ] && [ "$RC" -ne 0 ]; then
  ERRMSG="Auto-update failed for '$PROJECT_NAME'. See output: $(cat $OUT)"
  echo "$ERRMSG" >&2
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Auto-update failed: $PROJECT_NAME" -d "$ERRMSG" -i 'warning'
  fi
  rm -f "$OUT"
  exit 1
fi

rm -f "$OUT"
exit 0
