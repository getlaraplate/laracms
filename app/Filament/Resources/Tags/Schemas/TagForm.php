<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Tags\Schemas;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\CMS\Models\Tag;
use Modules\Core\Filament\Utils\HasForm;
use Modules\Core\Helpers\LocaleContext;

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
                    ->required()
                    // One live tag per locale and slug: a taken slug is a message here, not a database error.
                    ->rules([
                        static fn (?Tag $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (is_string($value) && Tag::slugTakenInLocale($value, LocaleContext::get(), $record?->id)) {
                                $fail(__('validation.unique', ['attribute' => 'slug']));
                            }
                        },
                    ]),
                // Offers the types already in use, so an editor reuses one instead of spelling a near duplicate; what is
                // typed is offered too, as the slug it is stored as, so a new type is still one pick away.
                Select::make('type')
                    ->searchable()
                    ->options(static fn (): array => self::typeOptions())
                    ->getSearchResultsUsing(static fn (string $search): array => self::typeSearchResults($search))
                    ->getOptionLabelUsing(static fn (?string $value): ?string => Tag::normalizeType($value)),
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

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $types = Tag::getTypes()->filter()->sort()->values()->all();

        return array_combine($types, $types);
    }

    /**
     * The types in use that contain what is typed, then what is typed as a new type when no type in use is it.
     *
     * @return array<string, string>
     */
    private static function typeSearchResults(string $search): array
    {
        $typed = Tag::normalizeType($search);

        if ($typed === null) {
            return self::typeOptions();
        }

        $results = array_filter(self::typeOptions(), static fn (string $type): bool => str_contains($type, $typed));
        $results[$typed] = $typed;

        return $results;
    }
}
