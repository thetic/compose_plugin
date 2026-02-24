<?PHP

/**
 * Async stack list loader for Compose Manager
 * This file is called via AJAX to load the stack list without blocking page load
 */

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");
// Note: helper functions removed; test suite provides any needed test-local helpers.

$cfg = parse_plugin_cfg($sName);

// Get stack state
$stackstate = shell_exec($plugin_root . "/scripts/compose.sh -c list");
$stackstate = json_decode($stackstate, TRUE);

// Get all compose containers with status/uptime info
$containersOutput = shell_exec($plugin_root . "/scripts/compose.sh -c ps");
$containersByProject = [];
if ($containersOutput) {
    $lines = explode("\n", trim($containersOutput));
    foreach ($lines as $line) {
        if (!empty($line)) {
            $container = json_decode($line, true);
            if ($container && isset($container['Labels'])) {
                // Extract project name from labels
                if (preg_match('/com\.docker\.compose\.project=([^,]+)/', $container['Labels'], $matches)) {
                    $projectName = $matches[1];
                    if (!isset($containersByProject[$projectName])) {
                        $containersByProject[$projectName] = [];
                    }
                    $containersByProject[$projectName][] = $container;
                }
            }
        }
    }
}

$composeProjects = @array_diff(@scandir($compose_root), array(".", ".."));
if (! is_array($composeProjects)) {
    $composeProjects = array();
}

$o = "";
$stackCount = 0;

foreach ($composeProjects as $project) {
    // Skip if not a directory or if it doesn't contain a compose file (either directly or via indirect)
    if (!hasComposeFile("$compose_root/$project") &&
        (! is_file("$compose_root/$project/indirect"))
    ) {
        continue;
    }

    $stackCount++;

    $projectName = $project;
    if (is_file("$compose_root/$project/name")) {
        $projectName = trim(file_get_contents("$compose_root/$project/name"));
    }
    $id = str_replace(".", "-", $project);
    $id = str_replace(" ", "", $id);

    // Get the compose file path
    $basePath = is_file("$compose_root/$project/indirect")
        ? trim(file_get_contents("$compose_root/$project/indirect"))
        : "$compose_root/$project";
    $composeFile = findComposeFile($basePath) ?: "$basePath/docker-compose.yml";
    // Resolve override via centralized helper (prefer correctly-named indirect override)
    require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");
    $overridePath = OverrideInfo::fromStack($compose_root, $project)->getOverridePath();

    // Use docker compose config --services to get accurate service count
    // This properly parses YAML, handles overrides, extends, etc.
    $definedServices = 0;
    if (is_file($composeFile)) {
        $files = "-f " . escapeshellarg($composeFile);
        if (is_file($overridePath)) {
            $files .= " -f " . escapeshellarg($overridePath);
        }

        // Get env file if specified
        $envFile = "";
        if (is_file("$compose_root/$project/envpath")) {
            $envPath = trim(file_get_contents("$compose_root/$project/envpath"));
            if (is_file($envPath)) {
                $envFile = "--env-file " . escapeshellarg($envPath);
            }
        }

        // Use docker compose config --services to list all service names
        $cmd = "docker compose $files $envFile config --services 2>/dev/null";
        $output = shell_exec($cmd);
        if ($output) {
            $services = array_filter(explode("\n", trim($output)));
            $definedServices = count($services);
        }
    }

    // Get running container info from $containersByProject
    $sanitizedProjectName = sanitizeStr($projectName);
    $projectContainers = $containersByProject[$sanitizedProjectName] ?? [];
    $runningCount = 0;
    $stoppedCount = 0;
    $pausedCount = 0;
    $restartingCount = 0;

    foreach ($projectContainers as $ct) {
        $ctState = $ct['State'] ?? '';
        if ($ctState === 'running') {
            $runningCount++;
        } elseif ($ctState === 'exited') {
            $stoppedCount++;
        } elseif ($ctState === 'paused') {
            $pausedCount++;
        } elseif ($ctState === 'restarting') {
            $restartingCount++;
        }
    }

    // Container counts
    $actualContainerCount = count($projectContainers);
    $containerCount = $definedServices > 0 ? $definedServices : $actualContainerCount;

    // Collect container names for the hide-from-docker feature (data attribute)
    $containerNamesList = [];
    foreach ($projectContainers as $ct) {
        $n = $ct['Names'] ?? '';
        if ($n) $containerNamesList[] = $n;
    }
    $containerNamesAttr = htmlspecialchars(json_encode($containerNamesList), ENT_QUOTES, 'UTF-8');

    // Determine states
    $isrunning = $runningCount > 0;
    $isexited = $stoppedCount > 0;
    $ispaused = $pausedCount > 0;
    $isrestarting = $restartingCount > 0;
    $isup = $actualContainerCount > 0;

    if (is_file("$compose_root/$project/description")) {
        $description = @file_get_contents("$compose_root/$project/description");
        $description = str_replace("\r", "", $description);
        // Escape HTML first to prevent XSS, then convert newlines to <br>
        $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $description = str_replace("\n", "<br>", $description);
    } else {
        $description = "";
    }

    $autostart = '';
    if (is_file("$compose_root/$project/autostart")) {
        $autostarttext = @file_get_contents("$compose_root/$project/autostart");
        if (strpos($autostarttext, 'true') !== false) {
            $autostart = 'checked';
        }
    }

    // Check for custom project icon (URL-based only via icon_url file)
    $projectIcon = '';
    if (is_file("$compose_root/$project/icon_url")) {
        $iconUrl = trim(@file_get_contents("$compose_root/$project/icon_url"));
        if (filter_var($iconUrl, FILTER_VALIDATE_URL) && (strpos($iconUrl, 'http://') === 0 || strpos($iconUrl, 'https://') === 0)) {
            $projectIcon = $iconUrl;
        }
    }

    // Check for stack-level WebUI URL
    $webuiUrl = '';
    if (is_file("$compose_root/$project/webui_url")) {
        $webuiUrlTmp = trim(@file_get_contents("$compose_root/$project/webui_url"));
        if (filter_var($webuiUrlTmp, FILTER_VALIDATE_URL) && (strpos($webuiUrlTmp, 'http://') === 0 || strpos($webuiUrlTmp, 'https://') === 0)) {
            $webuiUrl = $webuiUrlTmp;
        }
    }

    $profiles = array();
    if (is_file("$compose_root/$project/profiles")) {
        $profilestext = @file_get_contents("$compose_root/$project/profiles");
        $profiles = json_decode($profilestext, false);
    }
    $profilesJson = htmlspecialchars(json_encode($profiles ? $profiles : []), ENT_QUOTES, 'UTF-8');

    // Determine status text and class for badge
    $statusText = "Stopped";
    $statusClass = "status-stopped";
    if ($isup) {
        if ($isexited && !$isrunning) {
            $statusText = "Exited";
            $statusClass = "status-exited";
        } elseif ($isrunning && !$isexited && !$ispaused && !$isrestarting) {
            $statusText = "Running";
            $statusClass = "status-running";
        } elseif ($ispaused && !$isexited && !$isrunning && !$isrestarting) {
            $statusText = "Paused";
            $statusClass = "status-paused";
        } elseif ($ispaused && !$isexited) {
            $statusText = "Partial";
            $statusClass = "status-partial";
        } elseif ($isrestarting) {
            $statusText = "Restarting";
            $statusClass = "status-restarting";
        } else {
            $statusText = "Mixed";
            $statusClass = "status-mixed";
        }
    }

    // Escape for HTML output
    $projectNameHtml = htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8');
    $projectHtml = htmlspecialchars($project, ENT_QUOTES, 'UTF-8');
    $descriptionHtml = $description; // Already contains <br> tags from earlier processing
    $pathHtml = htmlspecialchars("$compose_root/$project", ENT_QUOTES, 'UTF-8');
    $projectIconUrl = htmlspecialchars($projectIcon, ENT_QUOTES, 'UTF-8');

    // Status like Docker tab (started/stopped with icon)
    $status = $isrunning ? ($runningCount == $containerCount ? 'started' : 'partial') : 'stopped';
    // Use exclamation icon for partial state so it looks like a warning
    if ($status === 'partial') {
        $shape = 'exclamation-circle';
    } elseif ($isrunning) {
        $shape = 'play';
    } else {
        $shape = 'square';
    }
    $color = $status == 'started' ? 'green-text' : ($status == 'partial' ? 'orange-text' : 'grey-text');
    // Use 'partial' outer class for partial state to allow correct styling
    $outerClass = $isrunning ? ($runningCount == $containerCount ? 'started' : 'partial') : 'stopped';

    $statusLabel = $status;
    if ($status == 'partial') {
        $statusLabel = "partial ($runningCount/$containerCount)";
    }

    // Get stack started_at timestamp from file for uptime calculation
    $stackStartedAt = '';
    if (is_file("$compose_root/$project/started_at")) {
        $stackStartedAt = trim(file_get_contents("$compose_root/$project/started_at"));
    }

    // Calculate uptime display from started_at timestamp
    $stackUptime = '';
    if ($stackStartedAt && $isrunning) {
        $startTime = strtotime($stackStartedAt);
        if ($startTime) {
            $diffSecs = time() - $startTime;
            $mins = floor($diffSecs / 60);
            $hours = floor($diffSecs / 3600);
            $days = floor($diffSecs / 86400);
            $weeks = floor($days / 7);
            $months = floor($days / 30);
            $years = floor($days / 365);

            if ($mins < 120) {
                $stackUptime = $mins . " min" . ($mins !== 1 ? "s" : "");
            } elseif ($hours < 48) {
                $stackUptime = $hours . " hour" . ($hours !== 1 ? "s" : "");
            } elseif ($days < 14) {
                $stackUptime = $days . " day" . ($days !== 1 ? "s" : "");
            } elseif ($weeks < 8) {
                $stackUptime = $weeks . " week" . ($weeks !== 1 ? "s" : "");
            } elseif ($months < 24) {
                $stackUptime = $months . " month" . ($months !== 1 ? "s" : "");
            } else {
                $stackUptime = $years . " year" . ($years !== 1 ? "s" : "");
            }
        }
    }
    if (!$stackUptime && $isrunning) {
        $stackUptime = "Uptime: running";
    } elseif (!$stackUptime) {
        $stackUptime = "stopped";
    }

    // Escape webui URL for HTML attribute
    $webuiUrlHtml = htmlspecialchars($webuiUrl, ENT_QUOTES, 'UTF-8');

    // Main row - Docker tab structure with expand arrow on left
    $o .= "<tr class='compose-sortable' id='stack-row-$id' data-project='$projectHtml' data-projectname='$projectNameHtml' data-path='$pathHtml' data-isup='$isup' data-profiles='$profilesJson' data-webui='$webuiUrlHtml' data-containers='$containerNamesAttr'>";

    // Name column: expand arrow, then icon with context menu, then name
    $o .= "<td class='ct-name' style='padding:8px 8px 8px 20px'>";
    // Expand arrow on the left (separate from the outer/inner structure)
    $o .= "<span style='display:inline-block;width:14px;text-align:left;vertical-align:middle;margin-right:8px;'>";
    $o .= "<i class='fa fa-chevron-right expand-icon' id='expand-icon-$id' onclick='toggleStackDetails(\"$id\");event.stopPropagation();' style='cursor:pointer;'></i>";
    $o .= "</span>";
    // Icon and name using Docker's outer/inner structure
    $o .= "<span class='outer $outerClass'>";
    $o .= "<span id='stack-$id' class='hand' data-stackid='$id' data-project='$projectHtml' data-projectname='$projectNameHtml' data-isup='$isup' data-running='" . ($isrunning ? '1' : '0') . "'>";
    // Use actual image - either custom icon URL or default question.png like Docker tab
    $imgSrc = $projectIconUrl ?: '/plugins/dynamix.docker.manager/images/question.png';
    $o .= "<img src='$imgSrc' class='img' onerror=\"this.src='/plugins/dynamix.docker.manager/images/question.png';\">";
    $o .= "</span>";
    $o .= "<span class='inner'><span class='appname'>$projectNameHtml</span><br>";
    // Add data-status attribute to the icon to aid debugging of initial render state
    $o .= "<i class='fa fa-$shape $status $color compose-status-icon' data-status='$status'></i><span class='state'>$statusLabel</span>";
    // Advanced: show project folder
    $o .= "<div class='cm-advanced' style='margin-top:4px;font-size:0.85em;color:#888;'>";
    $o .= "Project: $projectHtml";
    $o .= "</div>";
    $o .= "</span></span>";
    $o .= "</td>";

    // Update column (like Docker tab) - default to "not checked" until update check runs
    $o .= "<td class='compose-updatecolumn'>";
    if ($isrunning) {
        $o .= "<span class='grey-text' style='white-space:nowrap;cursor:default;' title='Click Check for Updates to check'><i class='fa fa-question-circle fa-fw'></i> not checked</span>";
    } else {
        $o .= "<span class='grey-text' style='white-space:nowrap;'><i class='fa fa-stop fa-fw'></i> stopped</span>";
    }
    $o .= "</td>";

    // Containers column (shows running/total)
    $containersDisplay = $isrunning ? "$runningCount / $containerCount" : "0 / $containerCount";
    $containersClass = ($runningCount == $containerCount && $runningCount > 0) ? 'green-text' : ($runningCount > 0 ? 'orange-text' : 'grey-text');
    $o .= "<td><span class='$containersClass'>$containersDisplay</span></td>";

    // Uptime column (both basic and advanced views)
    $uptimeDisplay = $stackUptime;
    $uptimeClass = $isrunning ? 'green-text' : 'grey-text';
    $o .= "<td><span class='$uptimeClass'>$uptimeDisplay</span></td>";

    // Description column (advanced only)
    $o .= "<td class='cm-advanced' style='overflow-wrap:break-word;word-wrap:break-word;'><span class='docker_readmore'>$descriptionHtml</span></td>";

    // Path column (advanced only)
    $o .= "<td class='cm-advanced' style='color:#606060;font-size:12px;'>$pathHtml</td>";

    // Auto Start toggle
    $o .= "<td class='nine'>";
    $o .= "<input type='checkbox' class='auto_start' data-scriptName='$projectHtml' id='autostart-$id' $autostart>";
    $o .= "</td>";

    $o .= "</tr>";

    // Expandable details row
    $o .= "<tr class='stack-details-row' id='details-row-$id' style='display:none;'>";
    $o .= "<td colspan='9' class='stack-details-cell' style='padding:0 0 0 60px;background:rgba(0,0,0,0.05);'>";
    $o .= "<div class='stack-details-container' id='details-container-$id' style='padding:8px 16px;'>";
    $o .= "<i class='fa fa-spinner fa-spin compose-spinner'></i> Loading containers...";
    $o .= "</div>";
    $o .= "</td>";
    $o .= "</tr>";
}

// If no stacks found, show a message
if ($stackCount === 0) {
    $o = "<tr><td colspan='7' style='text-align:center;padding:20px;color:#888;'>No Docker Compose stacks found. Click 'Add New Stack' to create one.</td></tr>";
}

// Output the HTML
echo $o;
