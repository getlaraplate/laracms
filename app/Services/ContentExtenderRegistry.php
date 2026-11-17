<?php

declare(strict_types=1);

namespace Modules\CMS\Services;

use InvalidArgumentException;
use Modules\CMS\Models\Content;
use Modules\Core\Search\Extensions\ExtensionRegistry;

/**
 * The extenders of {@see Content}, by alias: a delegate over the Core {@see ExtensionRegistry} under the host
 * `Content`. The container binds one shared instance over the Core singleton, so an extender registered here is the
 * one `Content` reads; `new ContentExtenderRegistry()` is an isolated registry with nothing in it.
 *
 * @deprecated Register in the Core {@see ExtensionRegistry} for `Content::class`. Kept while extenders still register
 *             through it (spec 2026-11-17-extended-entity-search-design.md, XS13; plan task 7 moves Shop).
 */
final readonly class ContentExtenderRegistry
{
    private ExtensionRegistry $registry;

    public function __construct(?ExtensionRegistry $registry = null)
    {
        $this->registry = $registry ?? new ExtensionRegistry();
    }

    /**
     * @param  class-string  $extender
     */
    public function register(string $alias, string $extender): void
    {
        $this->registry->register(Content::class, $alias, $extender);
    }

    public function has(string $alias): bool
    {
        return $this->registry->has(Content::class, $alias);
    }

    /**
     * @return class-string
     *
     * @throws InvalidArgumentException when the alias is not registered
     */
    public function resolve(string $alias): string
    {
        if (! $this->has($alias)) {
            throw new InvalidArgumentException("No content extender registered for alias [{$alias}].");
        }

        return $this->registry->resolve(Content::class, $alias);
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return $this->registry->aliases(Content::class);
    }
}
