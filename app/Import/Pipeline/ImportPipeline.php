<?php

declare(strict_types=1);

namespace Modules\CMS\Import\Pipeline;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\CMS\Import\Dto\ImportGraphDto;
use Modules\CMS\Import\Support\CategoryHierarchySorter;
use Modules\CMS\Import\Support\ContributorDefaults;
use Modules\CMS\Import\Support\ImportConnectionContext;
use Modules\CMS\Import\Support\ImportIdMap;
use Modules\CMS\Import\Support\ImportPresetProvisioner;
use Modules\CMS\Import\Upserters\CategoryUpserter;
use Modules\CMS\Import\Upserters\ContentUpserter;
use Modules\CMS\Import\Upserters\ContributorUpserter;
use Modules\CMS\Import\Upserters\LocationUpserter;
use Modules\CMS\Import\Upserters\TagUpserter;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Location;
use Modules\CMS\Models\Tag;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Import\Support\ImportFingerprint;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ImportPipeline
{
    public function __construct(
        private readonly ImportPresetProvisioner $preset_provisioner,
        private readonly CategoryHierarchySorter $category_sorter,
        private readonly CategoryUpserter $category_upserter,
        private readonly ContributorUpserter $contributor_upserter,
        private readonly TagUpserter $tag_upserter,
        private readonly LocationUpserter $location_upserter,
        private readonly ContentUpserter $content_upserter,
        private readonly ContributorDefaults $contributor_defaults,
        private readonly ImportIdMap $id_map,
        private readonly CacheLabelInvalidator $cache_label_invalidator,
    ) {}

    public function import(
        ImportGraphDto $graph,
        ?Model $root_model = null,
        ?OutputInterface $output = null,
    ): int {
        $root_model ??= new Content;
        $context = new ImportConnectionContext($root_model);

        $context->preflight($this->participantModelClasses($graph));

        try {
            return $context->connection()->transaction(
                fn (): int => $this->importGraph($graph, $context, $output),
            );
        } catch (Throwable $exception) {
            // The rollback may have removed rows the run state points at.
            $this->resetState();

            throw $exception;
        } finally {
            $this->flushWrittenTables($root_model);
        }
    }

    public function resetState(): void
    {
        $this->id_map->reset();
        $this->contributor_defaults->reset();
        $this->preset_provisioner->reset();
    }

    /**
     * The import writes through the query builder and raw pivot rows, which fire no model event, so the table
     * label of every table a run can write is flushed once, after the run. A failed run is flushed too: the
     * rollback leaves nothing behind, and a flush that was not needed only costs a cache miss.
     */
    private function flushWrittenTables(Model $root_model): void
    {
        $content = new Content;
        $tables = [
            $content->getTable(),
            (new Category)->getTable(),
            (new Contributor)->getTable(),
            (new Tag)->getTable(),
            (new Location)->getTable(),
            $content->categories()->getTable(),
            $content->contributors()->getTable(),
            $content->tags()->getTable(),
            $content->locations()->getTable(),
            $content->related()->getTable(),
        ];

        foreach (array_unique($tables) as $table) {
            $this->cache_label_invalidator->flushTable($table, $root_model);
        }
    }

    private function importGraph(
        ImportGraphDto $graph,
        ImportConnectionContext $context,
        ?OutputInterface $output = null,
    ): int {
        $this->preset_provisioner->provisionFromGraph($graph, $context);

        foreach ($this->category_sorter->sort($graph->categories) as $category) {
            $this->upsertUnlessWritten(
                'categories',
                $category->externalId,
                $category->sourceType,
                $category,
                $context,
                fn (): int => $this->category_upserter->upsert($category, $context),
            );
        }

        foreach ($graph->contributors as $contributor) {
            $this->upsertUnlessWritten(
                'contributors',
                $contributor->externalId,
                $contributor->sourceType,
                $contributor,
                $context,
                fn (): int => $this->contributor_upserter->upsert($contributor, $context),
            );
        }

        foreach ($graph->tags as $tag) {
            $this->upsertUnlessWritten(
                'tags',
                $tag->externalId,
                $tag->sourceType,
                $tag,
                $context,
                fn (): int => $this->tag_upserter->upsert($tag, $context),
            );
        }

        $location_ids = [];

        foreach ($graph->locations as $location) {
            $location_ids[] = $this->location_upserter->upsert($location, $context);
        }

        $category_ids = $this->id_map->resolveMany(
            'categories',
            $graph->content->categoryExternalIds,
            $context->connectionName(),
            $graph->content->sourceType,
        );
        $contributor_ids = $this->id_map->resolveMany(
            'contributors',
            $graph->content->contributorExternalIds,
            $context->connectionName(),
            $graph->content->sourceType,
        );

        if ($contributor_ids === []) {
            $contributor_ids = [$this->contributor_defaults->resolveContributorId($context)];
        }

        $tag_ids = $this->id_map->resolveMany(
            'tags',
            $graph->content->tagExternalIds,
            $context->connectionName(),
            $graph->content->sourceType,
        );

        foreach ($graph->relatedGraphs as $related_graph) {
            $this->importGraph($related_graph, $context, $output);
        }

        return $this->content_upserter->upsert(
            $graph->content,
            $category_ids,
            $contributor_ids,
            $tag_ids,
            $location_ids,
            $context,
            $output,
        );
    }

    /**
     * The source repeats the same categories, contributors and tags on most articles,
     * and writing an unchanged one again costs a dozen queries. An entity already
     * written in this run with the same payload is left alone: its local id is in the
     * run's id map, and a rolled-back graph clears that map with {@see resetState()}.
     *
     * @param  Closure(): int  $upsert
     */
    private function upsertUnlessWritten(
        string $entity,
        int $external_id,
        string $source_type,
        object $dto,
        ImportConnectionContext $context,
        Closure $upsert,
    ): void {
        $fingerprint = ImportFingerprint::of($dto);

        if ($fingerprint === null) {
            $upsert();

            return;
        }

        if ($this->id_map->isUpserted($entity, $external_id, $context->connectionName(), $source_type, $fingerprint)) {
            return;
        }

        $upsert();
        $this->id_map->markUpserted($entity, $external_id, $context->connectionName(), $source_type, $fingerprint);
    }

    /**
     * @return list<class-string<Model>>
     */
    private function participantModelClasses(ImportGraphDto $graph): array
    {
        $classes = [
            ...$this->preset_provisioner->participantModelClasses(),
            ...$this->content_upserter->participantModelClasses(),
            ...$this->contributor_upserter->participantModelClasses(),
            ...($graph->categories === [] ? [] : $this->category_upserter->participantModelClasses()),
            ...($graph->contributors === [] ? [] : $this->contributor_upserter->participantModelClasses()),
            ...($graph->tags === [] ? [] : $this->tag_upserter->participantModelClasses()),
            ...($graph->locations === [] ? [] : $this->location_upserter->participantModelClasses()),
        ];

        foreach ($graph->relatedGraphs as $related_graph) {
            $classes = [...$classes, ...$this->participantModelClasses($related_graph)];
        }

        return array_values(array_unique($classes));
    }
}
