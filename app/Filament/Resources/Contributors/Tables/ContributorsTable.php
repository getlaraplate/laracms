<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Contributors\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CMS\Filament\Utils\HasTable;
use Modules\CMS\Models\Contributor;

final class ContributorsTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $columns): void {
                $columns->unshift(...[
                    IconColumn::make('user.id')
                        ->label(__('cms.fields.type'))
                        ->trueIcon('heroicon-o-user')
                        ->falseIcon('heroicon-o-pencil')
                        ->falseColor('gray')
                        ->state(static fn (Contributor $record): bool => $record->user?->id !== null)
                        ->alignCenter()
                        ->tooltip(
                            static fn (Contributor $record): string => $record->user !== null
                                ? sprintf('User (#%d: %s)', $record->user->id, $record->user->name)
                                : 'Contributor',
                        )
                        ->toggleable(isToggledHiddenByDefault: true)
                        ->grow(false)
                        ->width(100),
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable()
                        ->grow(true),
                    TextColumn::make('components.email')
                        ->label(__('app.fields.email'))
                        ->searchable()
                        ->sortable()
                        ->toggleable()
                        ->grow(true),
                    ImageColumn::make('cover')
                        ->label(__('cms.fields.avatar'))
                        ->state(static fn (Contributor $record): ?string => $record->getFirstMedia('cover')?->getUrl('thumb-low'))
                        ->circular()
                        ->imageSize('2rem')
                        ->extraAttributes(['style' => 'padding-block: 0.625rem'])
                        ->toggleable(isToggledHiddenByDefault: false)
                        ->grow(false)
                        ->defaultImageUrl(static function (Contributor $record): string {
                            $name = (string) ($record->name ?? 'C');
                            $hash = abs(crc32($name));

                            $hue = $hash % 360;
                            $saturation = 60 + ($hash % 20); // 60-80%
                            $lightness = 40 + ($hash % 20); // 40-60%

                            $h = $hue / 360;
                            $s = $saturation / 100;
                            $l = $lightness / 100;

                            $c = (1 - abs(2 * $l - 1)) * $s;
                            $x = $c * (1 - abs(fmod($h * 6, 2) - 1));
                            $m = $l - $c / 2;

                            if ($h < 1 / 6) {
                                $r = $c;
                                $g = $x;
                                $b = 0;
                            } elseif ($h < 2 / 6) {
                                $r = $x;
                                $g = $c;
                                $b = 0;
                            } elseif ($h < 3 / 6) {
                                $r = 0;
                                $g = $c;
                                $b = $x;
                            } elseif ($h < 4 / 6) {
                                $r = 0;
                                $g = $x;
                                $b = $c;
                            } elseif ($h < 5 / 6) {
                                $r = $x;
                                $g = 0;
                                $b = $c;
                            } else {
                                $r = $c;
                                $g = 0;
                                $b = $x;
                            }

                            $r = round(($r + $m) * 255);
                            $g = round(($g + $m) * 255);
                            $b = round(($b + $m) * 255);

                            $hex = sprintf('%02x%02x%02x', $r, $g, $b);

                            return url(sprintf(
                                'https://ui-avatars.com/api/?name=%s&color=FFFFFF&background=%s',
                                mb_substr($name, 0, 1),
                                $hex,
                            ));
                        })
                        ->extraImgAttributes(['loading' => 'lazy']),
                ]);
            },
        )
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('media'))
            ->defaultSort('name');
    }
}
