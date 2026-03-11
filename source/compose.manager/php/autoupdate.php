<?php
require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

$action = isset($_POST['action']) ? $_POST['action'] : '';
$autofile = $plugin_root . "autoupdate.json";

header('Content-Type: application/json');

/**
 * Validate that a path is allowed for auto-update operations.
 * Must be under compose_root, /mnt/, or /boot/config/.
 * @param string $path The path to validate
 * @return bool True if path is allowed
 */
if (!function_exists('isAllowedAutoUpdatePath')) {
function isAllowedAutoUpdatePath($path) {
    global $compose_root;
    $realPath = realpath($path);
    if ($realPath === false) {
        return false;
    }
    // Allow paths under compose_root with proper boundary check
    $realComposeRoot = realpath($compose_root);
    if ($realComposeRoot !== false) {
        $realComposeRoot = rtrim($realComposeRoot, DIRECTORY_SEPARATOR);
        if ($realPath === $realComposeRoot || strpos($realPath, $realComposeRoot . DIRECTORY_SEPARATOR) === 0) {
            return true;
        }
    }
    // Allow indirect paths under /mnt/ or /boot/config/ with boundary checks
    if ($realPath === '/mnt' || strpos($realPath, '/mnt/') === 0) {
        return true;
    }
    if ($realPath === '/boot/config' || strpos($realPath, '/boot/config/') === 0) {
        return true;
    }
    return false;
}
} // end function_exists check

switch ($action) {
    case 'getConfig':
        if (is_file($autofile)) {
            $data = file_get_contents($autofile);
            echo $data;
        } else {
            echo json_encode(array());
        }
        break;
    case 'saveConfig':
        $data = isset($_POST['data']) ? $_POST['data'] : '{}';
        // basic validation
        $arr = json_decode($data, true);
        if ($arr === null && $data !== 'null') {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid JSON'));
            break;
        }
        // Normalize keys: ensure top-level is array
        if (!is_array($arr)) $arr = array();
        // Filter top-level keys: only allow stack paths that pass isAllowedAutoUpdatePath
        // Skip validation for 'defaults' key which stores default settings
        $filtered = array();
        foreach ($arr as $stackPath => $config) {
            if ($stackPath === 'defaults' || (is_string($stackPath) && isAllowedAutoUpdatePath($stackPath))) {
                $filtered[$stackPath] = $config;
            }
        }
        // ensure directory exists (test environment may map plugin_root differently)
        $dir = dirname($autofile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($autofile, json_encode($filtered, JSON_PRETTY_PRINT));
        echo json_encode(array('ok' => true));
        break;
    case 'installCron':
        $cronDirEnv = getenv('COMPOSE_MANAGER_CRON_DIR');
        // If a cron directory is explicitly provided (e.g., in tests), keep legacy file-based behavior.
        if ($cronDirEnv !== false && $cronDirEnv !== '') {
            $cronDir = $cronDirEnv;
            $cronFile = rtrim($cronDir, '/') . '/compose_manager_autoupdate';
            $runner = escapeshellarg($plugin_root . "php/autoupdate_runner.php");
            $line = "*/15 * * * * root php " . $runner . " >/dev/null 2>&1\n";
            // ensure cron directory exists for test environments
            $cdir = dirname($cronFile);
            if (!is_dir($cdir)) mkdir($cdir, 0755, true);
            if (file_put_contents($cronFile, $line) !== false) {
                echo json_encode(array('ok' => true));
            } else {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to write cron file'));
            }
        } else {
            // Default behavior: manage root's crontab with a marker block so it survives reboots.
            $runner = escapeshellarg($plugin_root . "php/autoupdate_runner.php");
            $markerStart = "# COMPOSE_MANAGER_AUTOUPDATE START";
            $markerEnd   = "# COMPOSE_MANAGER_AUTOUPDATE END";
            $cronLine = "*/15 * * * * php " . $runner . " >/dev/null 2>&1";

            $existing = shell_exec('crontab -l 2>/dev/null');
            if ($existing === null) {
                $existing = '';
            }

            // Remove any existing autoupdate block.
            $pattern = '/# COMPOSE_MANAGER_AUTOUPDATE START.*?# COMPOSE_MANAGER_AUTOUPDATE END\s*/s';
            $cleaned = preg_replace($pattern, '', $existing);
            if ($cleaned === null) {
                $cleaned = $existing;
            }

            $newBlock = $markerStart . "\n" . $cronLine . "\n" . $markerEnd . "\n";
            $newCron = trim($cleaned) . "\n" . $newBlock;

            $tmpFile = tempnam(sys_get_temp_dir(), 'cm_cron_');
            if ($tmpFile === false || file_put_contents($tmpFile, rtrim($newCron) . "\n") === false) {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to prepare cron file'));
                break;
            }

            exec('crontab ' . escapeshellarg($tmpFile), $output, $returnVar);
            unlink($tmpFile);

            if ($returnVar === 0) {
                echo json_encode(array('ok' => true));
            } else {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to install cron job'));
            }
        }
        break;
    case 'removeCron':
        $cronDirEnv = getenv('COMPOSE_MANAGER_CRON_DIR');
        if ($cronDirEnv !== false && $cronDirEnv !== '') {
            // Legacy/test behavior: remove cron.d file if it exists.
            $cronDir = $cronDirEnv;
            $cronFile = rtrim($cronDir, '/') . '/compose_manager_autoupdate';
            if (is_file($cronFile)) unlink($cronFile);
            echo json_encode(array('ok' => true));
        } else {
            // Default behavior: remove the marked block from root's crontab.
            $existing = shell_exec('crontab -l 2>/dev/null');
            if ($existing === null) {
                $existing = '';
            }

            $pattern = '/# COMPOSE_MANAGER_AUTOUPDATE START.*?# COMPOSE_MANAGER_AUTOUPDATE END\s*/s';
            $cleaned = preg_replace($pattern, '', $existing);
            if ($cleaned === null) {
                $cleaned = $existing;
            }

            // If nothing changed, we are effectively done.
            if ($cleaned !== $existing) {
                $tmpFile = tempnam(sys_get_temp_dir(), 'cm_cron_');
                if ($tmpFile !== false && file_put_contents($tmpFile, rtrim($cleaned) . "\n") !== false) {
                    exec('crontab ' . escapeshellarg($tmpFile), $output, $returnVar);
                    unlink($tmpFile);
                }
            }
            echo json_encode(array('ok' => true));
        }
        break;
    case 'getCronStatus':
        $cronDirEnv = getenv('COMPOSE_MANAGER_CRON_DIR');
        if ($cronDirEnv !== false && $cronDirEnv !== '') {
            // Legacy/test behavior: check for cron.d file existence.
            $cronDir = $cronDirEnv;
            $cronFile = rtrim($cronDir, '/') . '/compose_manager_autoupdate';
            echo json_encode(array('installed' => is_file($cronFile)));
        } else {
            // Default behavior: check whether the marker is present in root's crontab.
            $existing = shell_exec('crontab -l 2>/dev/null');
            if ($existing === null) {
                $existing = '';
            }
            $installed = (strpos($existing, '# COMPOSE_MANAGER_AUTOUPDATE START') !== false);
            echo json_encode(array('installed' => $installed));
        }
        break;
    case 'runNow':
        $path = isset($_POST['path']) ? $_POST['path'] : '';
        if (!$path) {
            http_response_code(400);
            echo json_encode(array('error' => 'Missing path'));
            break;
        }
        // Security: validate path is under allowed directories
        if (!isAllowedAutoUpdatePath($path)) {
            clientDebug("[autoupdate] Rejected invalid path: $path", null, 'daemon', 'error');
            http_response_code(403);
            echo json_encode(array('error' => 'Path not allowed'));
            break;
        }
        $composeFile = findComposeFile($path);
        if (!$composeFile) {
            http_response_code(404);
            echo json_encode(array('error' => 'Compose file not found'));
            break;
        }
        // Resolve project name - try to find the StackInfo, fall back to basename
        $stackInfo = StackInfo::fromComposePath($compose_root, $path);
        if ($stackInfo !== null) {
            $projectName = $stackInfo->sanitizedName;
        } else {
            $projectName = basename($path);
            if (is_file("$path/name")) {
                $projectName = trim(file_get_contents("$path/name"));
            }
            $projectName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $projectName);
        }

        clientDebug("[autoupdate] Running manual auto-update for: $projectName", null, 'daemon', 'info');

        $script = $plugin_root . "scripts/compose_autoupdate.sh";
        // Allow overriding the shell command via environment for tests; default to sh
        $shCmd = getenv('COMPOSE_MANAGER_SH') ? getenv('COMPOSE_MANAGER_SH') : 'sh';
        $cmd = $shCmd . ' ' . escapeshellarg($script) . " " . escapeshellarg($composeFile) . " " . escapeshellarg($projectName) . " 2>&1";
        exec($cmd, $output, $rc);
        echo json_encode(array('rc' => $rc, 'output' => $output));
        break;
    default:
        http_response_code(400);
        echo json_encode(array('error' => 'Unknown action'));
        break;
}

?>
