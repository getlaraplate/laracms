<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Categories\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\CMS\Filament\Utils\HasTable;
use Modules\CMS\Models\Category;

final class CategoriesTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('entity.name')
                        ->searchable()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('preset.name')
                        ->searchable()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable()
                        ->state(static fn (Category $record): string => Str::repeat('&nbsp;', $record->ancestors->count() * 4) . $record->name)
                        ->html(),
                    TextColumn::make('parent_id')
                        ->numeric()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('slug')
                        ->searchable()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('logo')
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('logo_full')
                        ->toggleable(isToggledHiddenByDefault: true),
                ]);
            },
        );
    }
}
