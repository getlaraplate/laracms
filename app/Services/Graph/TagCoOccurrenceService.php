<?php

declare(strict_types=1);

namespace Modules\CMS\Services\Graph;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Tag;

/**
 * Builds the tag adjacency graph: tags are nodes weighted by how many contents use
 * them, and two tags are joined by an edge weighted by how many contents they share.
 * This is what the graph widget renders and the graph facet selector navigates —
 * "pick a tag, then the tags that co-occur with it".
 *
 * @phpstan-type GraphNode array{id: int, label: string, weight: int}
 * @phpstan-type GraphEdge array{source: int, target: int, weight: int}
 * @phpstan-type TagGraph array{nodes: list<GraphNode>, edges: list<GraphEdge>}
 */
final class TagCoOccurrenceService
{
    /**
     * @return TagGraph
     */
    public function graph(int $minCoOccurrence = 1, int $limit = 200): array
    {
        $nodes = $this->nodes($limit);

        if ($nodes === []) {
            return ['nodes' => [], 'edges' => []];
        }

        $allowed = array_column($nodes, 'id');

        return [
            'nodes' => $nodes,
            'edges' => $this->edges($allowed, $minCoOccurrence),
        ];
    }

    /**
     * @return list<GraphNode>
     */
    private function nodes(int $limit): array
    {
        $taggables = CMSTables::Taggables->value;

        /** @var Collection<int, object{tag_id: int|string, weight: int|string}> $rows */
        $rows = (new Tag)->getConnection()->query()
            ->from($taggables)
            ->where("{$taggables}.taggable_type", Content::class)
            ->groupBy("{$taggables}.tag_id")
            ->orderByDesc('weight')
            ->limit($limit)
            ->select("{$taggables}.tag_id")
            ->selectRaw("COUNT(DISTINCT {$taggables}.taggable_id) as weight")
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $labels = $this->labelsFor($rows->pluck('tag_id')->map(static fn (mixed $id): int => (int) $id)->all());

        return $rows->map(static fn (object $row): array => [
            'id' => (int) $row->tag_id,
            'label' => $labels[(int) $row->tag_id] ?? (string) $row->tag_id,
            'weight' => (int) $row->weight,
        ])->all();
    }

    /**
     * @param  list<int>  $allowedTagIds
     * @return list<GraphEdge>
     */
    private function edges(array $allowedTagIds, int $minCoOccurrence): array
    {
        $taggables = CMSTables::Taggables->value;

        /** @var Collection<int, object{source: int|string, target: int|string, weight: int|string}> $rows */
        $rows = (new Tag)->getConnection()->query()
            ->from("{$taggables} as t1")
            ->join("{$taggables} as t2", static function (JoinClause $join): void {
                $join->on('t1.taggable_id', '=', 't2.taggable_id')
                    ->whereColumn('t1.taggable_type', '=', 't2.taggable_type')
                    ->whereColumn('t1.tag_id', '<', 't2.tag_id');
            })
            ->where('t1.taggable_type', Content::class)
            ->whereIn('t1.tag_id', $allowedTagIds)
            ->whereIn('t2.tag_id', $allowedTagIds)
            ->groupBy('t1.tag_id', 't2.tag_id')
            ->havingRaw('COUNT(DISTINCT t1.taggable_id) >= ?', [$minCoOccurrence])
            ->orderByDesc('weight')
            ->select(['t1.tag_id as source', 't2.tag_id as target'])
            ->selectRaw('COUNT(DISTINCT t1.taggable_id) as weight')
            ->get();

        unset($taggables);

        return $rows->map(static fn (object $row): array => [
            'source' => (int) $row->source,
            'target' => (int) $row->target,
            'weight' => (int) $row->weight,
        ])->all();
    }

    /**
     * Resolves tag labels from the translations table, preferring the active locale
     * then the fallback locale, so a tag hidden by the model's locale scope still
     * gets a name in the graph.
     *
     * @param  list<int>  $tagIds
     * @return array<int, string>
     */
    private function labelsFor(array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        $translations = CMSTables::TagsTranslations->value;
        $locale = (string) app()->getLocale();
        $fallback = (string) config('app.fallback_locale', 'en');

        /** @var Collection<int, object{tag_id: int|string, locale: string, name: string}> $rows */
        $rows = (new Tag)->getConnection()->query()
            ->from($translations)
            ->whereIn("{$translations}.tag_id", $tagIds)
            ->select(["{$translations}.tag_id", "{$translations}.locale", "{$translations}.name"])
            ->get();

        $byTag = [];

        foreach ($rows as $row) {
            $tagId = (int) $row->tag_id;
            $byTag[$tagId][(string) $row->locale] = (string) $row->name;
        }

        $labels = [];

        foreach ($byTag as $tagId => $names) {
            $labels[$tagId] = $names[$locale] ?? $names[$fallback] ?? (string) reset($names);
        }

        return $labels;
    }
}
