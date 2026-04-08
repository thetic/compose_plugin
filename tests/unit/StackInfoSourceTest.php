<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

class StackInfoSourceTest extends TestCase
{
    private string $utilPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->utilPath = __DIR__ . '/../../source/compose.manager/php/util.php';
        $this->assertFileExists($this->utilPath, 'util.php must exist');
    }

    private function getSource(): string
    {
        return file_get_contents($this->utilPath);
    }

    public function testMainFileServicesUsesAllProfilesForOverridePruning(): void
    {
        $source = $this->getSource();
        $this->assertStringContainsString('private function getMainFileServices(): array', $source);
        $this->assertStringContainsString("--profile '*' config --services", $source);
        $this->assertStringContainsString('profile-tagged services remain', $source);
    }
}