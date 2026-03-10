<?php
/**
 * Runner invoked by cron to check autoupdate.json and run updates when scheduled
 */
require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

$autofile = $plugin_root . "autoupdate.json";
if (!is_file($autofile)) exit(0);

$data = json_decode(file_get_contents($autofile), true);
if (!is_array($data)) exit(0);

// Set timezone from /etc/timezone if available, otherwise use system default
$timezoneFile = '/etc/timezone';
if (is_file($timezoneFile)) {
    $tz = trim(@file_get_contents($timezoneFile));
    if ($tz && @date_default_timezone_set($tz)) {
        // Successfully set timezone
    }
}
// If no /etc/timezone or failed, PHP uses system default which is fine

$now = time();
$hour = intval(date('H', $now));
$minute = intval(date('i', $now));
$wday = intval(date('w', $now)); // 0 (Sun) - 6
$mday = intval(date('j', $now)); // 1-31

foreach ($data as $path => $entry) {
    if ($path === 'defaults') continue;
    if (!is_array($entry)) continue;
    if (empty($entry['enabled'])) continue;
    $schedule = isset($entry['schedule']) ? $entry['schedule'] : 'daily';
    $time = isset($entry['time']) ? $entry['time'] : '02:00';
    $parts = explode(':', $time);
    $sh = intval($parts[0]);
    $sm = intval($parts[1]);

    $shouldRun = false;
    if ($sh == $hour && $sm == $minute) {
        if ($schedule == 'daily') $shouldRun = true;
        elseif ($schedule == 'weekly') {
            $weekday = isset($entry['weekday']) ? intval($entry['weekday']) : $wday;
            if ($weekday == $wday) $shouldRun = true;
        } elseif ($schedule == 'monthly') {
            $monthday = isset($entry['monthday']) ? intval($entry['monthday']) : $mday;
            if ($monthday == $mday) $shouldRun = true;
        }
    }

    if ($shouldRun) {
        // Find compose file using shared utility
        $composeFile = findComposeFile($path);
        if (!$composeFile) continue;

        // Resolve project name via StackInfo if possible
        $stackInfo = StackInfo::fromComposePath($compose_root, $path);
        if ($stackInfo !== null) {
            $projectName = $stackInfo->sanitizedName;
        } else {
            $projectName = basename($path);
            if (is_file($path . '/name')) $projectName = trim(file_get_contents($path . '/name'));
            $projectName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $projectName);
        }

        // Log the scheduled auto-update trigger
        clientDebug("[autoupdate] Scheduled auto-update triggered for: $projectName ($schedule)", null, 'daemon', 'info');

        $script = $plugin_root . "scripts/compose_autoupdate.sh";
        // Allow overriding the shell command via environment for tests; default to sh
        $shCmd = getenv('COMPOSE_MANAGER_SH') ? getenv('COMPOSE_MANAGER_SH') : 'sh';
        $cmd = $shCmd . ' ' . escapeshellarg($script) . " " . escapeshellarg($composeFile) . " " . escapeshellarg($projectName) . " >/dev/null 2>&1 &";
        exec($cmd);
    }
}

?>
