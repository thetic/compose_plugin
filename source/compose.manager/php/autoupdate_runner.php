<?php
/**
 * Runner invoked by cron to check autoupdate.json and run updates when scheduled
 */
require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");

$autofile = $plugin_root . "autoupdate.json";
if (!is_file($autofile)) exit(0);

$data = json_decode(file_get_contents($autofile), true);
if (!is_array($data)) exit(0);

date_default_timezone_set(@trim(shell_exec('cat /etc/localtime 2>/dev/null || echo UTC')) ?: 'UTC');

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
        // find compose file
        $composeFile = null;
        $candidates = array('compose.yml','docker-compose.yml','compose.yaml','docker-compose.yaml');
        foreach ($candidates as $c) {
            if (is_file($path . '/' . $c)) { $composeFile = $path . '/' . $c; break; }
        }
        if (!$composeFile) continue;

        $projectName = basename($path);
        if (is_file($path . '/name')) $projectName = trim(file_get_contents($path . '/name'));
        $projectName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $projectName);

        $script = $plugin_root . "scripts/compose_autoupdate.sh";
        // Allow overriding the shell command via environment for tests; default to sh
        $shCmd = getenv('COMPOSE_MANAGER_SH') ? getenv('COMPOSE_MANAGER_SH') : 'sh';
        $cmd = $shCmd . ' ' . escapeshellarg($script) . " " . escapeshellarg($composeFile) . " " . escapeshellarg($projectName) . " >/dev/null 2>&1 &";
        exec($cmd);
    }
}

?>
