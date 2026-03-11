<?php

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

/**
 * Utility functions for Compose Manager
 *
 * This file contains shared utility functions used across the Compose Manager plugin.
 * Functions include logging, string sanitization, path handling, and stack operation locking.
 *
 * These functions are designed to be reusable and testable. They are used by both the
 * main plugin code and the AJAX action handlers.
 */

function clientDebug($message, $data = null, $type = 'daemon', $level = 'info')
{
    if ($type == '' || $type == null) {
        $type = 'daemon';
    }
    switch ($level) {
        case 'debug':
            $logLevel = "$type.debug";
            break;
        case 'error':
        case 'err':
            $logLevel = "$type.err";
            break;
        case 'warning':
        case 'warn':
            $logLevel = "$type.warning";
            break;
        case 'info':
        default:
            $logLevel = "$type.info";
    }
    $cfg = @parse_ini_file("/boot/config/plugins/compose.manager/compose.manager.cfg", true, INI_SCANNER_RAW);
    // Skip debug messages if debug logging is disabled in plugin settings
    if ((($cfg['DEBUG_TO_LOG'] ?? 'false') == 'false') && $level == 'debug') {
        return;
    }
    if ($data !== null && $data !== '' && $data !== 'null') {
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data);
        }
        exec("logger -t 'compose.manager' -p '$logLevel' " . escapeshellarg($message) . ' - Data: ' . escapeshellarg($data));
    } else {
        exec("logger -t 'compose.manager' -p '$logLevel' " . escapeshellarg($message));
    }
}

function sanitizeStr($a)
{
    $a = str_replace(".", "_", $a);
    $a = str_replace(" ", "_", $a);
    $a = str_replace("-", "_", $a);
    return strtolower($a);
}

function sanitizeLogText(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Sanitize a stack name to create a safe folder name.
 * Removes special characters that could cause issues in paths,
 * including path separators and traversal sequences to prevent
 * directory escape attacks.
 *
 * @param string $stackName The stack name to sanitize
 * @return string The sanitized folder name
 */
function sanitizeFolderName(string $stackName): string
{
    $folderName = str_replace('"', '', $stackName);
    $folderName = str_replace("'", '', $folderName);
    $folderName = str_replace('&', '', $folderName);
    $folderName = str_replace('(', '', $folderName);
    $folderName = str_replace(')', '', $folderName);
    // Remove path separators and traversal sequences
    $folderName = str_replace(['/', '\\', '..'], '', $folderName);
    $folderName = preg_replace('/ {2,}/', ' ', $folderName);
    $folderName = preg_replace('/\s/', '_', $folderName);
    return $folderName;
}

function isIndirect($path)
{
    return is_file("$path/indirect");
}

function getPath($basePath)
{
    $outPath = $basePath;
    if (isIndirect($basePath)) {
        $outPath = file_get_contents("$basePath/indirect");
    }

    return $outPath;
}

/**
 * Compose file names in priority order per the Docker Compose spec.
 * @see https://docs.docker.com/compose/intro/compose-application-model/#the-compose-file
 */
define('COMPOSE_FILE_NAMES', [
    'compose.yaml',
    'compose.yml',
    'docker-compose.yaml',
    'docker-compose.yml',
]);

/**
 * Find the compose file in a directory using Docker Compose spec priority.
 *
 * Checks for compose.yaml, compose.yml, docker-compose.yaml, docker-compose.yml
 * in that order and returns the first one found.
 *
 * @param string $dir The directory to search in
 * @return string|false The full path to the compose file, or false if none found
 */
function findComposeFile($dir)
{
    foreach (COMPOSE_FILE_NAMES as $name) {
        if (is_file("$dir/$name")) {
            return "$dir/$name";
        }
    }
    return false;
}

/**
 * Check whether a stack directory has a compose file (any of the supported names).
 *
 * @param string $dir The directory to check
 * @return bool
 */
function hasComposeFile($dir)
{
    return findComposeFile($dir) !== false;
}

/**
 * Resolve the config file path used by auto-update.
 *
 * Prefers a persistent path under /boot/config when available and writable,
 * with environment override support for tests.
 *
 * @return string
 */
function getAutoUpdateConfigFilePath(): string
{
    global $plugin_root;

    $override = getenv('COMPOSE_MANAGER_AUTOUPDATE_FILE');
    if ($override !== false && $override !== '') {
        return $override;
    }

    $persistentPath = '/boot/config/plugins/compose.manager/autoupdate.json';
    $persistentDir  = dirname($persistentPath);

    // Prefer a persistent, writable location under /boot/config when possible.
    if ((is_dir('/boot/config') || is_dir($persistentDir) || @mkdir($persistentDir, 0755, true)) && is_dir($persistentDir)) {
        if (is_writable($persistentDir)) {
            // If the file already exists but is not writable, fall back to plugin_root.
            if (!file_exists($persistentPath) || is_writable($persistentPath)) {
                return $persistentPath;
            }
        }
    }

    return rtrim($plugin_root ?? '', '/') . '/autoupdate.json';
}

/**
 * Validate that a path is allowed for auto-update operations.
 * Must be under compose_root, /mnt/, or /boot/config/.
 *
 * @param string $path The path to validate
 * @return bool
 */
function isAllowedAutoUpdatePath($path): bool
{
    global $compose_root;

    $realPath = realpath($path);
    if ($realPath === false) {
        return false;
    }

    $realComposeRoot = realpath($compose_root);
    if ($realComposeRoot !== false) {
        $realComposeRoot = rtrim($realComposeRoot, DIRECTORY_SEPARATOR);
        if ($realPath === $realComposeRoot || strpos($realPath, $realComposeRoot . DIRECTORY_SEPARATOR) === 0) {
            return true;
        }
    }

    if ($realPath === '/mnt' || strpos($realPath, '/mnt/') === 0) {
        return true;
    }
    if ($realPath === '/boot/config' || strpos($realPath, '/boot/config/') === 0) {
        return true;
    }

    return false;
}


function pruneOverrideContentServices(string $overrideContent, array $validServices): array
{
    $validMap = [];
    foreach ($validServices as $service) {
        if (is_string($service) && $service !== '') {
            $validMap[$service] = true;
        }
    }

    if (empty($validMap) || $overrideContent === '') {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    $lineEnding = (strpos($overrideContent, "\r\n") !== false) ? "\r\n" : "\n";
    $normalized = str_replace(["\r\n", "\r"], "\n", $overrideContent);
    $hadTrailingNewline = substr($normalized, -1) === "\n";
    $lines = explode("\n", $normalized);

    $servicesStart = null;
    $servicesEnd = count($lines);

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        if ($servicesStart === null) {
            if (preg_match('/^services\s*:/', $line)) {
                $servicesStart = $i;
            }
            continue;
        }

        // Detect the end of the services section by finding the next top-level YAML key:
        // - a non-indented, non-comment line that looks like "key:" (optionally followed by a comment)
        // - excluding the "services:" key itself
        if (preg_match('/^[^\s#][^:]*:\s*(?:#.*)?$/', $line) && !preg_match('/^services\s*:/', $line)) {
            $servicesEnd = $i;
            break;
        }
    }

    if ($servicesStart === null || $servicesStart + 1 >= $servicesEnd) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    $serviceRanges = [];
    $currentServiceName = null;
    $currentServiceStart = null;

    for ($i = $servicesStart + 1; $i < $servicesEnd; $i++) {
        $line = $lines[$i];
        // Match a single service definition line under `services:` with:
        // - exactly two spaces of indentation
        // - an optional quote character (single or double) around the service name (captured in group 1)
        // - a service name that cannot start with or contain quotes, colon, hash, or whitespace
        // - a trailing colon after the service name, optional whitespace, and an optional inline `# comment`
        if (preg_match('/^ {2}(["\']?)([^"\':#\s][^"\':#]*)\1\s*:\s*(?:#.*)?$/', $line, $matches)) {
            if ($currentServiceName !== null) {
                $serviceRanges[] = [
                    'name' => $currentServiceName,
                    'start' => $currentServiceStart,
                    'end' => $i - 1
                ];
            }
            $currentServiceName = trim($matches[2]);
            $currentServiceStart = $i;
        }
    }

    if ($currentServiceName !== null) {
        $serviceRanges[] = [
            'name' => $currentServiceName,
            'start' => $currentServiceStart,
            'end' => $servicesEnd - 1
        ];
    }

    if (empty($serviceRanges)) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    $removedRanges = [];
    $removedServices = [];
    foreach ($serviceRanges as $range) {
        if (!isset($validMap[$range['name']])) {
            $removedRanges[] = $range;
            $removedServices[] = $range['name'];
        }
    }

    if (empty($removedRanges)) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    // Safety check: if ALL services in the override would be removed, don't prune.
    // This likely indicates a rename scenario rather than genuine orphans.
    // Wiping to "services: {}" would destroy user data (icons, webui labels, etc).
    if (count($removedRanges) === count($serviceRanges)) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    // Only prune specific orphaned services while preserving others
    $removeByLine = [];
    foreach ($removedRanges as $range) {
        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            $removeByLine[$lineIndex] = true;
        }
    }

    $newLines = [];
    foreach ($lines as $lineIndex => $line) {
        if (!isset($removeByLine[$lineIndex])) {
            $newLines[] = $line;
        }
    }

    $newContent = implode("\n", $newLines);
    if ($hadTrailingNewline && substr($newContent, -1) !== "\n") {
        $newContent .= "\n";
    }

    if ($lineEnding === "\r\n") {
        $newContent = str_replace("\n", "\r\n", $newContent);
    }

    return ['content' => $newContent, 'removed' => $removedServices, 'changed' => true];
}

class OverrideInfo
{
    /**
     * @var string Computed override filename (e.g. compose.override.yaml)
     */
    public string $computedName = '';
    /**
     * @var string|null Path to project override file
     */
    public ?string $projectOverride = null;
    /**
     * @var string|null Path to indirect override file
     */
    public ?string $indirectOverride = null;
    /**
     * @var bool True if indirect override should be used
     */
    public bool $useIndirect = false;
    /**
     * @var bool True if indirect contains legacy-named override but not correctly-named one
     */
    public bool $mismatchIndirectLegacy = false;
    /**
     * @var string|null Resolved path to the main compose file
     */
    public ?string $composeFilePath = null;

    private function __construct() {}

    /**
     * Create an OverrideInfo from a StackInfo instance.
     *
     * Primary factory — uses the pre-resolved identity fields from StackInfo
     * (path, composeSource, composeFilePath, isIndirect) so that no duplicate
     * filesystem resolution is needed.
     *
     * @param StackInfo $stackInfo The owning stack (must have identity fields populated)
     * @return OverrideInfo
     */
    public static function fromStackInfo(StackInfo $stackInfo): self
    {
        $indirectPath = $stackInfo->isIndirect ? $stackInfo->composeSource : null;
        return self::resolveOverride($stackInfo->path, $indirectPath, $stackInfo->composeFilePath);
    }

    /**
     * Create an OverrideInfo by resolving paths from scratch.
     *
     * @deprecated Use StackInfo::fromProject() which provides OverrideInfo automatically
     *             via fromStackInfo(), avoiding duplicate filesystem resolution.
     *
     * @param string $composeRoot
     * @param string $stack
     * @return OverrideInfo
     */
    public static function fromStack(string $composeRoot, string $stack): self
    {
        $projectPath = rtrim($composeRoot, '/') . '/' . $stack;
        $indirectPath = is_file("$projectPath/indirect")
            ? trim(file_get_contents("$projectPath/indirect"))
            : null;
        if ($indirectPath === '') {
            $indirectPath = null;
        }

        $composeSource = $indirectPath ?? $projectPath;
        $foundCompose = findComposeFile($composeSource);
        $composeFilePath = $foundCompose !== false ? $foundCompose : null;

        return self::resolveOverride($projectPath, $indirectPath, $composeFilePath);
    }

    /**
     * Core override resolution logic shared by both factories.
     *
     * Computes the override filename from the compose file, resolves project
     * and indirect override paths, migrates/removes legacy overrides, and
     * auto-creates a project override template if needed.
     *
     * @param string      $projectPath     Full path to the stack directory
     * @param string|null $indirectPath     Indirect target directory, or null if not indirect
     * @param string|null $composeFilePath  Resolved main compose file path, or null if none
     * @return OverrideInfo
     */
    private static function resolveOverride(string $projectPath, ?string $indirectPath, ?string $composeFilePath): self
    {
        $info = new self();
        $info->composeFilePath = $composeFilePath;

        $composeBaseName = $composeFilePath !== null ? basename($composeFilePath) : COMPOSE_FILE_NAMES[0];
        $info->computedName = preg_replace('/(\.[^.]+)$/', '.override$1', $composeBaseName);

        $info->projectOverride = $projectPath . '/' . $info->computedName;
        $info->indirectOverride = $indirectPath !== null ? ($indirectPath . '/' . $info->computedName) : null;

        $legacyProject = $projectPath . '/docker-compose.override.yml';
        $legacyIndirect = $indirectPath !== null ? ($indirectPath . '/docker-compose.override.yml') : null;

        $info->useIndirect = ($info->indirectOverride && is_file($info->indirectOverride));
        $info->mismatchIndirectLegacy = ($indirectPath !== null && $legacyIndirect && is_file($legacyIndirect) && !($info->indirectOverride && is_file($info->indirectOverride)));

        // Migrate legacy project override to computed project override (project-only migration)
        if (!is_file($info->projectOverride) && is_file($legacyProject) && realpath($legacyProject) !== @realpath($info->projectOverride)) {
            @rename($legacyProject, $info->projectOverride);
            clientDebug("[override] Migrated legacy project override $legacyProject -> $info->projectOverride", null, 'daemon', 'info');
        }

        if (is_file($info->projectOverride) && is_file($legacyProject) && realpath($legacyProject) !== @realpath($info->projectOverride)) {
            @rename($legacyProject, $legacyProject . ".bak");
            clientDebug("[override] Removed stale legacy project override $legacyProject (mismatch with computed override)", null, 'daemon', 'info');
        }

        if ($info->mismatchIndirectLegacy) {
            clientDebug("[override] Indirect override exists with non-matching name; using project fallback.", null, 'daemon', 'warning');
        }

        if (!is_file($info->projectOverride) && !$info->useIndirect) {
            $overrideContent = "# Override file for UI labels (icon, webui, shell)\n";
            $overrideContent .= "# This file is managed by Compose Manager\n";
            $overrideContent .= "services: {}\n";
            file_put_contents($info->projectOverride, $overrideContent);
            clientDebug("[override] Created missing project override template at $info->projectOverride", null, 'daemon', 'info');
        }

        return $info;
    }

    /**
     * Get the override file to use (indirect if present, else project override)
     * @return string|null
     */
    public function getOverridePath(): ?string
    {
        return $this->useIndirect ? $this->indirectOverride : $this->projectOverride;
    }

    /**
     * Prune orphaned services from the override file.
     *
     * Removes any services in the override that are not present in the
     * provided list of valid service names. The override file is rewritten
     * in place.
     *
     * @param string[] $validServices Service names that are defined in the main compose file
     * @return array{changed: bool, removed: string[]}
     */
    public function pruneOrphanServices(array $validServices): array
    {
        $overridePath = $this->getOverridePath();
        if ($overridePath === null || $overridePath === '' || !is_file($overridePath)) {
            return ['changed' => false, 'removed' => []];
        }

        if (empty($validServices)) {
            return ['changed' => false, 'removed' => []];
        }

        $overrideContent = file_get_contents($overridePath);
        if ($overrideContent === false || $overrideContent === '') {
            return ['changed' => false, 'removed' => []];
        }

        $result = pruneOverrideContentServices($overrideContent, $validServices);
        if (!($result['changed'] ?? false)) {
            return ['changed' => false, 'removed' => []];
        }

        file_put_contents($overridePath, $result['content']);

        $removedServices = $result['removed'] ?? [];
        if (!empty($removedServices)) {
            clientDebug(
                "[override] Pruned orphaned override services from " . basename($overridePath) . ": " . implode(', ', $removedServices),
                null,
                'daemon',
                'info'
            );
        }

        return ['changed' => true, 'removed' => $removedServices];
    }

    /**
     * Migrate override entries when services are renamed.
     *
     * Compares old and new compose content to detect service renames and
     * automatically updates the override file to match.
     *
     * @param string $oldComposeContent The old compose file content
     * @param string $newComposeContent The new compose file content
     * @return array{migrated: bool, migrations: array<array{from: string, to: string}>}
     */
    public function migrateOnRename(string $oldComposeContent, string $newComposeContent): array
    {
        $result = ['migrated' => false, 'migrations' => []];

        $overridePath = $this->getOverridePath();
        if ($overridePath === null || !is_file($overridePath)) {
            return $result;
        }

        $overrideContent = file_get_contents($overridePath);
        if ($overrideContent === false || trim($overrideContent) === '') {
            return $result;
        }

        // Parse services from old and new compose content using simple YAML parsing
        $oldServices = self::parseServicesFromYaml($oldComposeContent);
        $newServices = self::parseServicesFromYaml($newComposeContent);
        $overrideServices = self::parseServicesFromYaml($overrideContent);

        if (empty($oldServices) || empty($newServices) || empty($overrideServices)) {
            return $result;
        }

        // Find removed services (in old but not in new)
        $removedServices = array_diff(array_keys($oldServices), array_keys($newServices));
        // Find added services (in new but not in old)
        $addedServices = array_diff(array_keys($newServices), array_keys($oldServices));

        if (empty($removedServices) || empty($addedServices)) {
            return $result;
        }

        // Build rename map: match by image, or if same count assume positional rename
        $renameMap = [];

        // First try to match by image
        foreach ($removedServices as $oldName) {
            $oldImage = $oldServices[$oldName]['image'] ?? '';
            if ($oldImage === '') {
                continue;
            }

            foreach ($addedServices as $newName) {
                if (isset($renameMap[$oldName]) || in_array($newName, $renameMap)) {
                    continue;
                }
                $newImage = $newServices[$newName]['image'] ?? '';
                if ($oldImage === $newImage) {
                    $renameMap[$oldName] = $newName;
                    break;
                }
            }
        }

        // If counts match and we couldn't match by image, assume 1:1 positional rename
        if (empty($renameMap) && count($removedServices) === count($addedServices)) {
            $removedList = array_values($removedServices);
            $addedList = array_values($addedServices);
            for ($i = 0; $i < count($removedList); $i++) {
                // Only map if the old name exists in override
                if (isset($overrideServices[$removedList[$i]])) {
                    $renameMap[$removedList[$i]] = $addedList[$i];
                }
            }
        }

        if (empty($renameMap)) {
            return $result;
        }

        // Apply renames to override content
        $newOverrideContent = $overrideContent;
        foreach ($renameMap as $oldName => $newName) {
            // Only migrate if old name exists in override and new name doesn't
            if (!isset($overrideServices[$oldName]) || isset($overrideServices[$newName])) {
                continue;
            }

            // Replace service name in override (careful YAML replacement)
            // Match "  oldname:" at start of line (2 space indent under services:)
            $pattern = '/^(  )' . preg_quote($oldName, '/') . '(\s*:)/m';
            $replacement = '$1' . $newName . '$2';
            $newOverrideContent = preg_replace($pattern, $replacement, $newOverrideContent, 1, $count);

            if ($count > 0) {
                $result['migrations'][] = ['from' => $oldName, 'to' => $newName];
            }
        }

        if (!empty($result['migrations'])) {
            file_put_contents($overridePath, $newOverrideContent);
            $result['migrated'] = true;
            
            $migrationLog = array_map(fn($m) => "{$m['from']} -> {$m['to']}", $result['migrations']);
            clientDebug(
                "[override] Migrated renamed services: " . implode(', ', $migrationLog),
                null,
                'daemon',
                'info'
            );
        }

        return $result;
    }

    /**
     * Parse service definitions from YAML content.
     *
     * Simple parser that extracts service names and their image values.
     * Public static method for use in tests and other contexts.
     *
     * @param string $yamlContent The YAML content to parse
     * @return array<string, array{image?: string}> Service name => service data
     */
    public static function parseServicesFromYaml(string $yamlContent): array
    {
        $services = [];
        $lines = explode("\n", $yamlContent);
        $inServices = false;
        $currentService = null;

        foreach ($lines as $line) {
            // Skip empty lines and comments
            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                continue;
            }

            // Check for services: section
            if (preg_match('/^services\s*:/', $line)) {
                $inServices = true;
                continue;
            }

            // Check for end of services section (another top-level key)
            if ($inServices && preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*\s*:/', $line) && !preg_match('/^services\s*:/', $line)) {
                $inServices = false;
                continue;
            }

            if (!$inServices) {
                continue;
            }

            // Match service name (2 spaces indent)
            if (preg_match('/^  (["\']?)([a-zA-Z0-9_-]+)\1\s*:/', $line, $matches)) {
                $currentService = $matches[2];
                $services[$currentService] = [];
                continue;
            }

            // Match image under service (4+ spaces indent)
            if ($currentService && preg_match('/^    image\s*:\s*["\']?([^"\'#\n]+)["\']?/', $line, $matches)) {
                $services[$currentService]['image'] = trim($matches[1]);
            }
        }

        return $services;
    }
}

/**
 * Normalized container information.
 *
 * Provides a single canonical shape for container data regardless of source
 * (docker inspect response, update-check response, or cached status).
 * Eliminates PascalCase/camelCase drift and container-name aliasing issues.
 */
class ContainerInfo
{
    /** @var string Canonical container name (from Name/Service/container) */
    public string $name = '';
    /** @var string Docker container ID */
    public string $id = '';
    /** @var string Compose service name */
    public string $service = '';
    /** @var string Full image reference (e.g. library/nginx:latest) */
    public string $image = '';
    /** @var string Container state (running/exited/paused/restarting) */
    public string $state = '';
    /** @var bool Whether the container is currently running */
    public bool $isRunning = false;
    /** @var bool Whether an image update is available */
    public bool $hasUpdate = false;
    /** @var string Update status text (unknown/up-to-date/update-available) */
    public string $updateStatus = 'unknown';
    /** @var string Local image SHA (truncated) */
    public string $localSha = '';
    /** @var string Remote image SHA (truncated) */
    public string $remoteSha = '';
    /** @var bool Whether the image is pinned to a specific digest */
    public bool $isPinned = false;
    /** @var string|null Pinned digest if isPinned is true */
    public ?string $pinnedDigest = null;
    /** @var string Icon URL from Docker label */
    public string $icon = '';
    /** @var string Shell path from Docker label */
    public string $shell = '/bin/bash';
    /** @var string Resolved WebUI URL */
    public string $webUI = '';
    /** @var array Port mappings (e.g. ["192.168.1.1:8080->80/tcp"]) */
    public array $ports = [];
    /** @var array Network info [{name, ip, driver}] */
    public array $networks = [];
    /** @var array Volume mounts [{source, destination, type}] */
    public array $volumes = [];
    /** @var string ISO datetime when container was created */
    public string $created = '';
    /** @var string ISO datetime when container was started */
    public string $startedAt = '';

    private function __construct() {}

    /**
     * Create a ContainerInfo from a fully-assembled docker inspect + compose ps result.
     *
     * This is the shape built by the getStackContainers action in exec.php.
     * Accepts either PascalCase or camelCase keys and normalizes them.
     *
     * @param array $raw Associative array with container data
     * @return ContainerInfo
     */
    public static function fromDockerInspect(array $raw): self
    {
        $info = new self();
        $info->name = $raw['Name'] ?? $raw['name'] ?? '';
        $info->id = $raw['ID'] ?? $raw['Id'] ?? $raw['id'] ?? '';
        $info->service = $raw['Service'] ?? $raw['service'] ?? '';
        $info->image = $raw['Image'] ?? $raw['image'] ?? '';
        $info->state = strtolower($raw['State'] ?? $raw['state'] ?? '');
        $info->isRunning = ($info->state === 'running');
        $info->icon = $raw['Icon'] ?? $raw['icon'] ?? '';
        $info->shell = $raw['Shell'] ?? $raw['shell'] ?? '/bin/bash';
        $info->webUI = $raw['WebUI'] ?? $raw['webUI'] ?? $raw['webui'] ?? '';
        $info->ports = $raw['Ports'] ?? $raw['ports'] ?? [];
        $info->networks = $raw['Networks'] ?? $raw['networks'] ?? [];
        $info->volumes = $raw['Volumes'] ?? $raw['volumes'] ?? [];
        $info->created = $raw['Created'] ?? $raw['created'] ?? '';
        $info->startedAt = $raw['StartedAt'] ?? $raw['startedAt'] ?? '';

        // Normalize update status (accept PascalCase or camelCase)
        $info->updateStatus = $raw['updateStatus'] ?? $raw['UpdateStatus'] ?? $raw['status'] ?? 'unknown';
        $info->localSha = $raw['localSha'] ?? $raw['LocalSha'] ?? '';
        $info->remoteSha = $raw['remoteSha'] ?? $raw['RemoteSha'] ?? '';
        $info->hasUpdate = $raw['hasUpdate']
            ?? ($info->updateStatus === 'update-available');

        // Derive pinned status from @sha256: in image reference
        $info->derivePinned();

        return $info;
    }

    /**
     * Create a ContainerInfo from an update-check response element.
     *
     * This is the per-container shape returned by checkStackUpdates (keys:
     * container, image, hasUpdate, status, localSha, remoteSha).
     *
     * @param array $raw Associative array from update check
     * @return ContainerInfo
     */
    public static function fromUpdateResponse(array $raw): self
    {
        $info = new self();
        $info->name = $raw['container'] ?? $raw['name'] ?? $raw['Name'] ?? '';
        $info->id = $raw['ID'] ?? $raw['Id'] ?? $raw['id'] ?? '';
        $info->service = $raw['service'] ?? $raw['Service'] ?? '';
        $info->image = $raw['image'] ?? $raw['Image'] ?? '';
        $info->hasUpdate = $raw['hasUpdate'] ?? false;
        $info->updateStatus = $raw['status'] ?? $raw['updateStatus'] ?? 'unknown';
        $info->localSha = $raw['localSha'] ?? '';
        $info->remoteSha = $raw['remoteSha'] ?? '';

        $info->derivePinned();

        return $info;
    }

    /**
     * Merge update-check fields from another ContainerInfo without
     * overwriting identity or runtime state fields.
     *
     * @param ContainerInfo $update The newer update data to merge in
     */
    public function mergeUpdateStatus(ContainerInfo $update): void
    {
        if ($update->hasUpdate) {
            $this->hasUpdate = true;
        }
        if ($update->updateStatus !== '' && $update->updateStatus !== 'unknown') {
            $this->updateStatus = $update->updateStatus;
        }
        if ($update->localSha !== '') {
            $this->localSha = $update->localSha;
        }
        if ($update->remoteSha !== '') {
            $this->remoteSha = $update->remoteSha;
        }
        if ($update->isPinned) {
            $this->isPinned = $update->isPinned;
            $this->pinnedDigest = $update->pinnedDigest;
        }
    }

    /**
     * Serialize to a consistently camelCase associative array for JSON responses.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'id' => $this->id,
            'service' => $this->service,
            'image' => $this->image,
            'state' => $this->state,
            'isRunning' => $this->isRunning,
            'hasUpdate' => $this->hasUpdate,
            'updateStatus' => $this->updateStatus,
            'localSha' => $this->localSha,
            'remoteSha' => $this->remoteSha,
            'isPinned' => $this->isPinned,
            'pinnedDigest' => $this->pinnedDigest,
            'icon' => $this->icon,
            'shell' => $this->shell,
            'webUI' => $this->webUI,
            'ports' => $this->ports,
            'networks' => $this->networks,
            'volumes' => $this->volumes,
            'created' => $this->created,
            'startedAt' => $this->startedAt,
        ];
    }

    /**
     * Serialize only the update-related fields (for update-check responses).
     *
     * @return array
     */
    public function toUpdateArray(): array
    {
        return [
            'name' => $this->name,
            'image' => $this->image,
            'hasUpdate' => $this->hasUpdate,
            'updateStatus' => $this->updateStatus,
            'localSha' => $this->localSha,
            'remoteSha' => $this->remoteSha,
            'isPinned' => $this->isPinned,
            'pinnedDigest' => $this->pinnedDigest,
        ];
    }

    /**
     * Derive isPinned and pinnedDigest from the image reference.
     */
    private function derivePinned(): void
    {
        if ($this->image !== '' && strpos($this->image, '@sha256:') !== false) {
            $this->isPinned = true;
            $parts = explode('@sha256:', $this->image, 2);
            $this->pinnedDigest = $parts[1] ?? null;
        }
    }
}

/**
 * Centralized stack identity and metadata.
 *
 * Resolves and caches the canonical identity for a compose stack: directory
 * name (project), sanitized Docker project name, compose file path, indirect
 * target, override info, and provides lazy access to metadata files (name,
 * description, envpath, icon_url, webui_url, etc.).
 *
 * Construction is intentionally eager for identity fields and override
 * resolution (preserving current side-effect behavior). Metadata files are
 * loaded lazily on first access.
 */
class StackInfo
{
    /** @var string Directory basename (canonical filesystem identity) */
    public string $project;
    /** @var string sanitizeStr($project) — used as Docker -p project name */
    public string $sanitizedName;
    /** @var string Full path to the stack directory ($composeRoot/$project) */
    public string $path;
    /** @var string Resolved compose source directory (indirect target or $path) */
    public string $composeSource;
    /** @var string|null Full path to the main compose file, or null if none */
    public ?string $composeFilePath;
    /** @var bool Whether this stack uses an indirect compose path */
    public bool $isIndirect;
    /** @var OverrideInfo Resolved override info (eager) */
    public OverrideInfo $overrideInfo;

    /** @var string Compose root directory */
    private string $composeRoot;

    /** @var array Lazy-loaded metadata cache (field => value|null, unset = not loaded) */
    private array $metadataCache = [];

    /** @var array<string, StackInfo> Static instance cache keyed by composeRoot/project */
    private static array $instances = [];

    /**
     * @param string $composeRoot Compose root directory
     * @param string $project Directory basename of the stack
     */
    private function __construct(string $composeRoot, string $project)
    {
        $this->composeRoot = rtrim($composeRoot, '/');
        $this->project = $project;
        $this->path = $this->composeRoot . '/' . $project;
        $this->sanitizedName = sanitizeStr($project);

        // Resolve indirect
        $this->isIndirect = isIndirect($this->path);
        $this->composeSource = $this->isIndirect
            ? (trim(@file_get_contents($this->path . '/indirect')) ?: $this->path)
            : $this->path;

        // Resolve compose file
        $found = findComposeFile($this->composeSource);
        $this->composeFilePath = ($found !== false) ? $found : null;

        // Eagerly resolve override info using pre-resolved identity (no duplicate I/O)
        $this->overrideInfo = OverrideInfo::fromStackInfo($this);
    }

    /**
     * Create a StackInfo for a project directory under the compose root.
     *
     * Returns a cached instance if one already exists for this composeRoot/project
     * combination, avoiding redundant filesystem and override resolution work.
     *
     * @param string $composeRoot The compose projects root directory
     * @param string $project Directory basename of the stack
     * @return StackInfo
     */
    public static function fromProject(string $composeRoot, string $project): self
    {
        $key = rtrim($composeRoot, '/') . '/' . $project;
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($composeRoot, $project);
        }
        return self::$instances[$key];
    }

    /**
     * Clear the static instance cache.
     *
     * Primarily useful in tests to ensure a clean state between test cases.
     *
     * @param string|null $key Optional specific key (composeRoot/project) to clear; null clears all.
     */
    public static function clearCache(?string $key = null): void
    {
        if ($key !== null) {
            unset(self::$instances[$key]);
        } else {
            self::$instances = [];
        }
    }

    // ---------------------------------------------------------------
    // Lazy metadata getters — read from file on first access, cache
    // ---------------------------------------------------------------

    /**
     * Get the display name (from `name` file, falls back to $project).
     * @return string
     */
    public function getName(): string
    {
        return $this->readMetadata('name') ?? $this->project;
    }

    /**
     * Get the stack description.
     * @return string
     */
    public function getDescription(): string
    {
        return $this->readMetadata('description') ?? '';
    }

    /**
     * Get the custom env file path (from `envpath` file).
     * @return string|null
     */
    public function getEnvFilePath(): ?string
    {
        $val = $this->readMetadata('envpath');
        return ($val !== null && $val !== '') ? $val : null;
    }

    /**
     * Get the icon URL (from `icon_url` file), validated.
     * @return string|null
     */
    public function getIconUrl(): ?string
    {
        $url = $this->readMetadata('icon_url');
        if ($url !== null && filter_var($url, FILTER_VALIDATE_URL)
            && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)) {
            return $url;
        }
        return null;
    }

    /**
     * Get the stack-level WebUI URL (from `webui_url` file), validated.
     * @return string|null
     */
    public function getWebUIUrl(): ?string
    {
        $url = $this->readMetadata('webui_url');
        if ($url !== null && filter_var($url, FILTER_VALIDATE_URL)
            && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)) {
            return $url;
        }
        return null;
    }

    /**
     * Get default profiles (from `default_profile` file), comma-split.
     * @return string[]
     */
    public function getDefaultProfiles(): array
    {
        $raw = $this->readMetadata('default_profile');
        if ($raw === null || $raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Get autostart flag (from `autostart` file).
     * @return bool
     */
    public function getAutostart(): bool
    {
        $val = $this->readMetadata('autostart');
        return ($val !== null && strpos($val, 'true') !== false);
    }

    /**
     * Get the started_at timestamp (from `started_at` file).
     * @return string|null
     */
    public function getStartedAt(): ?string
    {
        $val = $this->readMetadata('started_at');
        return ($val !== null && $val !== '') ? $val : null;
    }

    /**
     * Get available profiles (from `profiles` JSON file).
     * @return array
     */
    public function getProfiles(): array
    {
        $raw = $this->readMetadata('profiles');
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ---------------------------------------------------------------
    // Derived helpers
    // ---------------------------------------------------------------

    /**
     * Get the OverrideInfo for this stack.
     * @return OverrideInfo
     */
    public function getOverrideInfo(): OverrideInfo
    {
        return $this->overrideInfo;
    }

    /**
     * Get the effective override file path (delegates to OverrideInfo).
     * @return string|null
     */
    public function getOverridePath(): ?string
    {
        return $this->overrideInfo->getOverridePath();
    }

    /**
     * Get the list of services defined in the main compose file.
     *
     * Uses `docker compose config --services` to accurately resolve
     * services including extends, anchors, etc.
     *
     * @return string[] List of service names
     */
    public function getDefinedServices(): array
    {
        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            return [];
        }

        $cmd = "docker compose -f " . escapeshellarg($this->composeFilePath);

        // Include override file if available
        $overridePath = $this->getOverridePath();
        if ($overridePath !== null && is_file($overridePath)) {
            $cmd .= " -f " . escapeshellarg($overridePath);
        }

        $envFilePath = $this->getEnvFilePath();
        if ($envFilePath !== null && is_file($envFilePath)) {
            $cmd .= " --env-file " . escapeshellarg($envFilePath);
        }
        $cmd .= " config --services 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", trim($output))), function ($service) {
            return $service !== '';
        }));
    }

    /**
     * Prune orphaned services from the override file.
     *
     * Resolves valid services from the main compose file (without override),
     * then delegates to OverrideInfo::pruneOrphanServices().
     *
     * @return array{changed: bool, removed: string[]}
     */
    public function pruneOrphanOverrideServices(): array
    {
        $validServices = $this->getMainFileServices();
        if (empty($validServices)) {
            return ['changed' => false, 'removed' => []];
        }
        return $this->overrideInfo->pruneOrphanServices($validServices);
    }

    /**
     * Get the list of services defined in the main compose file only (without override).
     *
     * Used internally by pruneOrphanOverrideServices() to determine which services
     * are valid. Excludes the override file so orphaned override services are not
     * counted as valid.
     *
     * External callers should typically use getDefinedServices() which includes
     * the override file for a complete picture.
     *
     * @return string[] List of service names
     */
    private function getMainFileServices(): array
    {
        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            return [];
        }

        $cmd = "docker compose -f " . escapeshellarg($this->composeFilePath);

        $envFilePath = $this->getEnvFilePath();
        if ($envFilePath !== null && is_file($envFilePath)) {
            $cmd .= " --env-file " . escapeshellarg($envFilePath);
        }
        $cmd .= " config --services 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", trim($output))), function ($service) {
            return $service !== '';
        }));
    }

    /**
     * Build the common compose CLI arguments for this stack.
     *
     * Returns the project name, file flags, and env-file flag suitable
     * for passing to `docker compose`.
     *
     * @return array{projectName: string, files: string, envFile: string}
     */
    public function buildComposeArgs(): array
    {
        $composeFile = $this->composeFilePath ?? ($this->composeSource . '/' . COMPOSE_FILE_NAMES[0]);

        $files = "-f " . escapeshellarg($composeFile);

        $overridePath = $this->getOverridePath();
        if ($overridePath !== null) {
            $files .= " -f " . escapeshellarg($overridePath);
        }

        $envFile = "";
        $envPath = $this->getEnvFilePath();
        if ($envPath !== null && is_file($envPath)) {
            $envFile = "--env-file " . escapeshellarg($envPath);
        }

        return [
            'projectName' => $this->sanitizedName,
            'files' => $files,
            'envFile' => $envFile,
        ];
    }

    /**
     * Check if any service in the stack has a build configuration.
     *
     * Uses `docker compose config` to parse the compose files and checks
     * for services with build contexts, indicating they need to be built
     * at startup rather than pulled from a registry.
     *
     * @return bool True if any service has a build configuration
     */
    public function hasBuildConfig(): bool
    {
        // Check cache first
        if (array_key_exists('has_build', $this->metadataCache)) {
            return (bool) $this->metadataCache['has_build'];
        }

        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            $this->metadataCache['has_build'] = false;
            return false;
        }

        // Use docker compose config to get the resolved configuration
        $cmd = "docker compose -f " . escapeshellarg($this->composeFilePath);

        $overridePath = $this->getOverridePath();
        if ($overridePath !== null && is_file($overridePath)) {
            $cmd .= " -f " . escapeshellarg($overridePath);
        }

        $envFilePath = $this->getEnvFilePath();
        if ($envFilePath !== null && is_file($envFilePath)) {
            $cmd .= " --env-file " . escapeshellarg($envFilePath);
        }
        $cmd .= " config 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            $this->metadataCache['has_build'] = false;
            return false;
        }

        // Parse the YAML output and check for build keys
        // Look for "build:" at the start of a line (indented under services)
        // This is a simple heuristic - build: can be a string (context) or object
        $hasBuild = preg_match('/^\s+build:/m', $output) === 1;

        $this->metadataCache['has_build'] = $hasBuild;
        return $hasBuild;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * Read a metadata file from the stack directory (lazy, cached).
     *
     * @param string $filename Metadata filename (e.g. 'name', 'envpath')
     * @return string|null Trimmed file contents, or null if file doesn't exist
     */
    private function readMetadata(string $filename): ?string
    {
        if (array_key_exists($filename, $this->metadataCache)) {
            return $this->metadataCache[$filename];
        }

        $filePath = $this->path . '/' . $filename;
        if (is_file($filePath)) {
            $content = @file_get_contents($filePath);
            $this->metadataCache[$filename] = ($content !== false) ? trim($content) : null;
        } else {
            $this->metadataCache[$filename] = null;
        }

        return $this->metadataCache[$filename];
    }

    // ---------------------------------------------------------------
    // Static factory: create a new stack on disk
    // ---------------------------------------------------------------

    /**
     * Create a new stack directory with all required files.
     *
     * Handles folder naming (via sanitizeFolderName + collision avoidance),
     * indirect wiring, default compose file creation, metadata files (name,
     * description), and override initialization.
     *
     * Input validation (e.g. allowed indirect path roots) is the caller's
     * responsibility — this method only deals with the filesystem structure.
     *
     * @param string $composeRoot  The compose projects root directory
     * @param string $stackName    Human-readable stack name
     * @param string $description  Optional description text
     * @param string $indirectPath Optional indirect compose source directory
     *
     * @return self The newly created (and cached) StackInfo instance
     *
     * @throws \RuntimeException If the stack directory cannot be created
     */
    public static function createNew(
        string $composeRoot,
        string $stackName,
        string $description = '',
        string $indirectPath = ''
    ): self {
        $composeRoot = rtrim($composeRoot, '/');

        // 0. Validate stack name is not empty
        if (trim($stackName) === '') {
            throw new \RuntimeException("Stack name cannot be empty");
        }

        // 1. Generate a unique folder name
        $folderName = sanitizeFolderName($stackName);
        if ($folderName === '') {
            throw new \RuntimeException("Stack name produced an empty folder name after sanitization.");
        }
        $folder = $composeRoot . '/' . $folderName;

        // Verify the resolved path stays within composeRoot (defense-in-depth)
        $realComposeRoot = realpath($composeRoot);
        if ($realComposeRoot === false) {
            throw new \RuntimeException("Invalid compose root directory.");
        }
        // For new folders, check that the parent resolves correctly
        $resolvedParent = realpath(dirname($folder));
        if ($resolvedParent === false || strpos($resolvedParent, $realComposeRoot) !== 0) {
            throw new \RuntimeException("Invalid stack name: path would escape compose root.");
        }

        if (is_dir($folder)) {
            // Append a random suffix to the base name to avoid collision;
            // re-derive from the clean base each attempt so suffixes don't compound.
            $attempts = 0;
            do {
                $candidate = $composeRoot . '/' . $folderName . mt_rand();
                $attempts++;
                if ($attempts > 100) {
                    throw new \RuntimeException("Unable to find a unique folder name for stack: $stackName");
                }
            } while (is_dir($candidate));
            $folder = $candidate;
        }

        // 2. Create the directory
        if (!mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new \RuntimeException("Failed to create stack directory: $folder");
        }

        // 3. Create compose / indirect files
        if ($indirectPath !== '') {
            file_put_contents("$folder/indirect", $indirectPath);
            if (!findComposeFile($indirectPath)) {
                file_put_contents("$indirectPath/" . COMPOSE_FILE_NAMES[0], "services:\n");
            }
        } else {
            file_put_contents("$folder/" . COMPOSE_FILE_NAMES[0], "services:\n");
        }

        // 4. Write metadata
        file_put_contents("$folder/name", $stackName);
        if ($description !== '') {
            file_put_contents("$folder/description", $description);
        }

        // 5. Build + cache the instance (resolves override, etc.)
        return self::fromProject($composeRoot, basename($folder));
    }

    /**
     * Find a StackInfo by its compose source path.
     *
     * Searches all projects to find one whose composeSource matches the given path.
     * Useful for auto-update feature where config stores compose source paths.
     *
     * @param string $composeRoot The compose projects root directory
     * @param string $composePath The compose source path to search for
     * @return self|null The matching StackInfo, or null if not found
     */
    public static function fromComposePath(string $composeRoot, string $composePath): ?self
    {
        $composeRoot = rtrim($composeRoot, '/');
        $normalizedPath = rtrim($composePath, '/');

        $projects = @array_diff(@scandir($composeRoot), ['.', '..']) ?: [];
        foreach ($projects as $project) {
            $projectPath = $composeRoot . '/' . $project;
            if (!is_dir($projectPath)) {
                continue;
            }
            // Check if this project's composeSource matches
            $stackInfo = self::fromProject($composeRoot, $project);
            if (rtrim($stackInfo->composeSource, '/') === $normalizedPath) {
                return $stackInfo;
            }
        }
        return null;
    }
}





/**
 * Stack operation locking functions
 * Prevents concurrent operations on the same stack
 */

// Lock directory override for testing - set via $GLOBALS['compose_lock_dir']

/**
 * Get the lock directory path
 * @return string
 */
function getLockDir(): string
{
    return $GLOBALS['compose_lock_dir'] ?? "/var/run/compose.manager";
}

/**
 * Acquire a lock for a stack operation
 * @param string $stackName The stack name/folder
 * @param int $timeout Maximum seconds to wait for lock (default 30)
 * @return resource|false File handle if lock acquired, false otherwise
 */
function acquireStackLock($stackName, $timeout = 30)
{
    $lockDir = getLockDir();
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }

    $lockFile = "$lockDir/" . sanitizeStr($stackName) . ".lock";
    $fp = @fopen($lockFile, 'w');

    if (!$fp) {
        return false;
    }

    $waited = 0;
    while (!flock($fp, LOCK_EX | LOCK_NB)) {
        if ($waited >= $timeout) {
            fclose($fp);
            return false;
        }
        sleep(1);
        $waited++;
    }

    // Write lock info for debugging
    fwrite($fp, json_encode([
        'pid' => getmypid(),
        'time' => date('c'),
        'stack' => $stackName
    ]));
    fflush($fp);

    return $fp;
}

/**
 * Release a stack lock
 * @param resource $fp File handle from acquireStackLock
 */
function releaseStackLock($fp)
{
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Check if a stack is currently locked
 * @param string $stackName The stack name/folder
 * @return array|false Lock info if locked, false if not locked
 */
function isStackLocked($stackName)
{
    $lockDir = getLockDir();
    $lockFile = "$lockDir/" . sanitizeStr($stackName) . ".lock";

    if (!is_file($lockFile)) {
        return false;
    }

    $fp = @fopen($lockFile, 'r');
    if (!$fp) {
        return false;
    }

    // Try to get a non-blocking lock
    if (flock($fp, LOCK_EX | LOCK_NB)) {
        // Got the lock, so it wasn't locked
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // Couldn't get lock, read the lock info
    $content = file_get_contents($lockFile);
    fclose($fp);

    $info = @json_decode($content, true);
    return $info ?: ['locked' => true];
}

/**
 * Get the last operation result for a stack
 * @param string $stackPath Full path to the stack directory
 * @return array|null Result info or null if not found
 */
function getStackLastResult($stackPath)
{
    $resultFile = "$stackPath/last_result.json";
    if (is_file($resultFile)) {
        $content = @file_get_contents($resultFile);
        if ($content) {
            return @json_decode($content, true);
        }
    }
    return null;
}

/**
 * Determine whether Compose-managed containers should be hidden from the Docker tab
 * Uses parse_plugin_cfg('compose.manager') when available (testable), or falls back to
 * parsing /boot/config/plugins/compose.manager/compose.manager.cfg
 *
 * @return bool
 */
function hide_compose_from_docker(): bool
{
    $cfg = [];
    if (function_exists('parse_plugin_cfg')) {
        $cfg = parse_plugin_cfg('compose.manager');
    } else {
        $cfg = @parse_ini_file('/boot/config/plugins/compose.manager/compose.manager.cfg') ?: [];
    }
    return (isset($cfg['HIDE_COMPOSE_FROM_DOCKER']) && $cfg['HIDE_COMPOSE_FROM_DOCKER'] === 'true');
}
