<?php

/**
 * Unit Tests for Settings Page — Backup/Restore Settings
 * 
 * Tests the backup/restore section markup in compose.manager.settings.page.
 * Extends the existing SettingsPageTest with additional coverage for
 * backup destination, retention, scheduling, and restore UI elements.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

class SettingsBackupTest extends TestCase
{
    private string $settingsPagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsPagePath = __DIR__ . '/../../source/compose.manager/compose.manager.settings.page';
        $this->assertFileExists($this->settingsPagePath, 'settings page must exist');
    }

    private function getPageSource(): string
    {
        return file_get_contents($this->settingsPagePath);
    }

    // ===========================================
    // Backup Tab Structure Tests
    // ===========================================

    public function testBackupTabExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="compose-tab-backup"', $source);
    }

    public function testBackupSettingsSectionExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('Backup Settings', $source);
        $this->assertStringContainsString('fa-download', $source);
    }

    public function testRestoreOperationsSectionExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('Restore Operations', $source);
        $this->assertStringContainsString('fa-upload', $source);
    }

    // ===========================================
    // Backup Destination Tests
    // ===========================================

    public function testBackupDestinationInputExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="BACKUP_DESTINATION"', $source);
    }

    public function testBackupDestinationDefaultPlaceholder(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('placeholder="/boot/config/plugins/compose.manager/backups"', $source);
    }

    public function testBackupDestinationReadsFromConfig(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("\$cfg['BACKUP_DESTINATION']", $source);
    }

    public function testBackupDestinationHasBrowseCapability(): void
    {
        $source = $this->getPageSource();
        // Should have Unraid's file picker attributes
        $this->assertStringContainsString('data-pickroot="/"', $source);
        $this->assertStringContainsString('data-picktop="/boot/config/plugins/compose.manager"', $source);
        $this->assertStringContainsString('data-pickfolders="true"', $source);
    }

    // ===========================================
    // Backup Retention Tests
    // ===========================================

    public function testBackupRetentionInputExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="BACKUP_RETENTION"', $source);
    }

    public function testBackupRetentionDefaultValue(): void
    {
        $source = $this->getPageSource();
        // Default retention is 5
        $this->assertStringContainsString("\$cfg['BACKUP_RETENTION'] ?? '5'", $source);
    }

    public function testBackupRetentionIsNumberInput(): void
    {
        $source = $this->getPageSource();
        // Should be a number input with min/max bounds
        $this->assertStringContainsString('type="number" id="BACKUP_RETENTION"', $source);
        $this->assertStringContainsString('min="0"', $source);
        $this->assertStringContainsString('max="100"', $source);
    }

    // ===========================================
    // Backup Schedule Tests
    // ===========================================

    public function testScheduleEnabledCheckboxExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="BACKUP_SCHEDULE_ENABLED"', $source);
        $this->assertStringContainsString("class=\"compose-toggle\"", $source);
    }

    public function testScheduleEnabledDefaultFalse(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("(\$cfg['BACKUP_SCHEDULE_ENABLED'] ?? 'false')", $source);
    }

    public function testScheduleFrequencySelectExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="BACKUP_SCHEDULE_FREQUENCY"', $source);
        $this->assertStringContainsString('value="daily"', $source);
        $this->assertStringContainsString('value="weekly"', $source);
    }

    public function testScheduleFrequencyDefaultDaily(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("(\$cfg['BACKUP_SCHEDULE_FREQUENCY'] ?? 'daily')", $source);
    }

    public function testScheduleDaySelectExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="BACKUP_SCHEDULE_DAY"', $source);
        // Should have all 7 days
        $this->assertStringContainsString('Sunday', $source);
        $this->assertStringContainsString('Monday', $source);
        $this->assertStringContainsString('Saturday', $source);
    }

    public function testScheduleDayDefaultMonday(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("(\$cfg['BACKUP_SCHEDULE_DAY'] ?? '1')", $source);
    }

    public function testScheduleTimeInputExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="BACKUP_SCHEDULE_TIME"', $source);
        $this->assertStringContainsString('type="time"', $source);
    }

    public function testScheduleTimeDefault(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("\$cfg['BACKUP_SCHEDULE_TIME'] ?? '03:00'", $source);
    }

    // ===========================================
    // Backup Actions Tests
    // ===========================================

    public function testSaveBackupSettingsButtonExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="btn-save-backup-settings"', $source);
        $this->assertStringContainsString('saveBackupSettings()', $source);
    }

    public function testBackupNowButtonExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="btn-create-backup"', $source);
        $this->assertStringContainsString('createBackupNow()', $source);
    }

    public function testBackupCreateStatusDivExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="backup-create-status"', $source);
    }

    // ===========================================
    // Restore UI Tests
    // ===========================================

    public function testArchiveListTableExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="backup-archive-list"', $source);
    }

    public function testArchiveListColumns(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('Filename', $source);
        $this->assertStringContainsString('Size', $source);
        $this->assertStringContainsString('Date', $source);
    }

    public function testRefreshArchiveListButton(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('loadBackupArchives()', $source);
    }

    public function testUploadArchiveButton(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('uploadBackupArchive(', $source);
        $this->assertStringContainsString('id="backup-upload-input"', $source);
    }

    public function testDeleteSelectedBackupButton(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('deleteSelectedBackup()', $source);
    }

    public function testStackChecklistExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="restore-stack-checklist"', $source);
    }

    public function testRestoreButtonExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="btn-restore-stacks"', $source);
        $this->assertStringContainsString('restoreSelectedStacks()', $source);
    }

    public function testRestoreButtonDefaultDisabled(): void
    {
        $source = $this->getPageSource();
        // Restore button should be disabled until stacks are selected
        $this->assertMatchesRegularExpression('/id="btn-restore-stacks".*disabled/', $source);
    }

    public function testRestoreStatusDivExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="restore-status"', $source);
    }

    // ===========================================
    // Backup Settings JS (Save Function) Tests
    // ===========================================

    public function testSaveBackupSettingsSubmitsAllFields(): void
    {
        $source = $this->getPageSource();
        // The JS save function should submit all backup-related fields
        $this->assertStringContainsString("'BACKUP_DESTINATION': $('#BACKUP_DESTINATION').val()", $source);
        $this->assertStringContainsString("'BACKUP_RETENTION': $('#BACKUP_RETENTION').val()", $source);
        $this->assertStringContainsString("'BACKUP_SCHEDULE_ENABLED':", $source);
        $this->assertStringContainsString("'BACKUP_SCHEDULE_FREQUENCY':", $source);
        $this->assertStringContainsString("'BACKUP_SCHEDULE_TIME':", $source);
        $this->assertStringContainsString("'BACKUP_SCHEDULE_DAY':", $source);
    }

    // ===========================================
    // Tab System Tests
    // ===========================================

    public function testSettingsTabExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('compose-tab-settings', $source);
    }

    public function testLogTabExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('compose-tab-log', $source);
    }

    public function testAllThreeTabsPresent(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('id="compose-tab-settings"', $source);
        $this->assertStringContainsString('id="compose-tab-backup"', $source);
        $this->assertStringContainsString('id="compose-tab-log"', $source);
    }
}
