<?php
/**
 * Runner invoked by cron to check autoupdate.json and run updates when scheduled
 */
require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

$autofile = getAutoUpdateConfigFilePath();
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

// Track whether we need to save updated last_run timestamps
$dataModified = false;

foreach ($data as $path => $entry) {
    if ($path === 'defaults') continue;
    if (!is_array($entry)) continue;
    if (empty($entry['enabled'])) continue;
    
    // Validate path is within allowed locations for security
    $realPath = realpath($path);
    $realComposeRoot = realpath($compose_root);
    if ($realPath === false) {
        clientDebug("[autoupdate] Skipping entry with unresolved path: $path", null, 'daemon', 'warn');
        continue;
    }
    $allowedPath = false;
    if ($realComposeRoot !== false) {
        $realComposeRoot = rtrim($realComposeRoot, DIRECTORY_SEPARATOR);
        if ($realPath === $realComposeRoot || strpos($realPath, $realComposeRoot . DIRECTORY_SEPARATOR) === 0) {
            $allowedPath = true;
        }
    }
    if (!$allowedPath && ($realPath === '/mnt' || strpos($realPath, '/mnt/') === 0)) {
        $allowedPath = true;
    }
    if (!$allowedPath && ($realPath === '/boot/config' || strpos($realPath, '/boot/config/') === 0)) {
        $allowedPath = true;
    }
    if (!$allowedPath) {
        clientDebug("[autoupdate] Skipping disallowed path: $path", null, 'daemon', 'warn');
        continue;
    }
    
    $schedule = isset($entry['schedule']) ? $entry['schedule'] : 'daily';
    $defaultTime = '02:00';
    $time = isset($entry['time']) ? $entry['time'] : $defaultTime;
    
    // Validate time format (H:MM or HH:MM) and bounds; fall back to safe default on invalid
    $sh = 2; // default hour from 02:00
    $sm = 0; // default minute from 02:00
    if (is_string($time) && preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        $parts = explode(':', $time);
        $parsedHour = intval($parts[0]);
        $parsedMinute = intval($parts[1]);
        if ($parsedHour >= 0 && $parsedHour <= 23 && $parsedMinute >= 0 && $parsedMinute <= 59) {
            $sh = $parsedHour;
            $sm = $parsedMinute;
        }
    }

    // Calculate today's scheduled timestamp and check if we should run
    // Using last_run tracking to handle non-15-minute-boundary times
    $scheduledToday = mktime($sh, $sm, 0);
    $lastRun = isset($entry['last_run']) ? intval($entry['last_run']) : 0;
    
    $shouldRun = false;
    // Check if current time is past the scheduled time and we haven't run yet today
    if ($now >= $scheduledToday && $lastRun < $scheduledToday) {
        if ($schedule == 'daily') {
            $shouldRun = true;
        } elseif ($schedule == 'weekly') {
            $weekday = isset($entry['weekday']) ? intval($entry['weekday']) : 0; // Default Sunday
            if ($weekday == $wday) $shouldRun = true;
        } elseif ($schedule == 'monthly') {
            $monthday = isset($entry['monthday']) ? intval($entry['monthday']) : 1; // Default 1st
            if ($monthday == $mday) $shouldRun = true;
        }
    }

    if ($shouldRun) {
        // Update last_run timestamp
        $data[$path]['last_run'] = $now;
        $dataModified = true;
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

// Save updated last_run timestamps if any were modified
if ($dataModified) {
    file_put_contents($autofile, json_encode($data, JSON_PRETTY_PRINT));
}

?>
