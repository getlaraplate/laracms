<?php

declare(strict_types=1);

namespace Modules\CMS\Jobs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\CMS\Models\Content;
use Modules\CMS\Scopes\HidesExtendedContent;
use Modules\Core\Authorization\RetrievedSelectGuard;
use Modules\Core\Overrides\LocaleScope;
use Modules\Core\Search\Jobs\CommonSearchJob;

/**
 * Re-sends to the search engine every content carrying a tag, because the tag names are part of the content's
 * search document (`tag_names`) and a change of a tag translation never touches the content row.
 *
 * Runs on the search indexing queue. The contents are read in chunks by id, as Scout's `searchable()` on a query
 * reads them, and each chunk goes to the content's own indexing path (`queueMakeSearchable()`), so a tag carried
 * by many contents is never loaded at once.
 */
final class ReindexTaggedContentsJob extends CommonSearchJob
{
    public function __construct(public readonly int $tagId)
    {
        parent::__construct();
    }

    /**
     * The contents are read past the locale and the extended-content scopes: the document of a content with no
     * translation in the worker's locale, or of an extended content, carries the tag names as well. Trashed
     * contents are not indexed and stay out. The read is a system read: the document is built from the content
     * and its relations, none of which reaches a caller, and search results are filtered again when served.
     */
    public function handle(): void
    {
        RetrievedSelectGuard::forSystemRead(function (): void {
            Content::query()
                ->withoutGlobalScope(LocaleScope::class)
                ->withoutGlobalScope(HidesExtendedContent::class)
                ->whereHas('tagsAcrossLocales', function (Builder $query): void {
                    $query->whereKey($this->tagId);
                })
                ->chunkById($this->chunkSize(), static function (Collection $contents): void {
                    $first = $contents->first();

                    if ($first instanceof Content) {
                        $first->queueMakeSearchable($contents);
                    }
                });
        });
    }

    /**
     * The chunk Scout's own `searchable()` reads a query with.
     */
    private function chunkSize(): int
    {
        $chunk = config('scout.chunk.searchable', 500);

        return is_numeric($chunk) && (int) $chunk > 0 ? (int) $chunk : 500;
    }
}
