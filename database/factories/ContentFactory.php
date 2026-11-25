<?php

declare(strict_types=1);

namespace Modules\CMS\Database\Factories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Tag;
use Modules\Core\Overrides\Factory;
use Override;

/**
 * @extends \Modules\Core\Overrides\Factory<\Modules\CMS\Models\Content>
 */
final class ContentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    #[Override]
    protected $model = Content::class;

    protected EntityType $entityType = EntityType::Contents;

    /**
     * Create pivot relations for a content model.
     *
     * Candidate ids are picked in PHP from pre-loaded pools instead of an
     * `ORDER BY RAND()` query per content: the latter full-scans and filesorts
     * the whole (growing) table on every call, which is the dominant cost when
     * seeding hundreds of thousands of contents. Pass $pools (built once via
     * {@see self::buildRelationIdPools()}) when relating many contents so the
     * pools are not rebuilt per content.
     *
     * @param  Content|Collection<Content>  $content
     * @param  array{contributors: list<int>, categories: list<int>, tags: list<int>, contents: list<int>}|null  $pools
     */
    public function createRelations(Model|Collection $content, ?callable $callback = null, ?array $pools = null): void
    {
        $pools ??= $this->buildRelationIdPools();

        $contents = $content instanceof Collection ? $content : collect([$content]);
        $existing = $this->existingRelationSets($contents);

        $this->createDynamicContentRelations($content, function (Content $content) use ($callback, $pools, $existing): void {
            if (! $content->getKey() || ! $content->exists) {
                return;
            }

            if (! isset($existing['contributors'][$content->id])) {
                $contributor_ids = $this->pickRandomIds($pools['contributors'], fake()->numberBetween(1, 3));

                if ($contributor_ids !== []) {
                    $content->contributors()->attach($contributor_ids);
                }
            }

            if (! isset($existing['categories'][$content->id])) {
                $category_ids = $this->pickRandomIds($pools['categories'], fake()->numberBetween(1, 2));

                if ($category_ids !== []) {
                    $content->categories()->attach($category_ids);
                }
            }

            if (fake()->boolean(70) && ! isset($existing['tags'][$content->id])) {
                $tag_ids = $this->pickRandomIds($pools['tags'], fake()->numberBetween(1, 5));

                if ($tag_ids !== []) {
                    $content->tags()->attach($tag_ids);
                }
            }

            if (fake()->boolean(35) && ! isset($existing['related'][$content->id])) {
                $related_ids = $this->pickRandomIds($pools['contents'], fake()->numberBetween(1, 3), $content->id);

                if ($related_ids !== []) {
                    $content->related()->attach($related_ids);
                }
            }

            if ($callback) {
                $callback($content);
            }
        });
    }

    /**
     * Content ids that still need the required pivots (contributors and/or
     * categories). Tags and related are probabilistic optional attaches, so they
     * are not used as a gate — otherwise a re-run would keep revisiting the ~30%
     * of contents that never rolled tags.
     *
     * @param  list<int>  $content_ids
     * @return list<int>
     */
    public function idsMissingRequiredRelations(array $content_ids): array
    {
        if ($content_ids === []) {
            return [];
        }

        $with_relation = static fn (string $relation): array => array_fill_keys(
            Content::withoutGlobalScopes()->whereKey($content_ids)->has($relation)->pluck('id')->all(),
            true,
        );

        $has_contributors = $with_relation('contributors');
        $has_categories = $with_relation('categories');

        return array_values(array_filter(
            $content_ids,
            static fn (int $id): bool => ! isset($has_contributors[$id]) || ! isset($has_categories[$id]),
        ));
    }

    /**
     * Build the candidate id pools once so {@see self::createRelations()} can
     * pick related records in PHP rather than issuing an `ORDER BY RAND()` query
     * per content.
     *
     * @return array{contributors: list<int>, categories: list<int>, tags: list<int>, contents: list<int>}
     */
    public function buildRelationIdPools(): array
    {
        return [
            'contributors' => Contributor::query()->pluck('id')->all(),
            'categories' => Category::query()->pluck('id')->all(),
            'tags' => Tag::query()->pluck('id')->all(),
            // Drop the ordering scope: a pluck does not need an ORDER BY, and it
            // does not change which ids are eligible (validity/soft-delete stay).
            'contents' => Content::query()->withoutGlobalScope('global_ordered')->pluck('id')->all(),
        ];
    }

    /**
     * Define the model's default state.
     */
    #[Override]
    protected function definitionsArray(): array
    {
        $valid_from = fake()->boolean() ? now()->addDays(fake()->numberBetween(-10, 10)) : null;
        $valid_to = $valid_from && fake()->boolean() ? $valid_from->addDays(fake()->numberBetween(-10, 10)) : null;

        return [
            // title, slug, components are now in translations table
            'valid_from' => $valid_from,
            'valid_to' => $valid_to,
        ];
    }

    #[Override]
    protected function beforeFactoryMaking(Model $model): void
    {
        if (! $model instanceof Content) {
            return;
        }

        // Pre-set required translatable fields before validation and before components-driven logic.
        $title = fake()->sentence(fake()->numberBetween(3, 8));
        $model->title = $title;
        $model->slug = Str::slug($title);

        // Note: dynamic contents are filled by the base factory after this hook.
    }

    #[Override]
    protected function afterFactoryMaking(Model $model): void
    {
        if (! $model instanceof Content) {
            return;
        }

        // components are available after base fillDynamicContents(); now we can adjust derived fields.
        if (array_key_exists('period_to', $model->components) && fake()->boolean()) {
            $model->setAttribute('period_to', max(fake()->dateTime($model->valid_to ?? 'now'), $model->valid_from)->format('Y-m-d H:i:s'));
        }

        if (array_key_exists('period_from', $model->components)) {
            $model->setAttribute('period_from', max(fake()->dateTime($model->components['period_to'] ?? $model->valid_to ?? 'now'), $model->valid_from)->format('Y-m-d H:i:s'));
        }
    }

    #[Override]
    protected function translatedFieldsArray(Model $model): array
    {
        if (! $model instanceof Content) {
            return [];
        }

        return [
            'title' => $model->title,
            'slug' => $model->slug,
            'components' => $model->components ?? [],
        ];
    }

    /**
     * Resolve, once for the whole batch, which contents already have each
     * relation — the batched replacement for a per-content `doesntHave()` call.
     * Keeps the pivot phase idempotent (a re-run skips contents already related)
     * while collapsing 4 existence queries per content into 4 per batch.
     *
     * Global scopes are dropped on the outer query so the check covers exactly
     * the given contents regardless of their own validity/ordering; each
     * relation's own scopes still apply inside `has()`.
     *
     * @param  \Illuminate\Support\Collection<int, Content>  $contents
     * @return array{contributors: array<int, true>, categories: array<int, true>, tags: array<int, true>, related: array<int, true>}
     */
    private function existingRelationSets(\Illuminate\Support\Collection $contents): array
    {
        $ids = $contents->map(static fn (Content $content): int => $content->getKey())->all();

        if ($ids === []) {
            return ['contributors' => [], 'categories' => [], 'tags' => [], 'related' => []];
        }

        $with_relation = static fn (string $relation): array => array_fill_keys(
            Content::withoutGlobalScopes()->whereKey($ids)->has($relation)->pluck('id')->all(),
            true,
        );

        return [
            'contributors' => $with_relation('contributors'),
            'categories' => $with_relation('categories'),
            'tags' => $with_relation('tags'),
            'related' => $with_relation('related'),
        ];
    }

    /**
     * Pick up to $count distinct ids at random from $pool, optionally excluding
     * one id (used to keep a content out of its own related set).
     *
     * @param  list<int>  $pool
     * @return list<int>
     */
    private function pickRandomIds(array $pool, int $count, ?int $exclude = null): array
    {
        if ($pool === []) {
            return [];
        }

        $count = min($count, count($pool));

        if ($count < 1) {
            return [];
        }

        $keys = (array) array_rand($pool, $count);
        $ids = array_map(static fn (int $key): int => $pool[$key], $keys);

        if ($exclude !== null) {
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $exclude));
        }

        return $ids;
    }
}
