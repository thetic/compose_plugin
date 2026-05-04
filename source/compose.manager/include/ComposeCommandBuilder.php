<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

/**
 * Compose command argument builder for stack-scoped operations.
 *
 * Centralizes action-aware argument resolution so callers (PHP endpoints,
 * event scripts, autoupdate wrappers) can consume a single contract.
 */
class ComposeCommandBuilder
{
    /**
     * Build resolved compose runtime arguments for a specific stack/action.
     *
     * @param StackInfo $stackInfo
     * @param string $action Supported: up, down, update, pull, stop, logs
     * @param string|null $stackPath Optional explicit stack path to include in output
        * @return array<string, mixed>
     */
    public static function buildForAction(StackInfo $stackInfo, string $action, ?string $stackPath = null): array
    {
        $action = strtolower(trim($action));
        self::assertSupportedAction($action);

        $args = $stackInfo->buildComposeArgs();

        return [
            'action' => $action,
            'projectName' => $args['projectName'],
            'composeFiles' => $args['filePaths'],
            'envFilePath' => $args['envFilePath'] ?? null,
            'profiles' => self::resolveProfilesForAction($stackInfo, $action),
            'stackPath' => $stackPath ?? $stackInfo->path,
        ];
    }

    /**
     * Resolve and build compose runtime arguments from compose root/project.
     *
     * @param string $composeRoot
     * @param string $project
     * @param string $action
     * @param string|null $stackPath
        * @return array<string, mixed>
     */
    public static function fromProject(string $composeRoot, string $project, string $action, ?string $stackPath = null): array
    {
        $stackInfo = StackInfo::fromProject($composeRoot, $project);
        return self::buildForAction($stackInfo, $action, $stackPath);
    }

    /**
     * @param string $action
     * @return string[]
     */
    private static function resolveProfilesForAction(StackInfo $stackInfo, string $action): array
    {
        if ($action === 'down') {
            return ['*'];
        }

        if ($action === 'update') {
            $profiles = $stackInfo->getRunningProfiles();
            if (empty($profiles)) {
                $profiles = $stackInfo->getDefaultProfiles();
            }
            return array_values($profiles);
        }

        return array_values($stackInfo->getDefaultProfiles());
    }

    /**
     * @param string $action
     */
    private static function assertSupportedAction(string $action): void
    {
        $allowed = ['up', 'down', 'update', 'pull', 'stop', 'logs'];
        if (!in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported compose action: ' . $action);
        }
    }
}
