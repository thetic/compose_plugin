#!/bin/sh
# Compose Manager - Background command runner
# Runs a compose command fully in background, captures output to last_cmd.log,
# and sends an Unraid system notification on success or failure.
#
# Args: <compose_sh_args...>
#   Must include -s <stack_path> so the log and result are written correctly.
#   All other args are passed verbatim to compose.sh.

COMPOSE_SH="$(dirname "$0")/compose.sh"
NOTIFY="/usr/local/emhttp/webGui/scripts/notify"

# Parse stack path (-s) from the argument list so we know where to write the log
STACK_PATH=""
OPERATION=""
for i in "$@"; do
  case "$i" in
    -s*) STACK_PATH="${i#-s}" ;;
    -c*) OPERATION="${i#-c}" ;;
  esac
done

LOG_FILE="${STACK_PATH}/last_cmd.log"
PROJECT_NAME=$(basename "$STACK_PATH")

# Ensure the stack directory exists before writing
if [ -z "$STACK_PATH" ] || [ ! -d "$STACK_PATH" ]; then
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Background command failed: $PROJECT_NAME" \
      -d "Could not determine stack path for background operation." -i 'warning'
  fi
  exit 1
fi

# Create a readable header in the log
{
  echo "=== Compose Manager Background Run ==="
  echo "Stack:     $PROJECT_NAME"
  echo "Operation: $OPERATION"
  echo "Started:   $(date)"
  echo "======================================="
  echo ""
} > "$LOG_FILE"

# Run compose.sh, capturing all output (stdout + stderr) and appending to the log
sh "$COMPOSE_SH" "$@" >> "$LOG_FILE" 2>&1
RC=$?

{
  echo ""
  echo "======================================="
  echo "Finished:  $(date)"
  echo "Exit code: $RC"
  echo "======================================="
} >> "$LOG_FILE"

# Send Unraid notification
if [ $RC -eq 0 ]; then
  MSG="Stack '$PROJECT_NAME' ($OPERATION) completed successfully."
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Stack $OPERATION complete: $PROJECT_NAME" -d "$MSG"
  fi
else
  # Include last few lines of output in the notification for quick diagnosis
  TAIL=$(tail -n 10 "$LOG_FILE" 2>/dev/null | tr -cd '[:print:]\n\t')
  MSG="Stack '$PROJECT_NAME' ($OPERATION) failed (exit $RC). Recent output: $TAIL"
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Stack $OPERATION failed: $PROJECT_NAME" -d "$MSG" -i 'warning'
  fi
fi

exit $RC
