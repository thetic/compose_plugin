<?php

/**
 * Unit Tests for Compose Manager Utility Functions (REAL SOURCE)
 * 
 * Tests the actual source file: source/compose.manager/include/Util.php
 * Uses stream wrapper to redirect Unraid paths to local files.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

// Load the actual source file via stream wrapper
require_once '/usr/local/emhttp/plugins/compose.manager/include/Util.php';

class UtilTest extends TestCase
{
    /**
     * Test sanitizeProjectString replaces dots with underscores
     */
    public function testSanitizeProjectStringReplacesDots(): void
    {
        $result = \StackInfo::sanitizeProjectString('my.stack.name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeProjectString replaces spaces with underscores
     */
    public function testSanitizeProjectStringReplacesSpaces(): void
    {
        $result = \StackInfo::sanitizeProjectString('my stack name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeProjectString preserves dashes
     */
    public function testSanitizeProjectStringPreservesDashes(): void
    {
        $result = \StackInfo::sanitizeProjectString('my-stack-name');
        $this->assertEquals('my-stack-name', $result);
    }

    /**
     * Test sanitizeProjectString converts to lowercase
     */
    public function testSanitizeProjectStringConvertsToLowercase(): void
    {
        $result = \StackInfo::sanitizeProjectString('MyStackName');
        $this->assertEquals('mystackname', $result);
    }

    /**
     * Test sanitizeProjectString handles combined cases (dashes preserved, other specials → underscore)
     */
    public function testSanitizeProjectStringCombined(): void
    {
        $result = \StackInfo::sanitizeProjectString('My.Stack-Name Here');
        $this->assertEquals('my_stack-name_here', $result);
    }

    /**
     * Test sanitizeProjectString with empty string defaults to 'compose'
     */
    public function testSanitizeProjectStringEmptyStringDefaultsToCompose(): void
    {
        $result = \StackInfo::sanitizeProjectString('');
        $this->assertEquals('compose', $result);
    }

    /**
     * Test sanitizeProjectString with underscores (should be preserved)
     */
    public function testSanitizeProjectStringPreservesUnderscores(): void
    {
        $result = \StackInfo::sanitizeProjectString('my_stack_name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeProjectString with numbers
     */
    public function testSanitizeProjectStringWithNumbers(): void
    {
        $result = \StackInfo::sanitizeProjectString('Stack123.Test');
        $this->assertEquals('stack123_test', $result);
    }

    public function testPathIsAbsolutePathUnix(): void
    {
        $this->assertTrue(\Path::isAbsolutePath('/usr/local/bin'));
    }

    public function testPathIsAbsolutePathWindowsDrive(): void
    {
        $this->assertTrue(\Path::isAbsolutePath('C:\\Program Files\\Docker')); 
    }

    public function testPathIsAbsolutePathWindowsUnc(): void
    {
        $this->assertTrue(\Path::isAbsolutePath('\\\\server\\share\\compose.yml'));
    }

    public function testPathIsAbsolutePathRelative(): void
    {
        $this->assertFalse(\Path::isAbsolutePath('compose.yml'));
    }

    public function testPathRefersToSamePathNormalizesEquivalentPaths(): void
    {
        $tempDir = $this->createTempDir();
        $envFile = $tempDir . '/.env';
        file_put_contents($envFile, "KEY=value\n");

        $this->assertTrue(\Path::refersToSamePath($envFile, $tempDir . '/./.env'));
    }

    public function testPathIsAllowedPathMatchesRootAndChildren(): void
    {
        $tempDir = $this->createTempDir();
        $childDir = $tempDir . '/child';
        mkdir($childDir, 0755, true);
        $childFile = $childDir . '/compose.yaml';
        file_put_contents($childFile, "services:\n");
        $otherDir = $this->createTempDir();

        $this->assertTrue(\Path::isAllowedPath($tempDir, [$tempDir]));
        $this->assertTrue(\Path::isAllowedPath($childFile, [$tempDir]));
        $this->assertFalse(\Path::isAllowedPath($otherDir, [$tempDir]));
    }

    /**
     * Test sanitizeProjectString with multiple consecutive special chars (dashes preserved, underscores collapsed)
     */
    public function testSanitizeProjectStringMultipleSpecialChars(): void
    {
        $result = \StackInfo::sanitizeProjectString('my..stack--name  here');
        $this->assertEquals('my_stack-name_here', $result);
    }

    /**
     * Broad style-matrix: every rule exercised in one data-driven test so
     * PHP, shell (common.bats), and legacy-sed (compose.bats) results stay in sync.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sanitizeProjectStringMatrixProvider')]
    public function testSanitizeProjectStringMatrix(string $input, string $expected): void
    {
        $this->assertEquals($expected, \StackInfo::sanitizeProjectString($input));
    }

    /** @return array<string, array{string, string}> */
    public static function sanitizeProjectStringMatrixProvider(): array
    {
        return [
            // identity / casing
            'already valid lowercase'           => ['mystack',                        'mystack'],
            'uppercase only'                    => ['MyStack',                        'mystack'],
            'mixed alphanumeric'                => ['Stack2026v1',                    'stack2026v1'],
            'underscore preserved'              => ['my_stack_name',                  'my_stack_name'],
            'dash preserved'                    => ['my-stack-name',                  'my-stack-name'],
            'mixed valid separators'            => ['my_stack-v2',                    'my_stack-v2'],

            // space / dot replacement
            'single space'                      => ['my stack name',                  'my_stack_name'],
            'multiple spaces'                   => ['Stack With    Spaces',            'stack_with_spaces'],
            'dot replaced'                      => ['my.stack.name',                  'my_stack_name'],
            'many consecutive dots'             => ['name.with.many....dots',         'name_with_many_dots'],

            // separator collapsing
            'multiple underscores collapsed'    => ['my___stack',                     'my_stack'],
            'multiple dashes collapsed'         => ['AdGuard---Home',                 'adguard-home'],
            'mixed repeated separators'         => ['Prod__API---V2',                 'prod_api-v2'],
            'mixed A--B__C..D'                  => ['A--B__C..D',                     'a-b_c_d'],
            'combined space+dot+dash'           => ['My.Stack-Name Here',             'my_stack-name_here'],
            'consecutive specials all types'    => ['my..stack--name  here',          'my_stack-name_here'],

            // trim leading/trailing separators
            'leading dash'                      => ['-leading',                       'leading'],
            'trailing dash'                     => ['trailing-',                      'trailing'],
            'leading underscore'                => ['_leading_underscore',            'leading_underscore'],
            'trailing underscore'               => ['trailing_underscore_',           'trailing_underscore'],
            'leading+trailing mixed'            => ['-_My Stack_-',                   'my_stack'],
            'dots at boundaries'                => ['.My Stack.',                     'my_stack'],
            'complex boundary'                  => ['..-My Stack-Name-..',            'my_stack-name'],

            // special characters → underscore
            'plus and at'                       => ['Stack+Name@Home',                'stack_name_home'],
            'hash and exclamation'              => ['Stack#Prod!Beta',                'stack_prod_beta'],
            'parens and brackets'               => ['name(2026)[prod]{x}',            'name_2026_prod_x'],
            'comma semicolon equals'            => ['app,backup;v2=final',            'app_backup_v2_final'],
            'dollar caret ampersand'            => ['foo$bar^baz&qux',                'foo_bar_baz_qux'],
            'apostrophe'                        => ["rock'n'roll",                    'rock_n_roll'],
            'slash'                             => ['stack/name',                     'stack_name'],
            'colon'                             => ['stack:name',                     'stack_name'],
            'at with version'                   => ['stack@v2.0',                     'stack_v2_0'],
            'equals sign'                       => ['my.app=prod',                    'my_app_prod'],

            // all-separator / empty → fallback
            'only dashes'                       => ['---',                            'compose'],
            'only underscores'                  => ['___',                            'compose'],
            'only dots'                         => ['...',                            'compose'],
            'mixed separators only'             => ['---___...',                      'compose'],
            'whitespace only'                   => ['   ',                            'compose'],
            'empty string'                      => ['',                               'compose'],

            // real-world stack names
            'Immich'                            => ['Immich',                         'immich'],
            'AdGuard-Home'                      => ['AdGuard-Home',                   'adguard-home'],
            'Pihole v6'                         => ['Pihole v6',                      'pihole_v6'],
            'Jellyfin2025'                      => ['Jellyfin2025',                   'jellyfin2025'],
            'traefik-v3.0'                      => ['traefik-v3.0',                   'traefik-v3_0'],
            'Nginx_Proxy_Manager'               => ['Nginx_Proxy_Manager',            'nginx_proxy_manager'],
            'WordPress'                         => ['WordPress',                      'wordpress'],
            'Audible_Plex Downloader'           => ['Audible_Plex Downloader',        'audible_plex_downloader'],
            'Home_Assistant'                    => ['Home_Assistant',                 'home_assistant'],
            'Unifi_Network_Application'         => ['Unifi_Network_Application',      'unifi_network_application'],
        ];
    }

    /**
     * sanitizeProjectString must be idempotent: running it twice produces the same output.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sanitizeProjectStringMatrixProvider')]
    public function testSanitizeProjectStringIsIdempotent(string $input, string $_expected): void
    {
        $once  = \StackInfo::sanitizeProjectString($input);
        $twice = \StackInfo::sanitizeProjectString($once);
        $this->assertEquals($once, $twice, "Not idempotent for input '$input'");
    }

    // isIndirect() and getPath() were moved to StackInfo instance methods.
    // See StackInfoTest for indirect resolution coverage.

    /**
     * Test getStackLastResult returns null when no result file
     */
    public function testGetStackLastResultReturnsNullWhenNoFile(): void
    {
        $tempDir = $this->createTempDir();
        
        $result = getStackLastResult($tempDir);
        
        $this->assertNull($result);
    }

    /**
     * Test getStackLastResult returns parsed JSON when file exists
     */
    public function testGetStackLastResultReturnsJson(): void
    {
        $tempDir = $this->createTempDir();
        $resultData = [
            'result' => 'success',
            'exit_code' => 0,
            'operation' => 'up',
            'timestamp' => '2026-02-03T10:00:00-05:00'
        ];
        file_put_contents("$tempDir/last_result.json", json_encode($resultData));
        
        $result = getStackLastResult($tempDir);
        
        $this->assertIsArray($result);
        $this->assertEquals('success', $result['result']);
        $this->assertEquals(0, $result['exit_code']);
        $this->assertEquals('up', $result['operation']);
    }

    /**
     * Test getStackLastResult handles invalid JSON gracefully
     */
    public function testGetStackLastResultHandlesInvalidJson(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/last_result.json", 'not valid json');
        
        $result = getStackLastResult($tempDir);
        
        $this->assertNull($result);
    }

    public function testPruneOverrideContentServicesRemovesOrphanedServices(): void
    {
        $override = "services:\n" .
            "  app:\n" .
            "    labels:\n" .
            "      test: \"1\"\n" .
            "  old-service:\n" .
            "    labels:\n" .
            "      test: \"2\"\n";

        $result = pruneOverrideContentServices($override, ['app']);

        $this->assertTrue($result['changed']);
        $this->assertEquals(['old-service'], $result['removed']);
        $this->assertStringContainsString("  app:\n", $result['content']);
        $this->assertStringNotContainsString("  old-service:\n", $result['content']);
    }

    public function testPruneOverrideContentServicesPreservesContentWhenAllWouldBeOrphaned(): void
    {
        // When ALL services in override would be removed (e.g., rename scenario),
        // preserve the content to avoid data loss
        $override = "services:\n" .
            "  old-service:\n" .
            "    labels:\n" .
            "      test: \"2\"\n";

        $result = pruneOverrideContentServices($override, ['new-service']);

        // Should NOT change - all services being "orphaned" likely means rename
        $this->assertFalse($result['changed']);
        $this->assertEquals([], $result['removed']);
        $this->assertEquals($override, $result['content']);
    }

    public function testPruneOverrideContentServicesPreservesOnMultipleRenames(): void
    {
        // Simulates renaming multiple services - none match anymore
        $override = "services:\n" .
            "  mariadb:\n" .
            "    labels:\n" .
            "      net.unraid.docker.icon: 'icon.png'\n" .
            "  wordpress:\n" .
            "    labels:\n" .
            "      net.unraid.docker.webui: 'https://example.com'\n";

        // User renamed both services
        $result = pruneOverrideContentServices($override, ['mysql', 'wp']);

        // Should preserve - don't wipe user's webui/icon configs
        $this->assertFalse($result['changed']);
        $this->assertEquals([], $result['removed']);
        $this->assertStringContainsString('mariadb:', $result['content']);
        $this->assertStringContainsString('wordpress:', $result['content']);
    }

    public function testPruneOverrideContentServicesNoChangeWhenServicesMatch(): void
    {
        $override = "services:\n" .
            "  app:\n" .
            "    labels:\n" .
            "      test: \"1\"\n";

        $result = pruneOverrideContentServices($override, ['app']);

        $this->assertFalse($result['changed']);
        $this->assertEquals([], $result['removed']);
        $this->assertEquals($override, $result['content']);
    }

    // ===========================================
    // Service Rename Migration Tests
    // ===========================================

    public function testParseServicesFromYamlExtractsServiceNames(): void
    {
        $yaml = "services:\n" .
            "  mariadb:\n" .
            "    image: mariadb:latest\n" .
            "  wordpress:\n" .
            "    image: wordpress:6.0\n";

        $services = \OverrideInfo::parseServicesFromYaml($yaml);

        $this->assertCount(2, $services);
        $this->assertArrayHasKey('mariadb', $services);
        $this->assertArrayHasKey('wordpress', $services);
        $this->assertEquals('mariadb:latest', $services['mariadb']['image']);
        $this->assertEquals('wordpress:6.0', $services['wordpress']['image']);
    }

    public function testMigrateOnRenameDetectsByImage(): void
    {
        $oldCompose = "services:\n" .
            "  mariadb:\n" .
            "    image: mariadb:latest\n" .
            "  wordpress:\n" .
            "    image: wordpress:6.0\n";

        $newCompose = "services:\n" .
            "  mysql:\n" .
            "    image: mariadb:latest\n" .
            "  wp:\n" .
            "    image: wordpress:6.0\n";

        $override = "services:\n" .
            "  mariadb:\n" .
            "    labels:\n" .
            "      net.unraid.docker.icon: 'db-icon.png'\n" .
            "  wordpress:\n" .
            "    labels:\n" .
            "      net.unraid.docker.webui: 'https://example.com'\n";

        // Create temp stack directory
        $tempDir = sys_get_temp_dir();
        $stackName = 'test_stack_' . getmypid();
        $stackDir = $tempDir . '/' . $stackName;
        @mkdir($stackDir, 0755, true);
        file_put_contents($stackDir . '/compose.yaml', $oldCompose);
        file_put_contents($stackDir . '/compose.override.yaml', $override);

        try {
            $overrideInfo = \StackInfo::fromProject($tempDir, $stackName)->overrideInfo;
            $result = $overrideInfo->migrateOnRename($oldCompose, $newCompose);

            $this->assertTrue($result['migrated']);
            $this->assertCount(2, $result['migrations']);

            $newOverride = file_get_contents($stackDir . '/compose.override.yaml');
            $this->assertStringContainsString('mysql:', $newOverride);
            $this->assertStringContainsString('wp:', $newOverride);
            $this->assertStringNotContainsString('mariadb:', $newOverride);
            $this->assertStringNotContainsString('wordpress:', $newOverride);
            // Labels should be preserved
            $this->assertStringContainsString('db-icon.png', $newOverride);
            $this->assertStringContainsString('https://example.com', $newOverride);
        } finally {
            @unlink($stackDir . '/compose.yaml');
            @unlink($stackDir . '/compose.override.yaml');
            @rmdir($stackDir);
        }
    }

    public function testMigrateOnRenameNoChangeWhenNoRenames(): void
    {
        $compose = "services:\n" .
            "  mariadb:\n" .
            "    image: mariadb:latest\n";

        $override = "services:\n" .
            "  mariadb:\n" .
            "    labels:\n" .
            "      test: value\n";

        // Create temp stack directory
        $tempDir = sys_get_temp_dir();
        $stackName = 'test_stack_' . getmypid();
        $stackDir = $tempDir . '/' . $stackName;
        @mkdir($stackDir, 0755, true);
        file_put_contents($stackDir . '/compose.yaml', $compose);
        file_put_contents($stackDir . '/compose.override.yaml', $override);

        try {
            $overrideInfo = \StackInfo::fromProject($tempDir, $stackName)->overrideInfo;
            $result = $overrideInfo->migrateOnRename($compose, $compose);

            $this->assertFalse($result['migrated']);
            $this->assertEmpty($result['migrations']);
        } finally {
            @unlink($stackDir . '/compose.yaml');
            @unlink($stackDir . '/compose.override.yaml');
            @rmdir($stackDir);
        }
    }

    public function testMigrateOnRenameFallsBackToPositionalMatch(): void
    {
        // Services with no image or different images - should match positionally
        $oldCompose = "services:\n" .
            "  old_service:\n" .
            "    build: ./app\n";

        $newCompose = "services:\n" .
            "  new_service:\n" .
            "    build: ./app\n";

        $override = "services:\n" .
            "  old_service:\n" .
            "    labels:\n" .
            "      net.unraid.docker.icon: 'icon.png'\n";

        // Create temp stack directory
        $tempDir = sys_get_temp_dir();
        $stackName = 'test_stack_' . getmypid();
        $stackDir = $tempDir . '/' . $stackName;
        @mkdir($stackDir, 0755, true);
        file_put_contents($stackDir . '/compose.yaml', $oldCompose);
        file_put_contents($stackDir . '/compose.override.yaml', $override);

        try {
            $overrideInfo = \StackInfo::fromProject($tempDir, $stackName)->overrideInfo;
            $result = $overrideInfo->migrateOnRename($oldCompose, $newCompose);

            $this->assertTrue($result['migrated']);
            $this->assertCount(1, $result['migrations']);
            $this->assertEquals('old_service', $result['migrations'][0]['from']);
            $this->assertEquals('new_service', $result['migrations'][0]['to']);

            $newOverride = file_get_contents($stackDir . '/compose.override.yaml');
            $this->assertStringContainsString('new_service:', $newOverride);
            $this->assertStringNotContainsString('old_service:', $newOverride);
        } finally {
            @unlink($stackDir . '/compose.yaml');
            @unlink($stackDir . '/compose.override.yaml');
            @rmdir($stackDir);
        }
    }

    // ===========================================
    // Stack Locking Tests
    // ===========================================

    private string $testLockDir;

    protected function setUpLockTests(): void
    {
        // Use a temp directory for lock tests
        $this->testLockDir = sys_get_temp_dir() . '/compose_manager_test_' . getmypid();
        if (!is_dir($this->testLockDir)) {
            mkdir($this->testLockDir, 0755, true);
        }
        $GLOBALS['compose_lock_dir'] = $this->testLockDir;
    }

    protected function tearDownLockTests(): void
    {
        // Clean up lock files
        if (isset($this->testLockDir) && is_dir($this->testLockDir)) {
            $files = glob($this->testLockDir . '/*.lock');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testLockDir);
        }
        $GLOBALS['compose_lock_dir'] = null;
    }

    /**
     * Test acquireStackLock creates lock directory if needed
     */
    public function testAcquireStackLockCreatesDirectory(): void
    {
        $this->setUpLockTests();
        
        try {
            // Clean up any existing lock
            @unlink($this->testLockDir . '/test_stack.lock');
            
            $fp = acquireStackLock('test_stack', 1);
            
            $this->assertIsResource($fp);
            $this->assertDirectoryExists($this->testLockDir);
            
            releaseStackLock($fp);
        } finally {
            $this->tearDownLockTests();
        }
    }

    /**
     * Test acquireStackLock writes lock info
     */
    public function testAcquireStackLockWritesInfo(): void
    {
        $this->setUpLockTests();
        
        try {
            $lockFile = $this->testLockDir . '/test_stack_2.lock';
            
            // Clean up any existing lock
            @unlink($lockFile);
            
            $fp = acquireStackLock('test_stack_2', 1);
            $this->assertIsResource($fp);
            
            // Release the lock first so we can read the file
            releaseStackLock($fp);
            
            // Read lock content - file should still exist with the info
            $this->assertFileExists($lockFile);
            $content = file_get_contents($lockFile);
            $info = json_decode($content, true);
            
            $this->assertIsArray($info);
            $this->assertArrayHasKey('pid', $info);
            $this->assertArrayHasKey('time', $info);
            $this->assertArrayHasKey('stack', $info);
            $this->assertEquals('test_stack_2', $info['stack']);
        } finally {
            $this->tearDownLockTests();
        }
    }

    /**
     * Test isStackLocked returns false when not locked
     */
    public function testIsStackLockedReturnsFalseWhenNotLocked(): void
    {
        $this->setUpLockTests();
        
        try {
            // Use a unique stack name that shouldn't have a lock
            $result = isStackLocked('nonexistent_stack_' . time());
            
            $this->assertFalse($result);
        } finally {
            $this->tearDownLockTests();
        }
    }

    /**
     * Test releaseStackLock releases the lock
     */
    public function testReleaseStackLockReleasesLock(): void
    {
        $this->setUpLockTests();
        
        try {
            $fp = acquireStackLock('release_test', 1);
            $this->assertIsResource($fp);
            
            releaseStackLock($fp);
            
            // Should be able to acquire again immediately
            $fp2 = acquireStackLock('release_test', 1);
            $this->assertIsResource($fp2);
            releaseStackLock($fp2);
        } finally {
            $this->tearDownLockTests();
        }
    }

    /**
     * Test sanitizeStr is used for lock file naming
     */
    public function testLockFileUsesSanitizedName(): void
    {
        $this->setUpLockTests();
        
        try {
            // Stack name with special chars that sanitizeStr handles
            $fp = acquireStackLock('My.Stack-Name', 1);
            $this->assertIsResource($fp);
            
            // Lock file should use sanitized name (dashes preserved by sanitizeProjectString)
            $expectedFile = $this->testLockDir . '/my_stack-name.lock';
            $this->assertFileExists($expectedFile);
            
            releaseStackLock($fp);
        } finally {
            $this->tearDownLockTests();
        }
    }

    // ===========================================
    // isValidWebuiUrl Tests
    // ===========================================

    public function testIsValidWebuiUrlAcceptsPlainHttp(): void
    {
        $this->assertTrue(isValidWebuiUrl('http://192.168.1.1:8080'));
    }

    public function testIsValidWebuiUrlAcceptsHttps(): void
    {
        $this->assertTrue(isValidWebuiUrl('https://myserver.local:443/admin'));
    }

    public function testIsValidWebuiUrlAcceptsIpPlaceholder(): void
    {
        $this->assertTrue(isValidWebuiUrl('http://[IP]:8080/'));
    }

    public function testIsValidWebuiUrlAcceptsPortPlaceholderWithDefault(): void
    {
        $this->assertTrue(isValidWebuiUrl('http://[IP]:[PORT:8080]/'));
    }

    public function testIsValidWebuiUrlAcceptsBarePortPlaceholder(): void
    {
        $this->assertTrue(isValidWebuiUrl('http://[IP]:[PORT]/'));
    }

    public function testIsValidWebuiUrlAcceptsBothPlaceholders(): void
    {
        $this->assertTrue(isValidWebuiUrl('http://[IP]:[PORT:9090]/dashboard'));
    }

    public function testIsValidWebuiUrlRejectsJavascript(): void
    {
        $this->assertFalse(isValidWebuiUrl('javascript:alert(1)'));
    }

    public function testIsValidWebuiUrlRejectsNoScheme(): void
    {
        $this->assertFalse(isValidWebuiUrl('192.168.1.1:8080'));
    }

    public function testIsValidWebuiUrlRejectsFtp(): void
    {
        $this->assertFalse(isValidWebuiUrl('ftp://server/file'));
    }

    public function testIsValidWebuiUrlRejectsEmpty(): void
    {
        $this->assertFalse(isValidWebuiUrl(''));
    }
}
