<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Indicator;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What of a table's state is saved with a report, and how it is put back on a freshly built page.
 *
 * Plain data only. A Livewire snapshot is deliberately not stored: it is signed with APP_KEY, lives for
 * minutes and is tied to the component's version, so a weekly report built on one would break on the
 * next key rotation or upgrade. The page class, the filters, the search and the sort are enough for
 * the table to build its own query again.
 */
final class ReportState
{
    public const int VERSION = 1;

    /**
     * @return array{version: int, filters: array<string, mixed>, search: string|null, column_searches: array<string, mixed>, sort: string|null, tab: string|null}
     */
    public static function capture(HasTable $livewire): array
    {
        $active = self::read($livewire, 'activeTab');
        $tab = (is_string($active) || is_int($active)) && (string) $active !== '' ? (string) $active : null;
        $search = self::read($livewire, 'tableSearch');
        $sort = self::read($livewire, 'tableSort');

        return [
            'version' => self::VERSION,
            'filters' => self::plain(self::read($livewire, 'tableFilters') ?? []),
            'search' => is_string($search) && $search !== '' ? $search : null,
            'column_searches' => self::plain(self::read($livewire, 'tableColumnSearches') ?? []),
            'sort' => is_string($sort) && $sort !== '' ? $sort : null,
            'tab' => $tab,
        ];
    }

    /**
     * Puts the state on a fresh component. Returns the reason it cannot, or null: a filter that carried
     * a value but no longer exists on the table, or a tab that is gone. Applying such a state anyway
     * would send a report with more rows than the owner meant.
     *
     * @param array<string, mixed> $state
     */
    public static function apply(HasTable $component, array $state): ?string
    {
        $table = $component->getTable();
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];

        foreach ($filters as $name => $value) {
            if (!self::blank($value) && $table->getFilter((string) $name) === null) {
                return 'filter:'.$name;
            }
        }

        $tab = $state['tab'] ?? null;

        if (is_string($tab) && $tab !== '') {
            // A saved tab that the page no longer offers (or no longer has any tabs at all) means the rows
            // it selected are gone with it; applying no tab would send everything.
            $tabs = method_exists($component, 'getTabs') ? $component->getTabs() : [];

            if (!array_key_exists($tab, $tabs)) {
                return 'tab:'.$tab;
            }
        }

        // Plain public properties of Filament's table trait, the same ones a browser would have set.
        $values = [
            'tableFilters' => $filters,
            'tableSearch' => is_string($state['search'] ?? null) ? $state['search'] : '',
            'tableColumnSearches' => is_array($state['column_searches'] ?? null) ? $state['column_searches'] : [],
            'tableSort' => is_string($state['sort'] ?? null) ? $state['sort'] : null,
        ];

        if (is_string($tab) && $tab !== '' && property_exists($component, 'activeTab')) {
            $values['activeTab'] = $tab;
        }

        foreach ($values as $property => $value) {
            data_set($component, $property, $value);
        }

        return null;
    }

    /**
     * The filters in words, as the table shows them above itself ("Status: On its way"). Saved with the
     * report so the owner can see what will be sent without opening the page.
     *
     * @return list<string>
     */
    public static function summary(HasTable $livewire): array
    {
        $lines = array_map(
            static function (Indicator $indicator): string {
                $label = $indicator->getLabel();

                return trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label));
            },
            $livewire->getTable()->getFilterIndicators(),
        );

        // A filter with a value but no indicator (a custom-form filter without `indicateUsing()`) is still applied by the
        // table and by the report: it is counted, so the list is never shorter than the truth.
        $hidden = self::unindicatedFilters($livewire);

        if ($hidden > 0) {
            $lines[] = __('filament-scheduled-reports::filament-scheduled-reports.summary_more_filters', ['count' => $hidden]);
        }

        $sort = self::read($livewire, 'tableSort');

        if (is_string($sort) && $sort !== '') {
            [$column, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');
            $label = $livewire->getTable()->getColumn($column)?->getLabel();
            $label = $label instanceof Htmlable ? strip_tags($label->toHtml()) : (is_string($label) && $label !== '' ? $label : $column);
            $lines[] = __('filament-scheduled-reports::filament-scheduled-reports.summary_sort', [
                'column' => $label,
                'direction' => __('filament-scheduled-reports::filament-scheduled-reports.'.(strtolower($direction) === 'desc' ? 'sort_desc' : 'sort_asc')),
            ]);
        }

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    private static function unindicatedFilters(HasTable $livewire): int
    {
        $state = self::read($livewire, 'tableFilters');
        $state = is_array($state) ? $state : [];
        $count = 0;

        foreach ($livewire->getTable()->getFilters() as $name => $filter) {
            if (!self::blank($state[$name] ?? null) && $filter->getIndicators() === []) {
                $count++;
            }
        }

        return $count;
    }

    private static function read(object $livewire, string $property): mixed
    {
        return property_exists($livewire, $property) ? $livewire->{$property} : null;
    }

    public static function blank(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::blank($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || $value === '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function plain(mixed $value): array
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $decoded = $json === false ? [] : json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
