<?php

declare(strict_types=1);

namespace Modules\CMS\Import\Support;

use Modules\CMS\Models\Translations\TagTranslation;

/**
 * Cross-source tag deduplication for import upserts.
 *
 * A tag is one per locale and slug, whatever source brings it: a source that meets a slug another source already
 * imported reuses that tag instead of creating a second one. Trashed tags are found too, so the import revives them.
 */
final class TagMatcher
{
    public function findExisting(string $slug, string $locale, ?ImportConnectionContext $context = null): ?int
    {
        $translation = $context?->model(TagTranslation::class) ?? new TagTranslation;

        $tag_id = $translation->getConnection()->table($translation->getTable())
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->value('tag_id');

        return is_numeric($tag_id) ? (int) $tag_id : null;
    }
}
