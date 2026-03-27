<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

class SettingsPageTest extends TestCase
{
    public function testSettingsPageIncludesHideCheckboxDefaultFalse(): void
    {
        // Ensure no explicit setting (defaults used)
        $this->mockPluginConfig('compose.manager', [
            // other keys as required by the page may stay default
        ]);

        // Instead of executing the page (which depends on Unraid runtime), assert the page source contains the expected input markup
        $this->assertFileExists(__DIR__ . '/../../source/compose.manager/compose.manager.settings.page');
        $page = file_get_contents(__DIR__ . '/../../source/compose.manager/compose.manager.settings.page');
        $this->assertIsString($page);

        $this->assertStringContainsString('name="HIDE_COMPOSE_FROM_DOCKER"', $page);
        // Default value expression should be present
        $this->assertStringContainsString("\$cfg['HIDE_COMPOSE_FROM_DOCKER'] ?? 'false'", $page);
    }
    public function testSettingsPageSetsDoneReturnCookie(): void
    {
        $this->assertFileExists(__DIR__ . '/../../source/compose.manager/compose.manager.settings.page');
        $page = file_get_contents(__DIR__ . '/../../source/compose.manager/compose.manager.settings.page');
        $this->assertStringContainsString("var cookieName = 'compose_manager_settings_return'", $page);
        $this->assertStringContainsString("window.done = function(k)", $page);
    }
    public function testSettingsPageReflectsConfigTrue(): void
    {
        // Set config to true
        $this->mockPluginConfig('compose.manager', [
            'HIDE_COMPOSE_FROM_DOCKER' => 'true',
        ]);

        $this->assertFileExists(__DIR__ . '/../../source/compose.manager/compose.manager.settings.page');
        $page = file_get_contents(__DIR__ . '/../../source/compose.manager/compose.manager.settings.page');
        $this->assertIsString($page);

        // The input uses an inline PHP expression to render 'checked' when the config equals 'true'
        $this->assertStringContainsString("(\$cfg['HIDE_COMPOSE_FROM_DOCKER'] ?? 'false') == 'true' ? 'checked' : ''", $page);
    }
}
