<?php

declare(strict_types=1);

namespace Modules\CMS\Import\Upserters;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Modules\CMS\Import\Dto\ImportTagDto;
use Modules\CMS\Import\Support\ExternalReferenceLocator;
use Modules\CMS\Import\Support\ImportConnectionContext;
use Modules\CMS\Import\Support\ImportReferenceResolver;
use Modules\CMS\Import\Support\TagMatcher;
use Modules\CMS\Models\Tag;
use Modules\CMS\Models\Translations\TagTranslation;
use Modules\Core\Import\Support\ImportFingerprint;

final class TagUpserter
{
    public function __construct(
        private readonly ExternalReferenceLocator $locator,
        private readonly ImportReferenceResolver $reference_resolver,
        private readonly TagMatcher $tag_matcher,
        private readonly string $locale,
    ) {}

    /**
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public function participantModelClasses(): array
    {
        return [Tag::class, TagTranslation::class, \Modules\Core\Models\RecordOrigin::class];
    }

    /**
     * A tag is one per locale and slug whatever source brings it: it is found by this source's origin, then by
     * slug, and created only when neither finds it. A tag another source also brought keeps its name, its type
     * and its life: this source adds its origin and nothing else.
     */
    public function upsert(ImportTagDto $dto, ?ImportConnectionContext $context = null): int
    {
        $context ??= new ImportConnectionContext(new Tag);
        $tag_model = $context->model(Tag::class);
        $slug = TagTranslation::slugFromName($dto->slug);
        $existing_id = $this->reference_resolver->resolve(
            'tags',
            Tag::class,
            $dto->externalId,
            $dto->sourceType,
            $context,
        ) ?? $this->tag_matcher->findExisting($slug, $this->locale, $context);

        $tag = $existing_id !== null
            ? $this->existingTag($tag_model, $existing_id)
            : $this->createTag($tag_model, $dto, $slug, $context);

        if (! $tag->wasRecentlyCreated) {
            $this->updateTag($tag, $dto, $slug);
        }

        if ($dto->deletedAt !== null && ! $tag->trashed() && ! $this->locator->hasOriginFromAnotherSource($tag, $dto->sourceType)) {
            $tag->delete();
        }

        $tag_id = (int) $tag->id;
        $this->reference_resolver->remember('tags', $dto->externalId, $tag_id, $dto->sourceType, $context);

        $this->locator->register(
            $tag,
            $dto->sourceType,
            $dto->externalId,
            $dto->originLabel,
            $dto->originUrl,
            ImportFingerprint::of($dto),
            $dto->updatedAt,
        );

        return $tag_id;
    }

    private function existingTag(Tag $tag_model, int $tag_id): Tag
    {
        // Bypass the soft-delete global scope: an id resolved from the origin
        // registry or a translation slug may point to a soft-deleted tag, which
        // must still be found (and reused) rather than crashing the import.
        return $tag_model->newQueryWithoutScopes()->whereKey($tag_id)->firstOrFail();
    }

    /**
     * Creates the tag and its translation together. When another import created the same slug in between, the
     * unique key refuses this one, the savepoint drops it, and the other import's tag is used.
     */
    private function createTag(Tag $tag_model, ImportTagDto $dto, string $slug, ImportConnectionContext $context): Tag
    {
        try {
            return $tag_model->getConnection()->transaction(function () use ($tag_model, $dto, $slug): Tag {
                $tag = $tag_model->newInstance(['type' => $dto->type]);
                $this->applyPosition($tag, $dto);
                $tag->save();
                $tag->setTranslation($this->locale, [
                    'name' => $dto->name,
                    'slug' => $slug,
                ]);

                return $tag;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing_id = $this->tag_matcher->findExisting($slug, $this->locale, $context);

            if ($existing_id === null) {
                throw $exception;
            }

            return $this->existingTag($tag_model, $existing_id);
        }
    }

    private function updateTag(Tag $tag, ImportTagDto $dto, string $slug): void
    {
        // A record that reappears in the source must be revived before it can be
        // updated: soft-deleted models reject updates ("Cannot update a softdeleted
        // model"). reviveInMemory() lets the save() below persist the restoration in
        // a single write. If the source still marks it deleted, it is re-deleted later.
        if ($tag->trashed()) {
            $tag->reviveInMemory();
        }

        if (! $tag->adoptType($dto->type)) {
            Log::warning('Tag type conflict during import', [
                'tag_id' => $tag->id,
                'slug' => $slug,
                'tag_type' => $tag->type,
                'source_type' => Tag::normalizeType($dto->type),
                'source' => $dto->sourceType,
                'external_id' => $dto->externalId,
            ]);
        }

        $this->applyPosition($tag, $dto);
        $tag->save();

        if (! $tag->hasTranslation($this->locale) || ! $this->locator->hasOriginFromAnotherSource($tag, $dto->sourceType)) {
            $tag->setTranslation($this->locale, [
                'name' => $dto->name,
                'slug' => $slug,
            ]);
        }
    }

    /**
     * The source owns the position when it has one; otherwise the tag keeps its own, and a new one goes last.
     */
    private function applyPosition(Tag $tag, ImportTagDto $dto): void
    {
        if ($dto->orderColumn !== null) {
            $tag->order_column = $dto->orderColumn;
            $tag->keepPositionWhenCreating();
        }
    }
}
