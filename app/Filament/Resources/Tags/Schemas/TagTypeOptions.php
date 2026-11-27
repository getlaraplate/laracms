<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Tags\Schemas;

use Modules\CMS\Models\Tag;
use Modules\CMS\Services\TagTypeDefinition;
use Modules\CMS\Services\TagTypeRegistry;

/**
 * The tag types the backoffice offers: every registered type, then the other types already in use.
 */
final class TagTypeOptions
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $registered = array_map(
            static fn (TagTypeDefinition $definition): string => $definition->type,
            resolve(TagTypeRegistry::class)->all(),
        );

        $others = Tag::getTypes()->filter()->diff($registered)->unique()->sort()->values()->all();
        $types = [...$registered, ...$others];

        return array_combine($types, $types);
    }

    /**
     * The types that contain what is typed, then what is typed as a new type when no type offered is it.
     *
     * @return array<string, string>
     */
    public static function search(string $search): array
    {
        $typed = Tag::normalizeType($search);

        if ($typed === null) {
            return self::all();
        }

        $results = array_filter(self::all(), static fn (string $type): bool => str_contains($type, $typed));
        $results[$typed] = $typed;

        return $results;
    }
}
