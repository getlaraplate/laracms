<?php

declare(strict_types=1);

namespace Modules\CMS\Observers;

use Modules\CMS\Jobs\ReindexTaggedContentsJob;
use Modules\CMS\Models\Pivot\Taggable;
use Modules\CMS\Models\Translations\TagTranslation;

/**
 * Queues the re-indexing of the contents carrying a tag when one of the tag's names is added, renamed or
 * removed: the names are part of each content's search document (`tag_names`), and neither the tag nor the
 * contents are re-sent otherwise. A change of the slug alone, or a tag no content carries, queues nothing.
 */
final class TagTranslationObserver
{
    /**
     * Handle the TagTranslation "saved" event (fires for both create and update).
     */
    public function saved(TagTranslation $translation): void
    {
        if (! $translation->wasRecentlyCreated && ! $translation->wasChanged('name')) {
            return;
        }

        $this->queueReindex($translation);
    }

    /**
     * Handle the TagTranslation "deleted" event.
     */
    public function deleted(TagTranslation $translation): void
    {
        $this->queueReindex($translation);
    }

    /**
     * Dispatched after the transaction commits, so the worker reads the names as saved.
     */
    private function queueReindex(TagTranslation $translation): void
    {
        if (! Taggable::query()->where('tag_id', $translation->tag_id)->exists()) {
            return;
        }

        dispatch(new ReindexTaggedContentsJob($translation->tag_id))->afterCommit();
    }
}
