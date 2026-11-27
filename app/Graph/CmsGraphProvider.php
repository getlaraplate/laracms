<?php

declare(strict_types=1);

namespace Modules\CMS\Graph;

use Modules\Core\Graph\Contracts\GraphProviderInterface;
use Override;

final class CmsGraphProvider implements GraphProviderInterface
{
    #[Override]
    public function defaultRelations(string $module, string $entity): array
    {
        return match ($entity) {
            'contents' => ['tags', 'categories', 'contributors', 'locations'],
            'tags', 'categories', 'contributors', 'locations' => [],
            default => [],
        };
    }

    #[Override]
    public function summaryFields(string $module, string $entity): array
    {
        return match ($entity) {
            'contents' => ['title', 'slug', 'status', 'type', 'created_at', 'updated_at'],
            'tags', 'categories', 'contributors', 'locations' => ['name', 'slug', 'type'],
            default => [],
        };
    }

    #[Override]
    public function edgeType(string $module, string $entity, string $relation): ?string
    {
        return match ($relation) {
            'tags' => 'tagged_as',
            'categories' => 'categorized_as',
            'contributors' => 'contributed_by',
            'locations' => 'located_at',
            'children' => 'parent_of',
            'parent' => 'child_of',
            default => $relation,
        };
    }

    #[Override]
    public function excludedRelations(string $module, string $entity): array
    {
        return ['translations', 'history', 'modifications', 'locks', 'media'];
    }
}
