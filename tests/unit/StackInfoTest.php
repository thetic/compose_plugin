<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/php/util.php';

class StackInfoTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        $this->tempRoot = $this->createTempDir();
    }

    // ===========================================
    // Factory / Identity Tests
    // ===========================================

    public function testFromProjectCreatesInstance(): void
    {
        $stack = 'mystack';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $this->assertInstanceOf(\StackInfo::class, $info);
    }

    public function testProjectIdentityFields(): void
    {
        $stack = 'my-stack';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('my-stack', $info->project);
        $this->assertSame(sanitizeStr('my-stack'), $info->sanitizedName);
        $this->assertSame($this->tempRoot . '/my-stack', $info->path);
        $this->assertFalse($info->isIndirect);
    }

    public function testSanitizedNameRemovesSpecialChars(): void
    {
        $stack = 'My Stack (v2)';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        // sanitizeStr lowercases and removes non-alphanumeric/dash/underscore
        $this->assertSame(sanitizeStr('My Stack (v2)'), $info->sanitizedName);
    }

    public function testIndirectStackResolution(): void
    {
        $stack = 'indirect-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        $indirectTarget = $this->tempRoot . '/actual_source';
        mkdir($stackDir);
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertTrue($info->isIndirect);
        $this->assertSame($indirectTarget, $info->composeSource);
    }

    public function testNonIndirectStackSource(): void
    {
        $stack = 'direct-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertFalse($info->isIndirect);
        $this->assertSame($stackDir, $info->composeSource);
    }

    // ===========================================
    // Compose File Resolution Tests
    // ===========================================

    public function testComposeFilePathNullWhenNoFile(): void
    {
        $stack = 'no-compose';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $this->assertNull($info->composeFilePath);
    }

    public function testComposeFilePathResolvedWithComposeYaml(): void
    {
        $stack = 'has-compose';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/compose.yaml", $info->composeFilePath);
    }

    public function testComposeFilePathResolvedWithDockerComposeYml(): void
    {
        $stack = 'legacy-compose';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/docker-compose.yml", "services:\n  web:\n    image: nginx\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/docker-compose.yml", $info->composeFilePath);
    }

    public function testComposeFilePathResolvedViaIndirect(): void
    {
        $stack = 'indirect-compose';
        $stackDir = $this->tempRoot . '/' . $stack;
        $indirectTarget = $this->tempRoot . '/external_source';
        mkdir($stackDir);
        mkdir($indirectTarget);
        file_put_contents("$stackDir/indirect", $indirectTarget);
        file_put_contents("$indirectTarget/compose.yaml", "services:\n  app:\n    image: redis\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$indirectTarget/compose.yaml", $info->composeFilePath);
    }

    // ===========================================
    // Override Info Tests
    // ===========================================

    public function testOverrideInfoIsResolved(): void
    {
        $stack = 'override-stack';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertInstanceOf(\OverrideInfo::class, $info->overrideInfo);
        $this->assertInstanceOf(\OverrideInfo::class, $info->getOverrideInfo());
    }

    public function testGetOverridePathDelegates(): void
    {
        $stack = 'with-override';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.override.yaml", '# override');

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("$stackDir/compose.override.yaml", $info->getOverridePath());
    }

    // ===========================================
    // Lazy Metadata Getter Tests
    // ===========================================

    public function testGetNameFromFile(): void
    {
        $stack = 'named-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/name", "My Display Name");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('My Display Name', $info->getName());
    }

    public function testGetNameFallsBackToProject(): void
    {
        $stack = 'unnamed-stack';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('unnamed-stack', $info->getName());
    }

    public function testGetDescription(): void
    {
        $stack = 'desc-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/description", "A test stack\nwith multiple lines");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame("A test stack\nwith multiple lines", $info->getDescription());
    }

    public function testGetDescriptionEmptyWhenNoFile(): void
    {
        $stack = 'no-desc';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('', $info->getDescription());
    }

    public function testGetEnvFilePath(): void
    {
        $stack = 'env-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/envpath", "/path/to/.env");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('/path/to/.env', $info->getEnvFilePath());
    }

    public function testGetEnvFilePathNullWhenNoFile(): void
    {
        $stack = 'no-env';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getEnvFilePath());
    }

    public function testGetIconUrlValid(): void
    {
        $stack = 'icon-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/icon_url", "https://example.com/icon.png");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('https://example.com/icon.png', $info->getIconUrl());
    }

    public function testGetIconUrlNullForInvalidUrl(): void
    {
        $stack = 'bad-icon';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/icon_url", "not-a-url");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getIconUrl());
    }

    public function testGetIconUrlNullForFtpScheme(): void
    {
        $stack = 'ftp-icon';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/icon_url", "ftp://example.com/icon.png");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getIconUrl());
    }

    public function testGetWebUIUrl(): void
    {
        $stack = 'webui-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/webui_url", "http://192.168.1.1:8080");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('http://192.168.1.1:8080', $info->getWebUIUrl());
    }

    public function testGetWebUIUrlNullWhenInvalid(): void
    {
        $stack = 'bad-webui';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/webui_url", "javascript:alert(1)");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getWebUIUrl());
    }

    public function testGetDefaultProfiles(): void
    {
        $stack = 'profiles-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/default_profile", "dev, test , production");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame(['dev', 'test', 'production'], $info->getDefaultProfiles());
    }

    public function testGetDefaultProfilesEmptyWhenNoFile(): void
    {
        $stack = 'no-profiles';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame([], $info->getDefaultProfiles());
    }

    public function testGetAutostartTrue(): void
    {
        $stack = 'autostart-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/autostart", "true");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertTrue($info->getAutostart());
    }

    public function testGetAutostartFalseWhenNoFile(): void
    {
        $stack = 'no-autostart';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertFalse($info->getAutostart());
    }

    public function testGetAutostartFalseWhenNotTrue(): void
    {
        $stack = 'false-autostart';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/autostart", "false");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertFalse($info->getAutostart());
    }

    public function testGetStartedAt(): void
    {
        $stack = 'started-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/started_at", "2024-06-15T10:30:00Z");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame('2024-06-15T10:30:00Z', $info->getStartedAt());
    }

    public function testGetStartedAtNullWhenNoFile(): void
    {
        $stack = 'not-started';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNull($info->getStartedAt());
    }

    public function testGetProfiles(): void
    {
        $stack = 'json-profiles';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/profiles", json_encode(['dev', 'staging', 'prod']));

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame(['dev', 'staging', 'prod'], $info->getProfiles());
    }

    public function testGetProfilesEmptyWhenNoFile(): void
    {
        $stack = 'no-json-profiles';
        mkdir($this->tempRoot . '/' . $stack);
        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame([], $info->getProfiles());
    }

    public function testGetProfilesEmptyForInvalidJson(): void
    {
        $stack = 'bad-json';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/profiles", "not-valid-json{");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame([], $info->getProfiles());
    }

    // ===========================================
    // Metadata Caching Tests
    // ===========================================

    public function testMetadataIsCachedOnSecondAccess(): void
    {
        $stack = 'cached-name';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/name", "Original Name");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);

        // First read
        $this->assertSame('Original Name', $info->getName());

        // Mutate the file — should still return cached value
        file_put_contents("$stackDir/name", "Changed Name");
        $this->assertSame('Original Name', $info->getName());
    }

    public function testFromProjectReturnsCachedInstance(): void
    {
        $stack = 'cached-instance';
        mkdir($this->tempRoot . '/' . $stack);

        $first = \StackInfo::fromProject($this->tempRoot, $stack);
        $second = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertSame($first, $second);
    }

    public function testClearCacheForcesFreshInstance(): void
    {
        $stack = 'clear-cache';
        mkdir($this->tempRoot . '/' . $stack);

        $first = \StackInfo::fromProject($this->tempRoot, $stack);
        \StackInfo::clearCache();
        $second = \StackInfo::fromProject($this->tempRoot, $stack);

        $this->assertNotSame($first, $second);
    }

    // ===========================================
    // buildComposeArgs Tests
    // ===========================================

    public function testBuildComposeArgsBasic(): void
    {
        $stack = 'args-stack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        $this->assertArrayHasKey('projectName', $args);
        $this->assertArrayHasKey('files', $args);
        $this->assertArrayHasKey('envFile', $args);
        $this->assertSame($info->sanitizedName, $args['projectName']);
        $this->assertStringContainsString('compose.yaml', $args['files']);
    }

    public function testBuildComposeArgsWithEnvFile(): void
    {
        $stack = 'env-args';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        $envPath = $stackDir . '/.env';
        file_put_contents($envPath, "KEY=value");
        file_put_contents("$stackDir/envpath", $envPath);

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        $this->assertStringContainsString('--env-file', $args['envFile']);
    }

    public function testBuildComposeArgsWithOverride(): void
    {
        $stack = 'override-args';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        file_put_contents("$stackDir/compose.override.yaml", "services:\n  web:\n    ports:\n      - '80:80'\n");

        $info = \StackInfo::fromProject($this->tempRoot, $stack);
        $args = $info->buildComposeArgs();

        // Should have two -f flags
        $this->assertSame(2, substr_count($args['files'], '-f'));
        $this->assertStringContainsString('compose.override.yaml', $args['files']);
    }

    // ===========================================
    // createNew() Tests
    // ===========================================

    public function testCreateNewBasicStack(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'My Stack');

        $this->assertInstanceOf(\StackInfo::class, $stack);
        $this->assertDirectoryExists($stack->path);
        $this->assertSame('My Stack', $stack->getName());
        $this->assertNotNull($stack->composeFilePath);
        $this->assertFileExists($stack->composeFilePath);
        $this->assertSame("services:\n", file_get_contents($stack->composeFilePath));
        $this->assertFalse($stack->isIndirect);
    }

    public function testCreateNewSanitizesFolderName(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'My "Stack" (v2)');

        // sanitizeFolderName removes quotes and parens, replaces spaces
        $this->assertSame('My_Stack_v2', $stack->project);
        $this->assertSame('My "Stack" (v2)', $stack->getName());
    }

    public function testCreateNewWithDescription(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'Described', 'A test stack');

        $this->assertSame('A test stack', $stack->getDescription());
        $this->assertFileExists($stack->path . '/description');
    }

    public function testCreateNewWithoutDescription(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'NoDesc');

        $this->assertSame('', $stack->getDescription());
        $this->assertFileDoesNotExist($stack->path . '/description');
    }

    public function testCreateNewWithIndirectPath(): void
    {
        $indirectDir = $this->tempRoot . '/external';
        mkdir($indirectDir, 0755, true);

        $stack = \StackInfo::createNew($this->tempRoot, 'Indirect Stack', '', $indirectDir);

        $this->assertTrue($stack->isIndirect);
        $this->assertSame($indirectDir, $stack->composeSource);
        $this->assertFileExists($stack->path . '/indirect');
        $this->assertSame($indirectDir, trim(file_get_contents($stack->path . '/indirect')));
        // Should have created compose.yaml at indirect target
        $this->assertFileExists($indirectDir . '/compose.yaml');
    }

    public function testCreateNewIndirectExistingComposeFile(): void
    {
        $indirectDir = $this->tempRoot . '/existing';
        mkdir($indirectDir, 0755, true);
        file_put_contents("$indirectDir/compose.yaml", "services:\n  web:\n    image: nginx\n");

        $stack = \StackInfo::createNew($this->tempRoot, 'Existing Indirect', '', $indirectDir);

        $this->assertTrue($stack->isIndirect);
        // Should NOT overwrite existing compose file
        $this->assertSame("services:\n  web:\n    image: nginx\n", file_get_contents("$indirectDir/compose.yaml"));
    }

    public function testCreateNewHandlesFolderCollision(): void
    {
        // Pre-create the folder that sanitizeFolderName would produce
        $existingDir = $this->tempRoot . '/Collide';
        mkdir($existingDir, 0755, true);

        $stack = \StackInfo::createNew($this->tempRoot, 'Collide');

        // Should have created a different folder (with random suffix)
        $this->assertNotSame('Collide', $stack->project);
        $this->assertStringStartsWith('Collide', $stack->project);
        $this->assertDirectoryExists($stack->path);
    }

    public function testCreateNewInitializesOverride(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'Override Init');

        $this->assertInstanceOf(\OverrideInfo::class, $stack->overrideInfo);
        // Override file should be created for non-indirect stacks
        $overridePath = $stack->getOverridePath();
        $this->assertNotNull($overridePath);
        $this->assertFileExists($overridePath);
    }

    public function testCreateNewIsCached(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'Cached New');

        // Fetching via fromProject should return the same cached instance
        $fetched = \StackInfo::fromProject($this->tempRoot, $stack->project);
        $this->assertSame($stack, $fetched);
    }

    public function testCreateNewWritesNameFile(): void
    {
        $stack = \StackInfo::createNew($this->tempRoot, 'Display Name');

        $this->assertFileExists($stack->path . '/name');
        $this->assertSame('Display Name', file_get_contents($stack->path . '/name'));
    }
}
