<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Asignua\FilamentXlsxExport\Support\StreamedExports;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;

/**
 * The export action's own modal (column picker, `exportOptions()`), reused as the report's options.
 * Found by name on the page, so a report schedules exactly what the Export button would download.
 */
final class ExportForm
{
    public static function action(HasTable $livewire, string $name): ?object
    {
        // Header action of the page or of the table: a report is never made from a row or a selection.
        return StreamedExports::findAction($livewire, $name, false, null);
    }

    /**
     * @return list<Component>
     */
    public static function components(object $action, Schema $schema): array
    {
        if (!method_exists($action, 'getSchema')) {
            return [];
        }

        $built = $action->getSchema($schema);

        return $built instanceof Schema ? array_values(array_filter($built->getComponents(), static fn (mixed $component): bool => $component instanceof Component)) : [];
    }

    /**
     * The export modal's own defaults (the columns the table shows now), which a form filled by this
     * plugin would otherwise replace with nothing.
     *
     * @return array<string, mixed>
     */
    public static function defaults(object $action, Schema $schema): array
    {
        $defaults = [];

        foreach (self::components($action, $schema) as $component) {
            if ($component instanceof \Filament\Forms\Components\Field) {
                $name = $component->getName();
                $state = $component->getDefaultState();

                if ($state !== null) {
                    $defaults[$name] = $state;
                }
            }
        }

        return $defaults;
    }

    /**
     * The columns the export can write now, by name, or null when the action has no column picker.
     *
     * @return array<string, string>|null
     */
    public static function columnOptions(object $action, Schema $schema): ?array
    {
        foreach (self::components($action, $schema) as $component) {
            if ($component instanceof CheckboxList && $component->getName() === 'columns') {
                /** @var array<string, string> */
                return $component->getOptions();
            }
        }

        return null;
    }
}
