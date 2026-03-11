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
 * Execute a compose command in a ttyd terminal, optionally capturing output to a log file.
 *
 * @param string $cmd The command to execute
 * @param bool $debug Whether to log debug messages
 * @param string $logFile Optional path to write a copy of the output
 */
function execComposeCommandInTTY($cmd, $debug, $logFile = '')
{
    global $socket_name;
    // Use pkill -f for more robust process matching instead of pgrep|awk pipeline
    exec("pkill -f " . escapeshellarg("$socket_name.sock") . " 2>/dev/null");
    usleep(300000); // 300ms for process to exit
    @unlink("/var/tmp/$socket_name.sock");
    $socketPath = escapeshellarg("/var/tmp/$socket_name.sock");
    if ($logFile !== '') {
        // Preserve interactive TTY behavior by running the command under "script"
        // (PTY capture). A plain tee pipeline breaks terminal redraw/spinner output.
        $scriptCmd = "script -qefc " . escapeshellarg($cmd) . " " . escapeshellarg($logFile);
        $innerCmd = "bash -lc " . escapeshellarg($scriptCmd);
        $command = "ttyd -R -o -i $socketPath $innerCmd > /dev/null &";
    } else {
        $command = "ttyd -R -o -i $socketPath $cmd > /dev/null &";
    }
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
 * @param bool $background Whether to run in the background (no terminal window; sends notification on finish)
 */
function echoComposeCommand($action, $recreate = false, $background = false)
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

        // Resolve stack identity via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $project);

        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . $stackInfo->sanitizedName;

        $composeFile = $stackInfo->composeFilePath ?? ($stackInfo->composeSource . '/' . COMPOSE_FILE_NAMES[0]);
        $composeCommand[] = "-f$composeFile";

        // Prune orphaned services from override before compose up
        if ($action === 'up') {
            $stackInfo->pruneOrphanOverrideServices();
        }

        $composeCommand[] = "-f" . $stackInfo->getOverridePath();

        $envFilePath = $stackInfo->getEnvFilePath();
        if ($envFilePath !== null) {
            $composeCommand[] = "-e" . $envFilePath;
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

        if ($background) {
            // Run fully in the background using compose_background.sh.
            // Output is captured to last_cmd.log; notification sent on completion.
            $bgScript = $plugin_root . "scripts/compose_background.sh";
            $bgCmd = escapeshellarg($bgScript);
            foreach ($composeCommand as $arg) {
                $bgCmd .= ' ' . escapeshellarg($arg);
            }
            $bgCmd .= ' > /dev/null 2>&1 &';
            exec($bgCmd);
            if ($debug) {
                logger("Background command: " . $bgCmd);
            }
            // Signal to JS that this ran in background (no terminal window to open)
            echo json_encode(['background' => true]);
        } elseif ($cfg['OUTPUTSTYLE'] == "ttyd") {
            $logFile = $path . '/last_cmd.log';
            $composeCommandEscaped = array_map(function ($item) {
                return escapeshellarg($item);
            }, $composeCommand);
            $composeCommandStr = join(" ", $composeCommandEscaped);
            execComposeCommandInTTY($composeCommandStr, $debug, $logFile);
            if ($debug) {
                logger($composeCommandStr);
            }
            $composeCommand = "/plugins/compose.manager/php/show_ttyd.php" . ($originalAction !== 'logs' ? "?done=1" : "");
            echo $composeCommand;
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
            echo $composeCommand;
        }
        if ($debug) {
            logger((string)$composeCommand);
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

        $project = basename($path);

        // Resolve stack identity via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $project);
        $stackNames[] = $stackInfo->getName();

        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . $stackInfo->sanitizedName;

        $composeFile = $stackInfo->composeFilePath ?? ($stackInfo->composeSource . '/' . COMPOSE_FILE_NAMES[0]);
        $composeCommand[] = "-f$composeFile";

        // Prune orphaned services from override before compose up
        if ($action === 'up') {
            $stackInfo->pruneOrphanOverrideServices();
        }

        $composeCommand[] = "-f" . $stackInfo->getOverridePath();

        // Add env-file if available for this stack
        $envFilePath = $stackInfo->getEnvFilePath();
        if ($envFilePath !== null) {
            $composeCommand[] = "-e" . $envFilePath;
        }

        // Add default profiles for multi-stack operations
        $defaultProfiles = $stackInfo->getDefaultProfiles();
        foreach ($defaultProfiles as $p) {
            $composeCommand[] = "-g $p";
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
