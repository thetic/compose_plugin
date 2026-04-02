#!/bin/sh
# Compose auto-update runner
# Args: <compose_yml_path> <project_name>

COMPOSE_FILE="$1"
PROJECT_NAME="$2"
NOTIFY="/usr/local/emhttp/webGui/scripts/notify"
LOCK_DIR="/var/run/compose.manager"
LOCK_TIMEOUT=${COMPOSE_LOCK_TIMEOUT:-30}
COMMAND_TIMEOUT=${COMPOSE_COMMAND_TIMEOUT:-1800}

if [ -z "$COMPOSE_FILE" ] || [ -z "$PROJECT_NAME" ]; then
  echo "Usage: $0 <compose_yml_path> <project_name>"
  exit 2
fi

# Create lock directory if needed
mkdir -p "$LOCK_DIR" 2>/dev/null || true

# Acquire lock to prevent concurrent operations on the same stack
LOCK_FILE="$LOCK_DIR/${PROJECT_NAME}.lock"
exec 9>"$LOCK_FILE"

waited=0
while ! flock -n 9 2>/dev/null; do
    if [ "$waited" -ge "$LOCK_TIMEOUT" ]; then
        echo "Could not acquire lock for $PROJECT_NAME after ${LOCK_TIMEOUT}s - another operation may be in progress" >&2
        exit 1
    fi
    sleep 1
    waited=$((waited + 1))
done

# Write lock info
echo "{\"pid\":$$,\"command\":\"autoupdate\",\"time\":\"$(date -Iseconds)\"}" >&9

OUT=$(mktemp 2>/dev/null || echo "")
if [ -z "$OUT" ]; then
  echo "Failed to create temporary output file" >&2
  exit 1
fi
RC=0

summarize_output() {
  # Keep notifications readable and avoid huge untrusted payloads.
  tail -n 40 "$OUT" 2>/dev/null | tr -cd '[:print:]\n\t'
}

# Get current image digests before pull
# Uses docker compose images to list service images, then inspects each for RepoDigests
get_image_digests() {
  docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" images -q 2>/dev/null | while read -r img_id; do
    if [ -n "$img_id" ]; then
      docker inspect --format='{{index .RepoDigests 0}}' "$img_id" 2>/dev/null || echo "$img_id"
    fi
  done | sort
}

OLD_DIGESTS=$(get_image_digests || true)

# Run pull and capture output (timeout prevents indefinite hangs on unresponsive registries)
timeout "$COMMAND_TIMEOUT" docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" pull > "$OUT" 2>&1 || RC=$?

if [ "$RC" -ne 0 ]; then
  ERRMSG="Auto-update pull failed for '$PROJECT_NAME'. Recent output: $(summarize_output)"
  echo "$ERRMSG" >&2
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Auto-update failed: $PROJECT_NAME" -d "$ERRMSG" -i 'warning'
  fi
  rm -f "$OUT"
  exit 1
fi

# Get new image digests after pull
NEW_DIGESTS=$(get_image_digests || true)

# Compare digests to determine if any images were updated
if [ "$OLD_DIGESTS" != "$NEW_DIGESTS" ]; then
  # Images changed - run recreate/up
  timeout "$COMMAND_TIMEOUT" docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" up -d >> "$OUT" 2>&1 || RC=$?
  
  if [ "$RC" -ne 0 ]; then
    ERRMSG="Auto-update up failed for '$PROJECT_NAME'. Recent output: $(summarize_output)"
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
