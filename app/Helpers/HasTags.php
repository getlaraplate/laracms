<?php

declare(strict_types=1);

namespace Modules\CMS\Helpers;

use ArrayAccess;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use Modules\CMS\Contracts\Taggable;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Pivot\Taggable as TaggablePivot;
use Modules\CMS\Models\Tag;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-require-implements \Modules\CMS\Contracts\Taggable
 */
trait HasTags
{
    /**
     * @var list<string|Tag>
     */
    protected array $queuedTags = [];

    public static function bootHasTags(): void
    {
        static::created(function (Model $taggable_model): void {
            if (! $taggable_model instanceof Taggable) {
                return;
            }

            if ($taggable_model->getQueuedTags() === []) {
                return;
            }

            $taggable_model->attachTags($taggable_model->getQueuedTags());
            $taggable_model->clearQueuedTags();
        });

        static::deleted(function (Model $deleted_model): void {
            if (! $deleted_model instanceof Taggable) {
                return;
            }

            if (! in_array(HasTags::class, class_uses_recursive($deleted_model), true)) {
                return;
            }

            if (! method_exists($deleted_model, 'detachModelTags')) {
                return;
            }

            // A soft delete keeps the row, and its tags with it, for the restore. Only a row that is
            // gone has to be detached: the taggables table has no foreign key on the taggable side.
            if ($deleted_model->exists) {
                return;
            }

            $deleted_model->detachModelTags();
        });
    }

    /**
     * @return list<string|Tag>
     */
    public function getQueuedTags(): array
    {
        return $this->queuedTags;
    }

    public function clearQueuedTags(): void
    {
        $this->queuedTags = [];
    }

    /**
     * @return MorphToMany<Tag, $this, TaggablePivot, 'pivot'>
     */
    public function tags(): MorphToMany
    {
        return $this
            ->morphToMany(Tag::class, 'taggable', CMSTables::Taggables->value)
            ->using(TaggablePivot::class)
            ->withTimestamps()
            ->ordered();
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tagsWithType(?string $type = null): Collection
    {
        $type = Tag::normalizeType($type);

        return $this->tags->filter(fn (Tag $tag): bool => $tag->type === $type);
    }

    /**
     * @param  array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>|string|Tag  $tags
     */
    public function attachTags(array|ArrayAccess|string|Tag $tags, ?string $type = null): static
    {
        $resolved_tags = $this->resolveTagsFromInput($tags, $type);

        $this->tags()->syncWithoutDetaching(
            $resolved_tags->pluck('id')->filter(static fn (mixed $id): bool => is_int($id) || is_string($id))->map(static function (mixed $id): int {
                if (is_int($id)) {
                    return $id;
                }

                return (int) $id;
            })->all(),
        );

        return $this;
    }

    public function attachTag(string|Tag $tag, ?string $type = null): static
    {
        return $this->attachTags([$tag], $type);
    }

    /**
     * @param  array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $tags
     */
    public function detachTags(array|ArrayAccess $tags, ?string $type = null): static
    {
        $resolved_tags = static::convertToTags($tags, $type);

        foreach ($resolved_tags as $tag) {
            $this->tags()->detach($tag);
        }

        return $this;
    }

    public function detachTag(string|Tag $tag, ?string $type = null): static
    {
        return $this->detachTags([$tag], $type);
    }

    /**
     * @param  string|Tag|array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $tags
     */
    public function syncTags(string|array|ArrayAccess|Tag $tags): static
    {
        $resolved_tags = $this->resolveTagsFromInput($tags, null);

        $this->tags()->sync(
            $resolved_tags->pluck('id')->filter(static fn (mixed $id): bool => is_int($id) || is_string($id))->map(static function (mixed $id): int {
                if (is_int($id)) {
                    return $id;
                }

                return (int) $id;
            })->all(),
        );

        return $this;
    }

    /**
     * @param  array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $tags
     */
    public function syncTagsWithType(array|ArrayAccess $tags, ?string $type = null): static
    {
        $resolved_tags = $this->resolveTagsFromInput($tags, $type);

        $this->syncTagIds(
            array_values($resolved_tags->pluck('id')->filter(static fn (mixed $id): bool => is_int($id) || is_string($id))->map(static function (mixed $id): int {
                if (is_int($id)) {
                    return $id;
                }

                return (int) $id;
            })->all()),
            $type,
        );

        return $this;
    }

    public function hasTag(string|int|Tag $tag, ?string $type = null): bool
    {
        $type = Tag::normalizeType($type);

        return $this->tags
            ->when($type !== null, fn (Collection $tags): Collection => $tags->where('type', $type))
            ->contains(fn (Tag $model_tag): bool => $model_tag->name === $tag || $model_tag->id === $tag);
    }

    /**
     * @param  string|Tag|array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $values
     * @return SupportCollection<int, Tag>
     */
    protected static function convertToTags(string|array|ArrayAccess|Tag $values, ?string $type = null): SupportCollection
    {
        $type = Tag::normalizeType($type);

        return SupportCollection::make(self::normalizeTagsInput($values))
            ->map(function (string|Tag $value) use ($type): ?Tag {
                if ($value instanceof Tag) {
                    throw_if(isset($type) && $value->type !== $type, InvalidArgumentException::class, sprintf('Type was set to %s but tag is of type %s', $type, $value->type));

                    return $value;
                }

                return Tag::findFromString($value, $type);
            })
            ->filter(static fn (?Tag $tag): bool => $tag instanceof Tag)
            ->values();
    }

    /**
     * @param  string|Tag|array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $values
     * @return SupportCollection<int, Tag>
     */
    protected static function convertToTagsOfAnyType(string|array|ArrayAccess|Tag $values): SupportCollection
    {
        return SupportCollection::make(self::normalizeTagsInput($values))
            ->flatMap(function (string|Tag $value): SupportCollection {
                if ($value instanceof Tag) {
                    return SupportCollection::make([$value]);
                }

                return SupportCollection::make(Tag::findFromStringOfAnyType($value)->all());
            })
            ->values();
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|list<string|Tag>|ArrayAccess<int|string, string|Tag>|Tag  $tags
     * @return Builder<static>
     */
    #[Scope]
    protected function withAllTags(
        Builder $query,
        string|array|ArrayAccess|Tag $tags,
        ?string $type = null,
    ): Builder {
        $resolved_tags = static::convertToTags($tags, $type);

        foreach ($resolved_tags as $tag) {
            $query->whereHas('tags', function (Builder $tag_query) use ($tag): void {
                $tag_query->whereRaw('tags.id = ?', [$tag->id ?? 0]);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|list<string|Tag>|ArrayAccess<int|string, string|Tag>|Tag  $tags
     * @return Builder<static>
     */
    #[Scope]
    protected function withAnyTags(
        Builder $query,
        string|array|ArrayAccess|Tag $tags,
        ?string $type = null,
    ): Builder {
        $resolved_tags = static::convertToTags($tags, $type);
        $tag_ids = $resolved_tags->pluck('id')->filter(static fn (mixed $id): bool => $id !== null)->values();

        return $query->whereHas('tags', function (Builder $tag_query) use ($tag_ids): void {
            $tag_query->whereIn('tags.id', $tag_ids->all());
        });
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|list<string|Tag>|ArrayAccess<int|string, string|Tag>|Tag  $tags
     * @return Builder<static>
     */
    #[Scope]
    protected function withoutTags(
        Builder $query,
        string|array|ArrayAccess|Tag $tags,
        ?string $type = null,
    ): Builder {
        $resolved_tags = static::convertToTags($tags, $type);
        $tag_ids = $resolved_tags->pluck('id')->filter(static fn (mixed $id): bool => $id !== null)->values();

        return $query->whereDoesntHave('tags', function (Builder $tag_query) use ($tag_ids): void {
            $tag_query->whereIn('tags.id', $tag_ids->all());
        });
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|list<string|Tag>|ArrayAccess<int|string, string|Tag>|Tag  $tags
     * @return Builder<static>
     */
    #[Scope]
    protected function withAllTagsOfAnyType(Builder $query, string|array|ArrayAccess|Tag $tags): Builder
    {
        $resolved_tags = static::convertToTagsOfAnyType($tags);

        foreach ($resolved_tags as $tag) {
            $query->whereHas(
                'tags',
                fn (Builder $tag_query): Builder => $tag_query->whereRaw('tags.id = ?', [$tag->id ?? 0]),
            );
        }

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|list<string|Tag>|ArrayAccess<int|string, string|Tag>|Tag  $tags
     * @return Builder<static>
     */
    #[Scope]
    protected function withAnyTagsOfAnyType(Builder $query, string|array|ArrayAccess|Tag $tags): Builder
    {
        $resolved_tags = static::convertToTagsOfAnyType($tags);
        $tag_ids = $resolved_tags->pluck('id')->filter(static fn (mixed $id): bool => $id !== null)->values();

        return $query->whereHas(
            'tags',
            fn (Builder $tag_query): Builder => $tag_query->whereIn('tags.id', $tag_ids->all()),
        );
    }

    protected function detachModelTags(): void
    {
        $this->detachTags($this->tags()->get()->all());
    }

    /**
     * @param  string|Tag|array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $tags
     */
    protected function setTagsAttribute(string|array|ArrayAccess|Tag $tags): void
    {
        if (! $this->exists) {
            $this->queuedTags = $this->normalizeQueuedTags($tags);

            return;
        }

        $this->syncTags($tags);
    }

    /**
     * @param  array<int>  $ids
     */
    protected function syncTagIds(array $ids, ?string $type = null, bool $detaching = true): void
    {
        $is_updated = false;

        $tag_model = $this->tags()->getRelated();

        $current = $this->tags()
            ->newPivotStatement()
            ->where('taggable_id', $this->getKey())
            ->where('taggable_type', $this->getMorphClass())
            ->join(
                $tag_model->getTable(),
                CMSTables::Taggables->value . '.tag_id',
                '=',
                $tag_model->getTable() . '.' . $tag_model->getKeyName(),
            )
            ->where($tag_model->getTable() . '.type', Tag::normalizeType($type))
            ->pluck('tag_id')
            ->filter(static fn (mixed $id): bool => is_int($id) || is_string($id))
            ->map(static function (mixed $id): int {
                if (is_int($id)) {
                    return $id;
                }

                return (int) $id;
            })
            ->all();

        $detach = array_diff($current, $ids);

        if ($detaching && $detach !== []) {
            $this->tags()->detach($detach);
            $is_updated = true;
        }

        $attach = array_values(array_unique(array_diff($ids, $current)));

        if ($attach !== []) {
            $this->tags()->attach($attach, []);

            $is_updated = true;
        }

        if ($is_updated) {
            $this->tags()->touchIfTouching();
        }
    }

    /**
     * @param  string|Tag|array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $values
     * @return list<string|Tag>
     */
    private static function normalizeTagsInput(string|Tag|array|ArrayAccess $values): array
    {
        if ($values instanceof Tag || is_string($values)) {
            return [$values];
        }

        if (is_array($values)) {
            return array_values($values);
        }

        $normalized = [];

        /** @var ArrayAccess<int|string, mixed> $values */
        $index = 0;

        while ($values->offsetExists($index)) {
            $value = $values->offsetGet($index);

            if (is_string($value) || $value instanceof Tag) {
                $normalized[] = $value;
            }

            $index++;
        }

        return $normalized;
    }

    /**
     * @param  string|Tag|array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $tags
     * @return SupportCollection<int, Tag>
     */
    private function resolveTagsFromInput(string|array|ArrayAccess|Tag $tags, ?string $type): SupportCollection
    {
        $created = Tag::findOrCreate($tags, $type);

        if ($created instanceof Tag) {
            return SupportCollection::make([$created]);
        }

        return SupportCollection::make($created->all())->values();
    }

    /**
     * @param  string|Tag|array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $tags
     * @return list<string|Tag>
     */
    private function normalizeQueuedTags(string|array|ArrayAccess|Tag $tags): array
    {
        return self::normalizeTagsInput($tags);
    }
}
