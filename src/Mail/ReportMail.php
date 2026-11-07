<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * One mail per recipient, so that the unsubscribe link is theirs and nobody learns who else gets it.
 * The file is attached from the disk the export was stored on (it lives there for the export's retention).
 */
class ReportMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param list<string> $summary
     * @param list<string> $notes   codes the recipient should know about (a saved column that is gone), shown as words
     */
    public function __construct(
        public readonly string $reportName,
        public readonly string $recipientName,
        public readonly string $fileName,
        public readonly int $rows,
        public readonly array $summary,
        public readonly string $unsubscribeUrl,
        public readonly string $disk,
        public readonly string $path,
        public readonly ?string $link = null,
        public readonly array $notes = [],
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
        return new Envelope(subject: __('filament-scheduled-reports::filament-scheduled-reports.mail_subject', ['name' => $this->reportName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'filament-scheduled-reports::mail.report');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->link !== null) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($this->disk, $this->path)
                ->as($this->fileName)
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }

    public function headers(): Headers
    {
        // The one-click form (RFC 8058): the link is a signed URL, so a mail client can POST to it.
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
