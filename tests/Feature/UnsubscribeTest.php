<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Feature;

use Asignua\FilamentScheduledReports\Enums\PauseReason;
use Asignua\FilamentScheduledReports\Enums\ReportStatus;
use Asignua\FilamentScheduledReports\Support\Unsubscribe;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Workbench\App\Models\User;

class UnsubscribeTest extends TestCase
{
    public function test_a_valid_link_asks_first_and_changes_nothing_on_get(): void
    {
        $report = $this->makeReport();

        $this->get(Unsubscribe::url($report, 'u-'.auth()->id()))->assertOk()->assertSee('<form', false);

        $this->assertCount(1, $report->refresh()->recipients);
    }

    public function test_posting_the_signed_link_removes_only_that_recipient(): void
    {
        $colleague = User::factory()->create();
        $report = $this->makeReport(['recipients' => [
            ['type' => 'user', 'id' => (string) auth()->id()],
            ['type' => 'user', 'id' => (string) $colleague->id],
        ]]);

        $this->post(Unsubscribe::url($report, 'u-'.$colleague->id))->assertOk();

        $report->refresh();
        $this->assertSame([['type' => 'user', 'id' => (string) auth()->id()]], $report->recipients);
        $this->assertSame(ReportStatus::Active, $report->status);
    }

    public function test_an_external_recipient_can_unsubscribe_without_an_account(): void
    {
        $report = $this->makeReport(['recipients' => [
            ['type' => 'user', 'id' => (string) auth()->id()],
            ['type' => 'email', 'email' => 'Boss@Example.com'],
        ]]);
        auth()->logout();

        $this->post(Unsubscribe::url($report, 'e-'.sha1('boss@example.com')))->assertOk();

        $this->assertCount(1, $report->refresh()->recipients);
    }

    public function test_the_last_recipient_leaving_keeps_a_manager_pause(): void
    {
        $report = $this->makeReport();
        $report->update(['status' => ReportStatus::Paused, 'paused_reason' => PauseReason::ManagerPaused, 'next_run_at' => null]);

        $this->post(Unsubscribe::url($report, 'u-'.auth()->id()))->assertOk();

        $report->refresh();
        $this->assertSame([], $report->recipients);
        $this->assertSame(PauseReason::ManagerPaused, $report->paused_reason);
        $this->assertTrue($report->isLockedByManager());
    }

    public function test_the_last_recipient_leaving_pauses_the_report(): void
    {
        $report = $this->makeReport();

        $this->post(Unsubscribe::url($report, 'u-'.auth()->id()))->assertOk();

        $report->refresh();
        $this->assertSame(ReportStatus::Paused, $report->status);
        $this->assertSame(PauseReason::NoRecipients, $report->paused_reason);
        $this->assertNull($report->next_run_at);
    }

    public function test_a_forged_signature_is_refused_and_changes_nothing(): void
    {
        $report = $this->makeReport();
        $url = Unsubscribe::url($report, 'u-'.auth()->id());

        $this->post(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();

        $this->assertCount(1, $report->refresh()->recipients);
    }

    public function test_a_link_without_a_signature_is_refused(): void
    {
        $report = $this->makeReport();

        $this->post('/scheduled-reports/unsubscribe/'.$report->ulid.'/u-'.auth()->id())->assertForbidden();

        $this->assertCount(1, $report->refresh()->recipients);
    }

    public function test_a_signature_for_one_recipient_does_not_work_for_another(): void
    {
        $colleague = User::factory()->create();
        $report = $this->makeReport(['recipients' => [
            ['type' => 'user', 'id' => (string) auth()->id()],
            ['type' => 'user', 'id' => (string) $colleague->id],
        ]]);
        $url = Unsubscribe::url($report, 'u-'.$colleague->id);

        $this->post(str_replace('u-'.$colleague->id, 'u-'.auth()->id(), $url))->assertForbidden();

        $this->assertCount(2, $report->refresh()->recipients);
    }

    public function test_a_second_click_looks_the_same_and_does_nothing(): void
    {
        $colleague = User::factory()->create();
        $report = $this->makeReport(['recipients' => [
            ['type' => 'user', 'id' => (string) auth()->id()],
            ['type' => 'user', 'id' => (string) $colleague->id],
        ]]);
        $url = Unsubscribe::url($report, 'u-'.$colleague->id);

        $this->post($url)->assertOk();
        $this->post($url)->assertOk();

        $this->assertCount(1, $report->refresh()->recipients);
    }
}
