<?php

/**
 * Unit Tests for Compose Manager Utility Functions (REAL SOURCE)
 * 
 * Tests the actual source file: source/compose.manager/php/util.php
 * Uses stream wrapper to redirect Unraid paths to local files.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

// Load the actual source file via stream wrapper
require_once '/usr/local/emhttp/plugins/compose.manager/php/util.php';

class UtilTest extends TestCase
{
    /**
     * Test sanitizeStr removes dots
     */
    public function testSanitizeStrRemovesDots(): void
    {
        $result = sanitizeStr('my.stack.name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeStr removes spaces
     */
    public function testSanitizeStrRemovesSpaces(): void
    {
        $result = sanitizeStr('my stack name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeStr removes dashes
     */
    public function testSanitizeStrRemovesDashes(): void
    {
        $result = sanitizeStr('my-stack-name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeStr converts to lowercase
     */
    public function testSanitizeStrConvertsToLowercase(): void
    {
        $result = sanitizeStr('MyStackName');
        $this->assertEquals('mystackname', $result);
    }

    /**
     * Test sanitizeStr handles combined cases
     */
    public function testSanitizeStrCombined(): void
    {
        $result = sanitizeStr('My.Stack-Name Here');
        $this->assertEquals('my_stack_name_here', $result);
    }

    /**
     * Test sanitizeStr with empty string
     */
    public function testSanitizeStrEmptyString(): void
    {
        $result = sanitizeStr('');
        $this->assertEquals('', $result);
    }

    /**
     * Test sanitizeStr with underscores (should be preserved)
     */
    public function testSanitizeStrPreservesUnderscores(): void
    {
        $result = sanitizeStr('my_stack_name');
        $this->assertEquals('my_stack_name', $result);
    }

    /**
     * Test sanitizeStr with numbers
     */
    public function testSanitizeStrWithNumbers(): void
    {
        $result = sanitizeStr('Stack123.Test');
        $this->assertEquals('stack123_test', $result);
    }

    /**
     * Test sanitizeStr with multiple consecutive special chars
     */
    public function testSanitizeStrMultipleSpecialChars(): void
    {
        $result = sanitizeStr('my..stack--name  here');
        $this->assertEquals('my__stack__name__here', $result);
    }

    /**
     * Test isIndirect returns false when no indirect file exists
     */
    public function testIsIndirectReturnsFalseWhenNoFile(): void
    {
        $tempDir = $this->createTempDir();
        
        $result = isIndirect($tempDir);
        
        $this->assertFalse($result);
    }

    /**
     * Test isIndirect returns true when indirect file exists
     */
    public function testIsIndirectReturnsTrueWhenFileExists(): void
    {
        $tempDir = $this->createTempDir();
        file_put_contents("$tempDir/indirect", '/mnt/user/appdata/realpath');
        
        $result = isIndirect($tempDir);
        
        $this->assertTrue($result);
    }

    /**
     * Test getPath returns basePath when not indirect
     */
    public function testGetPathReturnsBasePathWhenNotIndirect(): void
    {
        $tempDir = $this->createTempDir();
        
        $result = getPath($tempDir);
        
        $this->assertEquals($tempDir, $result);
    }

    /**
     * Test getPath returns indirect content when indirect file exists
     */
    public function testGetPathReturnsIndirectContent(): void
    {
        $tempDir = $this->createTempDir();
        $targetPath = '/mnt/user/appdata/mystack';
        file_put_contents("$tempDir/indirect", $targetPath);
        
        $result = getPath($tempDir);
        
        $this->assertEquals($targetPath, $result);
    }

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

    public function testPruneOverrideContentServicesSetsEmptyServicesMapWhenAllOrphaned(): void
    {
        $override = "services:\n" .
            "  old-service:\n" .
            "    labels:\n" .
            "      test: \"2\"\n";

        $result = pruneOverrideContentServices($override, ['app']);

        $this->assertTrue($result['changed']);
        $this->assertEquals(['old-service'], $result['removed']);
        $this->assertStringContainsString('services: {}', $result['content']);
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
            
            // Lock file should use sanitized name
            $expectedFile = $this->testLockDir . '/my_stack_name.lock';
            $this->assertFileExists($expectedFile);
            
            releaseStackLock($fp);
        } finally {
            $this->tearDownLockTests();
        }
    }
}
