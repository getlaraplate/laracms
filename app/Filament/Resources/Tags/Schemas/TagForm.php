<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Tags\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\CMS\Models\Tag;
use Modules\Core\Filament\Utils\HasForm;

final class TagForm
{
    use HasForm;

    public static function configure(Schema $schema): Schema
    {
        self::configureForm($schema);

        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                // Suggests the types already in use, so an editor reuses one instead of spelling a near duplicate.
                TextInput::make('type')
                    ->datalist(static fn (): array => Tag::getTypes()->filter()->values()->all()),
                // A new tag goes last whatever is typed (`sort_when_creating`), so the position is edited only
                // once the tag exists.
                TextInput::make('order_column')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->hiddenOn('create')
                    ->helperText(__('cms.help.tagPosition')),
            ]);
    }
}
