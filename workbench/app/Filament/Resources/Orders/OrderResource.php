<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Orders;

use Asignua\FilamentXlsxExport\Actions\XlsxExportAction;
use Asignua\FilamentXlsxExport\ColumnFormat;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Enums\OrderStatus;
use Workbench\App\Filament\Resources\Orders\Pages\ListOrders;
use Workbench\App\Models\Order;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->alignEnd(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('Customer'),
                TextColumn::make('amount')->money('USD')->sortable()->alignEnd(),
                TextColumn::make('quantity')->numeric()->sortable()->alignEnd(),
                TextColumn::make('status')->badge(),
                IconColumn::make('paid')->boolean(),
                TextColumn::make('placed_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::class),
                // A filter whose state is a related record's key: the query goes through whereHas.
                SelectFilter::make('customer')->relationship('customer', 'name'),
                // A custom-form filter with no indicator of its own: the table applies it and says nothing about it.
                Filter::make('big')
                    ->schema([TextInput::make('min')])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['min'] ?? null) ? $query->where('amount', '>=', $data['min']) : $query),
            ]);
    }

    /** Tests only: changes the export action (disabled, authorize, ...). */
    public static ?Closure $exportTweak = null;

    public static function exportAction(): XlsxExportAction
    {
        $action = XlsxExportAction::make()
            ->title('Orders')
            ->columnFormats(['amount' => ColumnFormat::make()->money('$')->sum()->width(14)]);

        return self::$exportTweak === null ? $action : (self::$exportTweak)($action);
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/')];
    }
}
