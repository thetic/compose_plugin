#!/bin/bash
# Compose Manager - Backup Cron Script
# Called by cron to create scheduled backups.

PLUGIN_ROOT="/usr/local/emhttp/plugins/compose.manager"
LOG_TAG="compose.manager"

logger -t "$LOG_TAG" "[backup] Scheduled backup starting..."

# Execute the backup via the PHP backend
result=$(php -r "
  \$_POST = ['action' => 'createBackup'];
  require_once('${PLUGIN_ROOT}/php/defines.php');
  require_once('${PLUGIN_ROOT}/php/backup_functions.php');
  \$r = createBackup();
  echo json_encode(\$r);
")

# Parse result fields individually to avoid eval
status=$(echo "$result" | php <<'PHP'
$j = json_decode(file_get_contents('php://stdin'), true) ?: [];
echo $j['result'] ?? 'error';
PHP
)
message=$(echo "$result" | php <<'PHP'
$j = json_decode(file_get_contents('php://stdin'), true) ?: [];
echo $j['message'] ?? 'Unknown error';
PHP
)
archive=$(echo "$result" | php <<'PHP'
$j = json_decode(file_get_contents('php://stdin'), true) ?: [];
echo $j['archive'] ?? '';
PHP
)
size=$(echo "$result" | php <<'PHP'
$j = json_decode(file_get_contents('php://stdin'), true) ?: [];
echo $j['size'] ?? '';
PHP
)
stacks=$(echo "$result" | php <<'PHP'
$j = json_decode(file_get_contents('php://stdin'), true) ?: [];
echo $j['stacks'] ?? '0';
PHP
)

if [ "$status" = "success" ]; then
    logger -t "$LOG_TAG" "[backup] Scheduled backup completed: $archive ($size, $stacks stacks)"
else
    logger -t "$LOG_TAG" "[backup] Scheduled backup FAILED: $message"
fi
