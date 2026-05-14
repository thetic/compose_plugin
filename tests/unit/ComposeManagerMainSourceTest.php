<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

/**
 * Source-level tests for ComposeManager.php.
 *
 * These verify key CPU/memory load logic markers in the page source so
 * regressions are caught even when the page cannot be executed in unit tests.
 */
class ComposeManagerMainSourceTest extends TestCase
{
    private string $mainPagePath;
    private string $mainScriptPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mainPagePath = __DIR__ . '/../../source/compose.manager/include/ComposeManager.php';
        $this->mainScriptPath = __DIR__ . '/../../source/compose.manager/javascript/composeManagerMain.js';
        $this->assertFileExists($this->mainPagePath, 'ComposeManager.php must exist');
        $this->assertFileExists($this->mainScriptPath, 'composeManagerMain.js must exist');
    }

    private function getPhpSource(): string
    {
        return file_get_contents($this->mainPagePath);
    }

    private function getJsSource(): string
    {
        return file_get_contents($this->mainScriptPath);
    }

    public function testCpuSpecCountHelperExists(): void
    {
        $source = $this->getPhpSource();
        $this->assertStringContainsString('function compose_manager_cpu_spec_count($cpuSpec)', $source);
        $this->assertStringContainsString('explode(\',\', trim((string)$cpuSpec))', $source);
    }

    public function testCpuCountSumsAllCpuSpecs(): void
    {
        $source = $this->getPhpSource();
        $this->assertStringContainsString('$cpuCount = 0;', $source);
        $this->assertStringContainsString('foreach ($cpus as $cpuSpec)', $source);
        $this->assertStringContainsString('$cpuCount += compose_manager_cpu_spec_count($cpuSpec);', $source);
    }

    public function testCpuCountHasFallbackGuards(): void
    {
        $source = $this->getPhpSource();
        $this->assertStringContainsString("trim(shell_exec('nproc 2>/dev/null') ?: '1')", $source);
        $this->assertStringContainsString('if ($cpuCount <= 0) {', $source);
        $this->assertStringContainsString('$cpuCount = 1;', $source);
    }

    public function testStackAggregationTracksMemoryLimits(): void
    {
        $source = $this->getJsSource();
        $this->assertStringContainsString('var totalMemLimitBytes = 0;', $source);
        $this->assertStringContainsString('totalMemLimitBytes += composeLoadById[ctId].memLimitBytes || 0;', $source);
        $this->assertStringContainsString('var stackMemTotalBytes = 0;', $source);
        $this->assertStringContainsString('stackMemTotalBytes = Math.min(totalMemLimitBytes, composeSystemMemBytes);', $source);
    }

    public function testDockerLoadMapStoresParsedLimitBytes(): void
    {
        $source = $this->getJsSource();
        $this->assertStringContainsString('var memPair = parseMemUsagePair(parts[2]);', $source);
        $this->assertStringContainsString('memLimitBytes: memPair.limit,', $source);
    }

    public function testComposeCustomTagSchemaSupportIsDeclared(): void
    {
        $source = $this->getJsSource();
        $this->assertStringContainsString("var customTags = ['!override', '!reset', '!merge'];", $source);
        $this->assertStringContainsString('function buildComposeYamlSchema()', $source);
        $this->assertStringContainsString("if (typeof jsyaml !== 'undefined') {", $source);
        $this->assertStringContainsString("throw new Error('YAML parser is unavailable. Please reload the page and try again.');", $source);
    }

    public function testLabelSaveBlocksTaggedOverrideRewrite(): void
    {
        $source = $this->getJsSource();
        $this->assertStringContainsString('overrideHasCustomTags: composeYamlContainsCustomTags(overrideData.content || \'\')', $source);
        $this->assertStringContainsString('WebUI labels cannot be saved because compose.override.yaml uses !override, !reset, or !merge tags.', $source);
    }

    // ===========================================
    // Regression Tests
    // ===========================================

    /**
     * Regression: Running stacks with no update data must show "check for updates"
     * (triggers checkStackUpdates) not "pull updates" (triggers showUpdateWarning).
     */
    public function testUncheckedRunningStackShowsCheckForUpdates(): void
    {
        $source = $this->getJsSource();
        // The else branch for no containers must call checkStackUpdates
        $this->assertStringContainsString(
            "onclick=\"checkStackUpdates(\\'' + composeEscapeAttr(stackName) + '\\');\"",
            $source,
            'Unchecked running stacks must trigger checkStackUpdates, not showUpdateWarning'
        );
        $this->assertStringContainsString('check for updates</a>', $source);
    }

    /**
     * Regression: Stopped stacks must always show "stopped" in the update column,
     * regardless of whether prior update check data exists. The early return
     * must be unconditional on !isRunning.
     */
    public function testStoppedStacksReturnEarlyUnconditionally(): void
    {
        $source = $this->getJsSource();
        // The stopped check should NOT reference hasCheckedData — it must be
        // a simple !isRunning guard.
        $this->assertStringNotContainsString('!isRunning && !hasCheckedData', $source,
            'Stopped stack early return must be unconditional (!isRunning only, not gated on hasCheckedData)');
    }

}
