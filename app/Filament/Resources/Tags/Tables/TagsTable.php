<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Tags\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CMS\Models\Tag;
use Modules\CMS\Models\Translations\TagTranslation;
use Modules\Core\Filament\Utils\HasTable;
use Modules\Core\Helpers\LocaleContext;

final class TagsTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $columns): void {
                $columns->unshift(...[
                    TextColumn::make('name')
                        ->searchable(),
                    TextColumn::make('slug')
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('type')
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                ]);
            },
        )
            ->defaultSort(static fn (Builder $query): Builder => $query->orderBy(self::shownSlug()));
    }

    /**
     * The slug the row shows: the content locale's, or the default locale's where that one is missing. Filament
     * appends the key, so tags with the same slug follow their id.
     *
     * @return Builder<TagTranslation>
     */
    private static function shownSlug(): Builder
    {
        $locale = LocaleContext::get();

        return TagTranslation::query()
            ->select('slug')
            ->whereColumn(new TagTranslation()->qualifyColumn('tag_id'), new Tag()->qualifyColumn('id'))
            ->whereIn('locale', array_unique([$locale, LocaleContext::getDefault()]))
            ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale])
            ->limit(1);
    }
}
