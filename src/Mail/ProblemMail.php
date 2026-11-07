<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the owner a report did not go out, or has been paused: a silent failure is the worst kind.
 */
class ProblemMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $reportName,
        public readonly string $title,
        public readonly string $detail,
    ) {
        $connection = config('filament-scheduled-reports.mail_connection');
        $queue = config('filament-scheduled-reports.mail_queue');

        if (is_string($connection)) {
            $this->onConnection($connection);
        }

        if (is_string($queue)) {
            $this->onQueue($queue);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'filament-scheduled-reports::mail.problem');
    }
}
