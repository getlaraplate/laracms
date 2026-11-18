<?php

declare(strict_types=1);

namespace Modules\CMS\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\CMS\Models\Content;
use Modules\Core\Search\Extensions\ExtensionResolver;

/**
 * Batched upcast of a page of {@see Content} (loaded with `withExtended()`): each extended content replaced by its
 * extender, the content re-attached as `content`, one query per alias. A delegate over the Core
 * {@see ExtensionResolver}.
 *
 * @deprecated Use `Content::upcastExtensions()` or the Core {@see ExtensionResolver}. Kept while callers still use it
 *             (spec 2026-11-17-extended-entity-search-design.md, XS13; plan task 7 moves Shop).
 */
final readonly class ContentExtensionResolver
{
    public function __construct(private ExtensionResolver $resolver) {}

    /**
     * @param  Collection<int, Content>  $contents
     * @return Collection<int, Content|Model>
     */
    public function resolve(Collection $contents): Collection
    {
        return $this->resolver->upcast(new Content(), $contents);
    }
}
