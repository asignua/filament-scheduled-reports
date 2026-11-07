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

        return $built instanceof Schema ? array_values($built->getComponents()) : [];
    }

    /**
     * The columns the export can write now, by name, or null when the action has no column picker.
     *
     * @return array<string, string>|null
     */
    public static function columnOptions(object $action, HasTable $livewire, Schema $schema): ?array
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
