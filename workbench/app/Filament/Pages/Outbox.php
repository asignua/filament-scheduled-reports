<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Workbench only: the messages the `log` mailer wrote, so the walk-through can show a report arriving
 * without a mail server. Reads the application log; nothing here belongs in a real panel.
 */
class Outbox extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'Outbox (mail log)';

    protected static ?string $slug = 'outbox';

    protected string $view = 'workbench::pages.outbox';

    /**
     * @return list<array{to: string, subject: string, attachment: string, unsubscribe: string, at: string}>
     */
    public function messages(): array
    {
        $path = storage_path('logs/laravel.log');
        $log = is_file($path) ? (string) file_get_contents($path) : '';
        $entries = preg_split('/^\[(\d{4}-\d{2}-\d{2} [\d:]+)\] \w+\.\w+: /m', $log, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $messages = [];

        for ($i = 1; $i + 1 < count($entries); $i += 2) {
            $body = $entries[$i + 1];

            if (preg_match('/^Subject: (.+)$/m', $body, $subject) !== 1) {
                continue;
            }

            preg_match('/^To: (.+)$/m', $body, $to);
            preg_match('/filename=(?:"([^"]+)"|([^\s;]+))/', $body, $file);
            preg_match('/^List-Unsubscribe: <([^>]+)>/m', $body, $unsubscribe);

            $messages[] = [
                'at' => $entries[$i],
                'to' => trim($to[1] ?? ''),
                'subject' => trim($subject[1]),
                'attachment' => $file[1] !== '' ? ($file[1] ?? '') : ($file[2] ?? ''),
                'unsubscribe' => trim($unsubscribe[1] ?? ''),
            ];
        }

        return array_slice(array_reverse($messages), 0, 10);
    }
}
