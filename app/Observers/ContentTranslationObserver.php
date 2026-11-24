<?php

declare(strict_types=1);

namespace Modules\CMS\Observers;

use Modules\CMS\Models\Content;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\Core\Events\TranslationRequiresReembedding;
use Modules\Core\Overrides\LocaleScope;

/**
 * Triggers an incremental, single-locale re-embed when a ContentTranslation's
 * embeddable text changes, and cleans up that locale's embeddings (plus a
 * parent reindex) when a translation is deleted.
 *
 * `Content::$embed = ['title', 'textual_only']`. `title` is a real column on
 * ContentTranslation, but `textual_only` is a DERIVED accessor
 * ({@see \Modules\Core\Models\Concerns\HasDynamicContents::getTextualOnlyAttribute})
 * computed from the per-locale dynamic field values stored in the `components`
 * JSON column — it has no column of its own, so a raw diff of
 * `ContentTranslation::getChanges()` would never list "textual_only" even when
 * the underlying text changed. {@see self::EMBED_FIELD_BACKING_COLUMNS} maps
 * each embed field to the translation column(s) that actually back it, so a
 * components-only edit still triggers a re-embed instead of being silently
 * skipped. Embed fields not listed there are assumed to map to a column of the
 * same name (the common case, e.g. `title`), which keeps this forward-compatible
 * with future additions to `Content::$embed`.
 */
final class ContentTranslationObserver
{
    /**
     * @var array<string, list<string>>
     */
    private const array EMBED_FIELD_BACKING_COLUMNS = [
        'textual_only' => ['components'],
    ];

    /**
     * Handle the ContentTranslation "saved" event (fires for both create and update).
     */
    public function saved(ContentTranslation $translation): void
    {
        // Whether contents are embeddable depends on configuration and the class,
        // not on the row: answer it before reading the content.
        if (! (new Content)->isEmbeddable()) {
            return;
        }

        $content = $this->resolveContent($translation);

        // isEmbeddable() covers both "does this model declare $embed" and "is vector
        // search actually enabled" (Modules\Core\Search\Traits\Searchable). Without this
        // gate, every ContentTranslation save would unconditionally enqueue a real
        // embedding call even when vector search is off (the default), which is both
        // wasted work and, under a synchronous queue connection, a real external call.
        if (! $content instanceof Content || ! $content->isEmbeddable()) {
            return;
        }

        if (! $translation->wasRecentlyCreated && ! $this->embeddableTextChanged($translation, $content)) {
            return;
        }

        event(new TranslationRequiresReembedding($content, $translation->locale));
    }

    /**
     * Handle the ContentTranslation "deleted" event.
     */
    public function deleted(ContentTranslation $translation): void
    {
        $content = $this->resolveContent($translation);

        if (! $content instanceof Content) {
            return;
        }

        $content->embeddings()->forLocale($translation->locale)->delete();

        // Refresh the ES `embeddings`/`locales` fields now that a locale's
        // embeddings (and, from the caller's perspective, its translation) are gone.
        $content->searchable();
    }

    /**
     * Resolve the owning Content, bypassing `LocaleScope`.
     *
     * `ContentTranslation::content()` is a plain `belongsTo(Content::class)`, so by
     * default it inherits Content's global `LocaleScope` — which hides a Content
     * that has no translation matching the app's current/default locale (with
     * fallback). That is exactly the state right after creating a Content's first
     * translation in a non-default locale, or the state left behind right after
     * deleting its default-locale translation: the relation would resolve to null
     * and silently skip the re-embed/cleanup this observer exists to run. The
     * observer acts on the owning row unconditionally, independent of any
     * request-scoped locale, so the lookup must ignore that scope.
     */
    private function resolveContent(ContentTranslation $translation): ?Content
    {
        /** @var Content|null $content */
        return Content::query()
            ->withoutGlobalScope(LocaleScope::class)
            ->without('presettable')
            ->find($translation->content_id);
    }

    /**
     * Whether any column backing an embeddable field changed on this translation.
     */
    private function embeddableTextChanged(ContentTranslation $translation, Content $content): bool
    {
        $backing_columns = $this->backingColumnsFor($content->getEmbedFields());

        return array_intersect(array_keys($translation->getChanges()), $backing_columns) !== [];
    }

    /**
     * @param  list<string>  $embed_fields
     * @return list<string>
     */
    private function backingColumnsFor(array $embed_fields): array
    {
        $columns = [];

        foreach ($embed_fields as $field) {
            array_push($columns, ...(self::EMBED_FIELD_BACKING_COLUMNS[$field] ?? [$field]));
        }

        return array_values(array_unique($columns));
    }
}
