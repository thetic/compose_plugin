<?php
require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

$action = isset($_POST['action']) ? $_POST['action'] : '';
$autofile = $plugin_root . "autoupdate.json";

header('Content-Type: application/json');

if (!function_exists('get_compose_file')) {
    function get_compose_file($path) {
        $candidates = array('compose.yml', 'docker-compose.yml', 'compose.yaml', 'docker-compose.yaml');
        foreach ($candidates as $c) {
            if (is_file("$path/$c")) return "$path/$c";
        }
        return null;
    }
}

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
        // ensure directory exists (test environment may map plugin_root differently)
        $dir = dirname($autofile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($autofile, json_encode($arr, JSON_PRETTY_PRINT));
        echo json_encode(array('ok' => true));
        break;
    case 'installCron':
        $cronDir = getenv('COMPOSE_MANAGER_CRON_DIR') ? getenv('COMPOSE_MANAGER_CRON_DIR') : '/etc/cron.d';
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
        break;
    case 'removeCron':
        $cronDir = getenv('COMPOSE_MANAGER_CRON_DIR') ? getenv('COMPOSE_MANAGER_CRON_DIR') : '/etc/cron.d';
        $cronFile = rtrim($cronDir, '/') . '/compose_manager_autoupdate';
        if (is_file($cronFile)) unlink($cronFile);
        echo json_encode(array('ok' => true));
        break;
    case 'getCronStatus':
        $cronDir = getenv('COMPOSE_MANAGER_CRON_DIR') ? getenv('COMPOSE_MANAGER_CRON_DIR') : '/etc/cron.d';
        $cronFile = rtrim($cronDir, '/') . '/compose_manager_autoupdate';
        echo json_encode(array('installed' => is_file($cronFile)));
        break;
    case 'runNow':
        $path = isset($_POST['path']) ? urldecode($_POST['path']) : '';
        if (!$path) {
            http_response_code(400);
            echo json_encode(array('error' => 'Missing path'));
            break;
        }
        $composeFile = get_compose_file($path);
        if (!$composeFile) {
            http_response_code(404);
            echo json_encode(array('error' => 'Compose file not found'));
            break;
        }
        $projectName = basename($path);
        if (is_file("$path/name")) {
            $projectName = trim(file_get_contents("$path/name"));
        }
        $projectName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $projectName);

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
