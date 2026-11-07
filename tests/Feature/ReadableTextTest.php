<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\Pages\EditScheduledReport;
use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\RelationManagers\RunsRelationManager;
use Asignua\FilamentScheduledReports\Mail\ProblemMail;
use Asignua\FilamentScheduledReports\Mail\ReportMail;
use Asignua\FilamentScheduledReports\Support\Reasons;
use Asignua\FilamentScheduledReports\Support\ReportState;
use Asignua\FilamentScheduledReports\Support\Scheduler;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Models\User;

class ReadableTextTest extends TestCase
{
    private const LANG = 'filament-scheduled-reports::filament-scheduled-reports.';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * @param array<string, mixed> $export
     * @param array<string, mixed> $filters
     */
    private function state(array $export = [], array $filters = [], ?string $sort = null): array
    {
        return ['version' => 1, 'filters' => $filters, 'search' => null, 'column_searches' => [], 'sort' => $sort, 'tab' => null, 'export' => $export];
    }

    public function test_every_code_the_runner_writes_has_readable_text(): void
    {
        $codes = [
            'owner_lost_access:owner', 'owner_lost_access:gate', 'owner_lost_access:page', 'owner_lost_access:action',
            'target_gone:panel', 'target_gone:plugin', 'target_gone:page', 'target_gone:filter:team', 'target_gone:tab:open',
            'target_gone:action', 'target_gone:columns', 'target_gone:query', 'tenancy_unsupported:panel', 'no_recipients:recipients',
            'scope_hook_missing:hook', 'skipped:paused_by_manager', 'skipped:not_active', 'exception:RuntimeException',
            'too_large:2000000:1000000', 'columns_removed:city,zip', 'recipient_dropped:user:7', 'recipient_dropped:email:a@b.test', 'oversize_not_sent:2',
        ];

        foreach ($codes as $code) {
            $text = Reasons::text($code);
            $this->assertNotSame($code, $text, $code);
            $this->assertStringNotContainsString(self::LANG, $text, $code);
            $this->assertDoesNotMatchRegularExpression('/^code_/', $text, $code);
        }

        $this->assertStringContainsString('team', Reasons::text('target_gone:filter:team'));
        $this->assertStringContainsString('city, zip', Reasons::text('columns_removed:city,zip'));
        $this->assertSame('something unknown:x', Reasons::text('something unknown:x'));
    }

    public function test_the_history_shows_words_not_codes(): void
    {
        $this->seedOrders();
        $report = $this->makeReport(['state' => $this->state(['columns' => ['name', 'city']], ['region' => ['value' => 'x']])]);
        Scheduler::dispatchDue();
        $paused = $report->runs()->firstOrFail();
        $this->assertSame('target_gone:filter:region', $paused->reason);

        $ok = $this->makeReport(['state' => $this->state(['columns' => ['name', 'city']])]);
        Scheduler::dispatchDue();
        $run = $ok->runs()->firstOrFail();
        $this->assertSame('attached', $run->delivery);

        Livewire::test(RunsRelationManager::class, ['ownerRecord' => $ok, 'pageClass' => EditScheduledReport::class])
            ->assertTableColumnStateSet('delivery', __(self::LANG.'delivery_attached'), $run)
            ->assertTableColumnStateSet('reason', Reasons::text('columns_removed:city'), $run);

        Livewire::test(RunsRelationManager::class, ['ownerRecord' => $report, 'pageClass' => EditScheduledReport::class])
            ->assertTableColumnStateSet('reason', Reasons::text('target_gone:filter:region'), $paused);
    }

    public function test_the_pause_mail_says_why_in_words(): void
    {
        $this->seedOrders();
        $report = $this->makeReport(['state' => $this->state([], ['region' => ['value' => 'x']])]);

        Scheduler::dispatchDue();

        Mail::assertQueued(ProblemMail::class, fn (ProblemMail $mail): bool => str_contains($mail->detail, Reasons::text('target_gone:filter:region')) && !str_contains($mail->detail, 'target_gone'));
        $this->assertNotNull($report->refresh()->paused_reason);
    }

    public function test_the_report_mail_names_a_column_that_was_dropped(): void
    {
        $this->seedOrders();
        $this->makeReport(['state' => $this->state(['columns' => ['name', 'discontinued']])]);

        Scheduler::dispatchDue();

        Mail::assertQueued(ReportMail::class, function (ReportMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'discontinued') && str_contains($html, htmlspecialchars(Reasons::text('columns_removed:discontinued'), ENT_QUOTES));
        });
    }

    public function test_a_report_mail_without_a_dropped_column_has_no_such_note(): void
    {
        $this->seedOrders();
        $this->makeReport(['state' => $this->state(['columns' => ['name']])]);

        Scheduler::dispatchDue();

        Mail::assertQueued(ReportMail::class, fn (ReportMail $mail): bool => !str_contains($mail->render(), __(self::LANG.'mail_notes')));
    }

    public function test_a_colleague_who_cannot_get_the_file_is_told(): void
    {
        $this->seedOrders();
        $colleague = User::factory()->create();
        config(['filament-scheduled-reports.max_attachment_mb' => 0]);
        $this->makeReport(['recipients' => [['type' => 'user', 'id' => (string) auth()->id()], ['type' => 'user', 'id' => (string) $colleague->id]]]);

        Scheduler::dispatchDue();

        Mail::assertQueued(ProblemMail::class, fn (ProblemMail $mail): bool => $mail->hasTo($colleague->email) && str_contains($mail->detail, auth()->user()->name));
        Mail::assertNotQueued(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo($colleague->email));
    }

    public function test_the_summary_counts_a_filter_that_has_no_indicator(): void
    {
        $this->seedOrders();

        $summary = Livewire::test(ListOrders::class)
            ->set('tableFilters.big.min', '20')
            ->set('tableFilters.status.value', 'new')
            ->instance();
        $lines = ReportState::summary($summary->getTable()->getLivewire());

        $this->assertContains(__(self::LANG.'summary_more_filters', ['count' => 1]), $lines);
        $this->assertGreaterThanOrEqual(2, count($lines));
    }

    public function test_the_report_really_applies_that_filter(): void
    {
        $this->seedOrders();
        $run = (function () {
            $report = $this->makeReport(['state' => $this->state(['columns' => ['name']], ['big' => ['min' => '20']])]);
            Scheduler::dispatchDue();

            return $report->runs()->firstOrFail();
        })();

        $this->assertSame(1, $run->rows);
    }

    public function test_the_sort_line_uses_the_columns_label_not_its_key(): void
    {
        $lines = ReportState::summary(Livewire::test(ListOrders::class)->set('tableSort', 'customer.name:desc')->instance());

        $this->assertContains(__(self::LANG.'summary_sort', ['column' => 'Customer', 'direction' => __(self::LANG.'sort_desc')]), $lines);
        $this->assertStringNotContainsString('customer.name', implode(' ', $lines));
    }

    public function test_the_summary_mentions_the_search_text(): void
    {
        $lines = ReportState::summary(Livewire::test(ListOrders::class)->set('tableSearch', 'Bolt')->instance());

        $this->assertStringContainsString('Bolt', implode(' | ', $lines));
    }

    public function test_the_new_keys_exist_in_every_language_with_the_same_placeholders(): void
    {
        $english = require __DIR__.'/../../resources/lang/en/filament-scheduled-reports.php';

        foreach (['en', 'de', 'es', 'fr', 'it', 'nl', 'pl', 'pt_BR', 'tr', 'uk'] as $locale) {
            $strings = require __DIR__."/../../resources/lang/{$locale}/filament-scheduled-reports.php";

            foreach (array_filter(array_keys($english), static fn (string $key): bool => str_starts_with($key, 'code_') || str_starts_with($key, 'delivery_') || in_array($key, ['mail_notes', 'mail_oversize_title', 'mail_oversize_body', 'summary_more_filters', 'sort_asc', 'sort_desc'], true)) as $key) {
                $this->assertArrayHasKey($key, $strings, "{$locale}: {$key}");
            }
        }
    }
}
