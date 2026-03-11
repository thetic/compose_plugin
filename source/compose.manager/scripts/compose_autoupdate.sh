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
RC=0

# Get current image digests before pull
# Uses docker compose images to list service images, then inspects each for RepoDigests
get_image_digests() {
  docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" images -q 2>/dev/null | while read -r img_id; do
    if [ -n "$img_id" ]; then
      docker inspect --format='{{index .RepoDigests 0}}' "$img_id" 2>/dev/null || echo "$img_id"
    fi
  done | sort
}

OLD_DIGESTS=$(get_image_digests)

# Run pull and capture output
docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" pull > "$OUT" 2>&1 || RC=$?

if [ "$RC" -ne 0 ]; then
  ERRMSG="Auto-update pull failed for '$PROJECT_NAME'. See output: $(cat $OUT)"
  echo "$ERRMSG" >&2
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Auto-update failed: $PROJECT_NAME" -d "$ERRMSG" -i 'warning'
  fi
  rm -f "$OUT"
  exit 1
fi

# Get new image digests after pull
NEW_DIGESTS=$(get_image_digests)

# Compare digests to determine if any images were updated
if [ "$OLD_DIGESTS" != "$NEW_DIGESTS" ]; then
  # Images changed - run recreate/up
  docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" up -d >> "$OUT" 2>&1 || RC=$?
  
  if [ "$RC" -ne 0 ]; then
    ERRMSG="Auto-update up failed for '$PROJECT_NAME'. See output: $(cat $OUT)"
    echo "$ERRMSG" >&2
    if [ -x "$NOTIFY" ]; then
      "$NOTIFY" -e 'Compose Manager' -s "Auto-update failed: $PROJECT_NAME" -d "$ERRMSG" -i 'warning'
    fi
    rm -f "$OUT"
    exit 1
  fi
  
  MSG="Stack '$PROJECT_NAME' was updated successfully."
  echo "$MSG"
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Stack updated: $PROJECT_NAME" -d "$MSG"
  fi
else
  MSG="No updates for stack '$PROJECT_NAME'."
  echo "$MSG"
fi

rm -f "$OUT"
exit 0
