<?php

declare(strict_types=1);

namespace Modules\CMS\Services;

use Illuminate\Support\Str;
use Modules\CMS\Models\Tag;

/**
 * In-memory registry of the tag types that have a label and an icon.
 *
 * Populated at boot by each module's service provider. A type that is not registered stays valid (the tag
 * type is free text): it simply has no registered icon and falls back to a letter.
 */
final class TagTypeRegistry
{
    /**
     * @var array<string, TagTypeDefinition>
     */
    private array $definitions = [];

    public function register(TagTypeDefinition $definition): void
    {
        $this->definitions[$definition->type] = $definition;
    }

    /**
     * @return list<TagTypeDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function find(?string $type): ?TagTypeDefinition
    {
        $type = Tag::normalizeType($type);

        return $type === null ? null : ($this->definitions[$type] ?? null);
    }

    /**
     * The Heroicon of the type, the generic tag icon for no type, null for a type that is not registered.
     */
    public function heroiconFor(?string $type): ?string
    {
        if (Tag::normalizeType($type) === null) {
            return 'heroicon-o-tag';
        }

        return $this->find($type)?->heroicon;
    }

    /**
     * The upper-case first letter of a type that is not registered, null otherwise.
     */
    public function letterFor(?string $type): ?string
    {
        $normalized = Tag::normalizeType($type);

        if ($normalized === null || $this->find($normalized) instanceof TagTypeDefinition) {
            return null;
        }

        return Str::upper(Str::substr($normalized, 0, 1));
    }
}
