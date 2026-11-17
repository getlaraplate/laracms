<?php

declare(strict_types=1);

namespace Modules\CMS\Observers;

use Modules\CMS\Models\Content;
use Modules\CMS\Models\ContentReference;
use Modules\Core\Authorization\RetrievedSelectGuard;
use Modules\Core\Overrides\LocaleScope;
use Modules\Core\Search\Extensions\HidesExtendedRows;

/**
 * Re-sends a content to the search engine when one of its references is added, changed or removed, because
 * the references (labels and URL hosts) are part of the content's search document and a change of theirs
 * never touches the content row itself.
 */
final class ContentReferenceObserver
{
    /**
     * Handle the ContentReference "saved" event (fires for both create and update).
     */
    public function saved(ContentReference $reference): void
    {
        $this->reindex($reference);
    }

    /**
     * Handle the ContentReference "deleted" event.
     */
    public function deleted(ContentReference $reference): void
    {
        $this->reindex($reference);
    }

    /**
     * The owning content is resolved past the locale and the extended-content scopes: the document of a
     * content with no translation in the current locale, or of an extended content, must follow its
     * references as well. A trashed content is not indexed, so it is left alone.
     *
     * The re-index is a system read: the caller who changed the reference may hold the reference's own
     * permissions without the content's, and the document is built from the content and its relations, none
     * of which reaches the caller. Search results are filtered again when served.
     */
    private function reindex(ContentReference $reference): void
    {
        RetrievedSelectGuard::forSystemRead(static function () use ($reference): void {
            $content = Content::query()
                ->withoutGlobalScope(LocaleScope::class)
                ->withoutGlobalScope(HidesExtendedRows::class)
                ->find($reference->content_id);

            if ($content instanceof Content) {
                $content->searchable();
            }
        });
    }
}
