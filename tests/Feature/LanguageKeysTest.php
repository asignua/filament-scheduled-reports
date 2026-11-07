<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Enums\Frequency;
use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Enums\RunStatus;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Illuminate\Support\Arr;

class LanguageKeysTest extends TestCase
{
    public function test_every_key_the_code_uses_exists_in_english(): void
    {
        $english = require __DIR__.'/../../resources/lang/en/filament-scheduled-reports.php';
        $used = [];

        foreach ([...glob(__DIR__.'/../../src/*.php'), ...glob(__DIR__.'/../../src/*/*.php'), ...glob(__DIR__.'/../../src/*/*/*.php'), ...glob(__DIR__.'/../../src/*/*/*/*.php'), ...glob(__DIR__.'/../../resources/views/*/*.php')] as $file) {
            $code = (string) file_get_contents($file);

            preg_match_all("/(?:filament-scheduled-reports::filament-scheduled-reports\\.|\\\$lang\\.|\\\$lang\\s*\\.\\s*)'?([a-z_]+)'/", $code, $direct);
            $used = [...$used, ...$direct[1]];
            preg_match_all("/filament-scheduled-reports::filament-scheduled-reports\\.([a-z_]+)'/", $code, $quoted);
            $used = [...$used, ...$quoted[1]];
        }

        foreach (Frequency::cases() as $case) {
            $used[] = 'frequency_'.$case->value;
        }

        foreach (PauseReason::cases() as $case) {
            $used[] = 'pause_'.$case->value;
        }

        foreach (RunStatus::cases() as $case) {
            $used[] = 'run_'.$case->value;
        }

        $used = array_filter($used, static fn (string $key): bool => !str_ends_with($key, '_'));
        $missing = array_diff(array_unique($used), array_keys($english));

        $this->assertSame([], array_values($missing), 'Used but missing in resources/lang/en: '.implode(', ', $missing));
    }

    public function test_translated_placeholders_match_english(): void
    {
        $english = Arr::dot(require __DIR__.'/../../resources/lang/en/filament-scheduled-reports.php');

        foreach (['de', 'es', 'fr', 'it', 'nl', 'pl', 'pt_BR', 'tr', 'uk'] as $locale) {
            $translated = Arr::dot(require __DIR__.'/../../resources/lang/'.$locale.'/filament-scheduled-reports.php');

            foreach ($english as $key => $text) {
                preg_match_all('/:[a-z]+/', $text, $a);
                preg_match_all('/:[a-z]+/', $translated[$key], $b);
                sort($a[0]);
                sort($b[0]);

                $this->assertSame($a[0], $b[0], "Placeholders of [{$locale}.{$key}] differ from English.");
            }
        }
    }
}
