<?php

/**
 * Compose Util Functions for Compose Manager
 *
 * Contains utility functions used by compose_util.php for compose command execution.
 * Separated from compose_util.php to allow unit testing without triggering the switch statement.
 */

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

/**
 * Log a message to syslog.
 *
 * @param string $string The message to log
 */
if (!function_exists('logger')) {
    function logger($string)
    {
        $string = escapeshellarg($string);
        exec("logger -t 'compose.manager' " . $string);
    }
}

/**
 * Execute a compose command in a ttyd terminal.
 *
 * @param string $cmd The command to execute
 * @param bool $debug Whether to log debug messages
 */
function execComposeCommandInTTY($cmd, $debug)
{
    global $socket_name;
    // Use pkill -f for more robust process matching instead of pgrep|awk pipeline
    exec("pkill -f " . escapeshellarg("$socket_name.sock") . " 2>/dev/null");
    usleep(300000); // 300ms for process to exit
    @unlink("/var/tmp/$socket_name.sock");
    $socketPath = escapeshellarg("/var/tmp/$socket_name.sock");
    $command = "ttyd -R -o -i $socketPath $cmd > /dev/null &";
    exec($command);
    if ($debug) {
        logger($command);
    }
}

/**
 * Build and echo a compose command for a single stack.
 *
 * @param string $action The compose action (up, down, update, pull, stop, logs)
 * @param bool $recreate Whether to force recreate containers (adds --force-recreate flag)
 */
function echoComposeCommand($action, $recreate = false)
{
    global $plugin_root;
    global $sName;
    global $compose_root;
    $cfg = parse_plugin_cfg($sName);
    $debug = $cfg['DEBUG_TO_LOG'] == "true";
    $path = isset($_POST['path']) ? trim($_POST['path']) : "";
    $profile = isset($_POST['profile']) ? trim($_POST['profile']) : "";
    $unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");
    $originalAction = $action;
    if ($unRaidVars['mdState'] != "STARTED") {
        echo $plugin_root . "/scripts/arrayNotStarted.sh";
        clientDebug("Array Not Started", null, 'daemon', 'debug');
    } else {
        $composeCommand = array($plugin_root . "scripts/compose.sh");

        $project = basename($path);
        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . sanitizeStr($project);

        if (isIndirect($path)) {
            $indirectPath = getPath($path);
            $found = findComposeFile($indirectPath);
            $composeFile = $found ?: "$indirectPath/" . COMPOSE_FILE_NAMES[0];
            $composeCommand[] = "-f$composeFile";
        } else {
            $found = findComposeFile($path);
            $composeFile = $found ?: "$path/" . COMPOSE_FILE_NAMES[0];
            $composeCommand[] = "-f$composeFile";
        }

        // Resolve override using centralized helper
        $overridePath = OverrideInfo::fromStack($compose_root, $project)->getOverridePath();
        $composeCommand[] = "-f" . $overridePath;

        if (is_file("$path/envpath")) {
            $envPath = "-e" . trim(file_get_contents("$path/envpath"));
            $composeCommand[] = $envPath;
        }

        // Support multiple profiles (comma-separated)
        if ($profile) {
            $profileList = array_map('trim', explode(',', $profile));
            foreach ($profileList as $p) {
                if ($p) {
                    $composeCommand[] = "-g $p";
                }
            }
        }

        // Pass stack path for timestamp saving
        $composeCommand[] = "-s$path";

        // Add recreate flag if requested
        if ($recreate) {
            $composeCommand[] = "--recreate";
        }

        if ($debug) {
            $composeCommand[] = "--debug";
        }

        if ($cfg['OUTPUTSTYLE'] == "ttyd") {
            $composeCommand = array_map(function ($item) {
                return escapeshellarg($item);
            }, $composeCommand);
            $composeCommand = join(" ", $composeCommand);
            execComposeCommandInTTY($composeCommand, $debug);
            if ($debug) {
                logger($composeCommand);
            }
            $composeCommand = "/plugins/compose.manager/php/show_ttyd.php" . ($originalAction !== 'logs' ? "?done=1" : "");
        } else {
            $i = 0;
            $composeCommand = array_reduce($composeCommand, function ($v1, $v2) use (&$i) {
                if ($v2[0] == "-") {
                    $i++; // increment $i
                    return $v1 . "&arg" . $i . "=" . $v2;
                } else {
                    return $v1 . $v2;
                }
            }, "");
        }

        echo $composeCommand;
        if ($debug) {
            logger($composeCommand);
        }
    }
}

/**
 * Build and echo a compose command for multiple stacks.
 *
 * @param string $action The compose action (up, down, update)
 * @param array $paths Array of stack paths
 */
function echoComposeCommandMultiple($action, $paths)
{
    global $plugin_root;
    global $sName;
    global $compose_root;
    $cfg = parse_plugin_cfg($sName);
    $debug = $cfg['DEBUG_TO_LOG'] == "true";
    $unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");

    if ($unRaidVars['mdState'] != "STARTED") {
        echo $plugin_root . "/scripts/arrayNotStarted.sh";
        if ($debug) {
            logger("Array not Started!");
        }
        return;
    }

    // Build a combined command that runs compose up/down for each stack sequentially
    $commands = array();
    $stackNames = array();

    foreach ($paths as $path) {
        $composeCommand = array($plugin_root . "scripts/compose.sh");

        $projectName = basename($path);
        $project = basename($path);
        if (is_file("$path/name")) {
            $projectName = trim(file_get_contents("$path/name"));
        }
        $stackNames[] = $projectName;

        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . sanitizeStr($project);

        if (isIndirect($path)) {
            // For indirect paths, resolve the target path and then locate the compose file
            $indirectPath = getPath($path);
            $found = findComposeFile($indirectPath);
            $composeFile = $found ?: "$indirectPath/" . COMPOSE_FILE_NAMES[0];
            $composeCommand[] = "-f$composeFile";
        } else {
            $found = findComposeFile($path);
            $composeFile = $found ?: "$path/" . COMPOSE_FILE_NAMES[0];
            $composeCommand[] = "-f$composeFile";
        }

        // Resolve override using centralized helper
        $overridePath = OverrideInfo::fromStack($compose_root, $projectName)->getOverridePath();
        $composeCommand[] = "-f" . $overridePath;

        // Add env-file if available for this stack
        if (is_file("$path/envpath")) {
            $envPath = "-e" . trim(file_get_contents("$path/envpath"));
            $composeCommand[] = $envPath;
        }

        // Add default profiles for multi-stack operations
        if (is_file("$path/default_profile")) {
            $defaultProfiles = trim(file_get_contents("$path/default_profile"));
            if ($defaultProfiles) {
                // Support comma-separated profiles
                $profileList = array_map('trim', explode(',', $defaultProfiles));
                foreach ($profileList as $p) {
                    if ($p) {
                        $composeCommand[] = "-g $p";
                    }
                }
            }
        }

        // Pass stack path for timestamp saving
        $composeCommand[] = "-s$path";

        if ($debug) {
            $composeCommand[] = "--debug";
        }

        $commands[] = $composeCommand;
    }

    if ($cfg['OUTPUTSTYLE'] == "ttyd") {
        // Build a bash script that runs all commands sequentially
        $bashScript = "bash -c '";
        $first = true;
        foreach ($commands as $idx => $cmd) {
            $cmdStr = implode(" ", array_map('escapeshellarg', $cmd));
            if (!$first) $bashScript .= " && ";
            $bashScript .= "echo \"\" && echo \"=== Starting: " . addslashes($stackNames[$idx]) . " ===\" && echo \"\" && " . $cmdStr;
            $first = false;
        }
        $bashScript .= "'";

        execComposeCommandInTTY($bashScript, $debug);
        clientDebug("Multi-stack command: " . $bashScript, null, 'daemon', 'debug');
        echo "/plugins/compose.manager/php/show_ttyd.php?done=1";
    } else {
        // For nchan/traditional output, create a temporary bash script that runs all commands
        $tmpScript = "/tmp/compose_multi_" . uniqid() . ".sh";
        $scriptContent = "#!/bin/bash\n";
        $scriptContent .= "# Multi-stack compose script - auto-generated\n\n";

        foreach ($commands as $idx => $cmd) {
            $cmdStr = implode(" ", array_map('escapeshellarg', $cmd));
            $scriptContent .= "echo \"\"\n";
            $scriptContent .= "echo \"========================================\"\n";
            $scriptContent .= "echo \"=== " . str_replace('"', '\\"', $stackNames[$idx]) . " ===\"\n";
            $scriptContent .= "echo \"========================================\"\n";
            $scriptContent .= "echo \"\"\n";
            $scriptContent .= "$cmdStr\n";
            $scriptContent .= "echo \"\"\n";
        }

        // Add cleanup at the end
        $scriptContent .= "\necho \"\"\n";
        $scriptContent .= "echo \"========================================\"\n";
        $scriptContent .= "echo \"=== All operations complete ===\"\n";
        $scriptContent .= "echo \"========================================\"\n";
        $scriptContent .= "rm -f " . escapeshellarg($tmpScript) . "\n";

        file_put_contents($tmpScript, $scriptContent);
        chmod($tmpScript, 0755);

        clientDebug("Multi-stack script created: $tmpScript", null, 'daemon', 'debug');

        echo $tmpScript;
    }
}
