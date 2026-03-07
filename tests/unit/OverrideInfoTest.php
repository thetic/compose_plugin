<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

// Load the actual source file via stream wrapper
require_once '/usr/local/emhttp/plugins/compose.manager/php/util.php';

class OverrideInfoTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = $this->createTempDir();
    }

    public function testFromStackCreatesInstance(): void
    {
        $stack = 'teststack';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertInstanceOf(\OverrideInfo::class, $info);
    }

    public function testComputedNameDefault(): void
    {
        $stack = 'stack1';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertStringContainsString('override', $info->computedName);
    }

    public function testProjectOverridePath(): void
    {
        $stack = 'stack2';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertStringContainsString($stackDir, $info->projectOverride);
    }

    public function testIndirectOverridePath(): void
    {
        $stack = 'stack3';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $indirectTarget = $this->tempRoot . '/indirect_target';
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertEquals($indirectTarget . '/' . $info->computedName, $info->indirectOverride);
    }

    public function testUseIndirectTrueWhenIndirectOverrideExists(): void
    {
        $stack = 'stack4';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $indirectTarget = $this->tempRoot . '/indirect_target2';
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);
        $overridePath = $indirectTarget . '/compose.override.yaml';
        file_put_contents($overridePath, '# override');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertTrue($info->useIndirect);
    }

    public function testMismatchIndirectLegacy(): void
    {
        $stack = 'stack5';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $indirectTarget = $this->tempRoot . '/indirect_target3';
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);
        $legacyPath = $indirectTarget . '/docker-compose.override.yml';
        file_put_contents($legacyPath, '# legacy');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertTrue($info->mismatchIndirectLegacy);
    }

    public function testGetOverridePathPrefersIndirect(): void
    {
        $stack = 'stack6';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $indirectTarget = $this->tempRoot . '/indirect_target4';
        mkdir($indirectTarget);
        file_put_contents($stackDir . '/indirect', $indirectTarget);
        $overridePath = $indirectTarget . '/compose.override.yaml';
        file_put_contents($overridePath, '# override');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertEquals($overridePath, $info->getOverridePath());
    }

    public function testGetOverridePathFallsBackToProject(): void
    {
        $stack = 'stack7';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $overridePath = $stackDir . '/compose.override.yaml';
        file_put_contents($overridePath, '# override');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertEquals($overridePath, $info->getOverridePath());
    }

    public function testLegacyProjectOverrideMigration(): void
    {
        $stack = 'stack8';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $legacyPath = $stackDir . '/docker-compose.override.yml';
        file_put_contents($legacyPath, '# legacy');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertFileDoesNotExist($legacyPath);
        $this->assertFileExists($info->projectOverride);
    }

    public function testLegacyProjectOverrideStaleRemoval(): void
    {
        $stack = 'stack9';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $legacyPath = $stackDir . '/docker-compose.override.yml';
        $computedPath = $stackDir . '/compose.override.yaml';
        file_put_contents($legacyPath, '# legacy');
        file_put_contents($computedPath, '# computed');
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertFileExists($computedPath);
        $this->assertFileDoesNotExist($legacyPath);
        $this->assertFileExists($legacyPath . '.bak');
    }

    // ===========================================
    // composeFilePath Tests
    // ===========================================

    public function testComposeFilePathIsNullWhenNoComposeFile(): void
    {
        $stack = 'no-compose';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertNull($info->composeFilePath);
    }

    public function testComposeFilePathIsSetWhenComposeFileExists(): void
    {
        $stack = 'has-compose';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertEquals("$stackDir/compose.yaml", $info->composeFilePath);
    }

    public function testComposeFilePathResolvesIndirect(): void
    {
        $stack = 'indirect-compose';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        $indirectTarget = $this->tempRoot . '/indirect_compose_target';
        mkdir($indirectTarget);
        file_put_contents("$stackDir/indirect", $indirectTarget);
        file_put_contents("$indirectTarget/docker-compose.yml", "services:\n  app:\n    image: redis\n");
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $this->assertEquals("$indirectTarget/docker-compose.yml", $info->composeFilePath);
    }

    // ===========================================
    // pruneOrphanServices Tests
    // ===========================================

    public function testPruneOrphanServicesReturnsUnchangedWhenNoOverride(): void
    {
        $stack = 'prune-no-override';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        // Delete the auto-created override so there's nothing to prune
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $overridePath = $info->getOverridePath();
        if ($overridePath && is_file($overridePath)) {
            unlink($overridePath);
        }
        // Re-create info without the override file existing
        $info2 = new \ReflectionClass(\OverrideInfo::class);
        // Just test via the public API by removing the override file
        $result = $info->pruneOrphanServices();
        $this->assertFalse($result['changed']);
        $this->assertEquals([], $result['removed']);
    }

    public function testPruneOrphanServicesRemovesStaleEntries(): void
    {
        $stack = 'prune-stale';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n");
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $overridePath = $info->getOverridePath();

        // Write an override with an orphaned service
        $overrideContent = "services:\n" .
            "  web:\n" .
            "    labels:\n" .
            "      test: \"1\"\n" .
            "  deleted-svc:\n" .
            "    labels:\n" .
            "      test: \"2\"\n";
        file_put_contents($overridePath, $overrideContent);

        $result = $info->pruneOrphanServices();

        $this->assertTrue($result['changed']);
        $this->assertEquals(['deleted-svc'], $result['removed']);

        // Verify override file was updated
        $newContent = file_get_contents($overridePath);
        $this->assertStringContainsString("  web:\n", $newContent);
        $this->assertStringNotContainsString("  deleted-svc:\n", $newContent);
    }

    public function testPruneOrphanServicesNoChangeWhenAllValid(): void
    {
        $stack = 'prune-valid';
        $stackDir = $this->tempRoot . '/' . $stack;
        mkdir($stackDir);
        file_put_contents("$stackDir/compose.yaml", "services:\n  web:\n    image: nginx\n  api:\n    image: node\n");
        $info = \OverrideInfo::fromStack($this->tempRoot, $stack);
        $overridePath = $info->getOverridePath();

        $overrideContent = "services:\n" .
            "  web:\n" .
            "    labels:\n" .
            "      test: \"1\"\n" .
            "  api:\n" .
            "    labels:\n" .
            "      test: \"2\"\n";
        file_put_contents($overridePath, $overrideContent);

        $result = $info->pruneOrphanServices();

        $this->assertFalse($result['changed']);
        $this->assertEquals([], $result['removed']);
    }
}
