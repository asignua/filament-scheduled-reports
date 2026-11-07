<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Filament\Facades\Filament;

class SmokeTest extends TestCase
{
    public function test_the_panel_boots(): void
    {
        $this->assertSame('admin', Filament::getCurrentPanel()?->getId());
    }

    public function test_the_plugin_is_registered_on_the_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasPlugin('asignua-filament-scheduled-reports'));
        $this->assertInstanceOf(ScheduledReportsPlugin::class, $panel->getPlugin('asignua-filament-scheduled-reports'));
    }

    public function test_the_translations_are_loaded(): void
    {
        $this->assertSame('Scheduled reports', __('filament-scheduled-reports::filament-scheduled-reports.plural'));
    }
}
