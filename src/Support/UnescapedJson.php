<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A JSON column that keeps Cyrillic (and any non-ASCII text) readable. The stock `array` cast writes
 * `До...`, which makes the database, dumps and backups unreadable. Reads both forms.
 *
 * @implements CastsAttributes<array<mixed>, array<mixed>|null>
 */
final class UnescapedJson implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return (string) json_encode($value ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
