<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

/**
 * Text that somebody else typed (a report's name, a filter value, a person's name), made safe to put into a
 * markdown mail. Laravel renders the mail's body as GitHub-flavoured markdown, so `[open](https://evil.example)`
 * or a bare address would become a live link in a mail that comes from the application's own address.
 *
 * Every ASCII punctuation mark is backslash-escaped (CommonMark allows exactly that), except the five that Blade's
 * own `{{ }}` encoding already turns into entities and that cannot start a link.
 */
final class Markdown
{
    public static function escape(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        $map = [];

        foreach (str_split('\\!#$%()*+,-./:;=?@[]^_`{|}~') as $character) {
            $map[$character] = '\\'.$character;
        }

        return strtr($text, $map);
    }
}
