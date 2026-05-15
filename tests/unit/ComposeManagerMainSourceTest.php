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

    public function testInvalidJsonSaveResponseIsTreatedAsFailure(): void
    {
        $source = $this->getJsSource();
        $this->assertStringContainsString('Failed to save ' . "' + saveTarget + '" . '. Invalid server response.', $source);
        $this->assertStringContainsString('return false;', $source);
    }

    public function testManualModeSaveAllUsesPartialSaveWarning(): void
    {
        $source = $this->getJsSource();
        $this->assertStringContainsString('var skippedManualLabels = false;', $source);
        $this->assertStringContainsString('skippedManualLabels = true;', $source);
        $this->assertStringContainsString('title: "Partially Saved"', $source);
        $this->assertStringContainsString('Non-label changes were saved.', $source);
    }

    public function testOverrideManagementModePersistsOnSaveSettings(): void
    {
        $phpSource = $this->getPhpSource();
        $jsSource = $this->getJsSource();

        $this->assertStringContainsString('id="settings-override-management"', $phpSource);
        $this->assertStringNotContainsString('id="settings-override-management" onchange=', $phpSource);
        $this->assertStringContainsString("editorModal.modifiedSettings.has('labels-view-mode')", $jsSource);
        $this->assertStringContainsString("editorModal.pendingLabelsViewMode = mode;", $jsSource);
        $this->assertStringContainsString("action: 'setLabelsViewMode'", $jsSource);
        $this->assertStringContainsString("editorModal.originalSettings['labels-view-mode'] = labelsViewMode;", $jsSource);
        $this->assertStringContainsString("toggleLabelsViewMode(labelsViewMode === 'advanced', true);", $jsSource);
        $this->assertStringContainsString("$('#editor-tab-labels-text').text(labelsViewMode === 'advanced' ? 'Override' : 'Labels');", $jsSource);
    }

    public function testEditorHasOkayApplyCloseButtonsAndChangeCounter(): void
    {
        $phpSource = $this->getPhpSource();
        $jsSource = $this->getJsSource();

        $this->assertStringContainsString('id="editor-change-count"', $phpSource);
        $this->assertStringContainsString('id="editor-btn-okay"', $phpSource);
        $this->assertStringContainsString('onclick="handleOkayAction()"', $phpSource);
        $this->assertStringContainsString('id="editor-btn-apply"', $phpSource);
        $this->assertStringContainsString('onclick="saveAllChanges(false)"', $phpSource);
        $this->assertStringContainsString('id="editor-btn-close"', $phpSource);
        $this->assertStringContainsString("function handleOkayAction()", $jsSource);
        $this->assertStringContainsString("saveAllChanges(true);", $jsSource);
        $this->assertStringContainsString("doCloseEditorModal();", $jsSource);
        $this->assertStringContainsString("function saveAllChanges(closeAfterSave)", $jsSource);
        $this->assertStringContainsString("$('#editor-btn-apply').prop('disabled', !hasChanges);", $jsSource);
        $this->assertStringContainsString("$('#editor-change-count').text(totalChanges + (totalChanges === 1 ? ' change' : ' changes'));", $jsSource);
        $this->assertStringContainsString("promptRecreateContainers(closeAfterSave);", $jsSource);
    }

    public function testSaveAllChangesDefaultsToApplyMode(): void
    {
        $source = $this->getJsSource();
        $this->assertStringContainsString('function saveAllChanges(closeAfterSave) {', $source);
        $this->assertStringContainsString("if (typeof closeAfterSave === 'undefined') {", $source);
        $this->assertStringContainsString('closeAfterSave = false;', $source);
    }

    public function testHandleOkayActionSavesAndClosesWhenModified(): void
    {
        $source = $this->getJsSource();

        $this->assertMatchesRegularExpression(
            '/function\\s+handleOkayAction\\s*\\(\\)\\s*\\{[\\s\\S]*?if\\s*\\(totalChanges\\s*>\\s*0\\)\\s*\\{[\\s\\S]*?saveAllChanges\\(true\\);[\\s\\S]*?\\}\\s*else\\s*\\{[\\s\\S]*?doCloseEditorModal\\(\\);/m',
            $source,
            'Okay must save with closeAfterSave=true and close immediately when no changes.'
        );
    }

    public function testNonLabelSaveOnlyClosesWhenRequested(): void
    {
        $source = $this->getJsSource();

        $this->assertMatchesRegularExpression(
            '/if\\s*\\(closeAfterSave\\)\\s*\\{\\s*doCloseEditorModal\\(\\);\\s*\\}[\\s\\S]*?refreshStackByProject\\(project\\);/m',
            $source,
            'Non-label success path should only close modal when closeAfterSave=true.'
        );
    }

    public function testApplyLabelSaveSkipsRecreatePromptAndKeepsModalOpen(): void
    {
        $source = $this->getJsSource();

        $this->assertMatchesRegularExpression(
            '/function\\s+promptRecreateContainers\\s*\\(closeAfterSave\\)\\s*\\{[\\s\\S]*?if\\s*\\(!closeAfterSave\\)\\s*\\{[\\s\\S]*?title:\\s*\"Saved!\"[\\s\\S]*?recreate or restart containers to apply the changes\\.[\\s\\S]*?refreshStackByProject\\(project\\);[\\s\\S]*?return;[\\s\\S]*?\\}/mi',
            $source,
            'Apply flow after label save should show informational message and avoid recreate-confirm dialog.'
        );
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
