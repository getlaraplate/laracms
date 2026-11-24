<?php

declare(strict_types=1);

namespace Modules\CMS\Import\Upserters;

use Modules\CMS\Import\Dto\ImportContentDto;
use Modules\CMS\Import\Support\EntityPresetResolver;
use Modules\CMS\Import\Support\ExternalReferenceLocator;
use Modules\CMS\Import\Support\ImportConnectionContext;
use Modules\CMS\Import\Support\ImportProgressLogger;
use Modules\CMS\Import\Support\ImportReferenceResolver;
use Modules\CMS\Import\Support\RelatedContentResolver;
use Modules\CMS\Models\Content;
use Modules\Core\Import\Support\ImportFingerprint;
use Symfony\Component\Console\Output\OutputInterface;

final class ContentUpserter
{
    public function __construct(
        private readonly EntityPresetResolver $entity_preset_resolver,
        private readonly ExternalReferenceLocator $locator,
        private readonly ImportReferenceResolver $reference_resolver,
        private readonly RelatedContentResolver $related_content_resolver,
        private readonly ImportProgressLogger $progress_logger,
        private readonly string $locale,
    ) {}

    /**
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public function participantModelClasses(): array
    {
        return [Content::class, \Modules\CMS\Models\Translations\ContentTranslation::class, \Modules\Core\Models\RecordOrigin::class];
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $contributorIds
     * @param  list<int>  $tagIds
     * @param  list<int>  $locationIds
     */
    public function upsert(
        ImportContentDto $dto,
        array $categoryIds = [],
        array $contributorIds = [],
        array $tagIds = [],
        array $locationIds = [],
        ?ImportConnectionContext $context = null,
        ?OutputInterface $output = null,
    ): int {
        $context ??= new ImportConnectionContext(new Content);
        $content_model = $context->model(Content::class);
        $existing_id = $this->reference_resolver->resolve(
            'contents',
            Content::class,
            $dto->externalId,
            $dto->sourceType,
            $context,
        );

        $entity_id = $this->entity_preset_resolver->entityId($dto->entityName, $context, $dto->preferredEntityName);
        $presettable = $this->entity_preset_resolver->presettable($dto->entityName, $dto->presetName, $context, $dto->preferredEntityName);
        $presettable_id = (int) $presettable->id;

        if ($existing_id !== null) {
            $content = $content_model->newQueryWithoutScopes()->without('presettable')->whereKey($existing_id)->firstOrFail();
        } else {
            $content = $content_model->newInstance();
        }

        // Set before any attribute, which would otherwise load the presettable to
        // tell dynamic fields apart: the resolver already holds it.
        $content->setRelation('presettable', $presettable);

        $created = $existing_id === null;

        // A record that reappears in the source must be revived before it can be
        // updated: soft-deleted models reject updates ("Cannot update a softdeleted
        // model"). reviveInMemory() lets the save() below persist the restoration in
        // a single write. If the source still marks it deleted, it is re-deleted below.
        if ($content->exists && $content->trashed()) {
            $content->reviveInMemory();
        }

        $content->entity_id = $entity_id;
        $content->presettable_id = $presettable_id;
        $content->shared_components = $dto->sharedComponents;
        // The date cast parses the DTO's strings, as it always did; setAttribute() says so to the analyser.
        $content->setAttribute('valid_from', $dto->validFrom);
        $content->setAttribute('valid_to', $dto->validTo);
        $content->save();

        $content->setTranslation($this->locale, [
            'title' => $dto->title,
            'slug' => $dto->slug,
            'components' => $dto->components,
        ]);

        foreach ($dto->translations as $locale => $translation) {
            if ($locale === $this->locale) {
                continue;
            }

            $content->setTranslation($locale, [
                'title' => $translation['title'],
                'slug' => $translation['slug'],
                'components' => $translation['components'],
            ]);
        }

        $content->save();

        if ($categoryIds !== []) {
            $content->categories()->sync($categoryIds);
        }

        if ($contributorIds !== []) {
            $content->contributors()->sync($contributorIds);
        }

        if ($tagIds !== []) {
            $content->tags()->sync($tagIds);
        }

        if ($locationIds !== []) {
            $content->locations()->sync($locationIds);
        }

        $related_ids = $this->related_content_resolver->resolveContentIds($dto->relatedContents, $context);

        if ($related_ids !== []) {
            $content->related()->sync($related_ids);
        }

        if ($dto->deletedAt !== null && ! $content->trashed()) {
            $content->delete();
        }

        $content_id = (int) $content->id;
        $this->reference_resolver->remember('contents', $dto->externalId, $content_id, $dto->sourceType, $context);

        foreach ($dto->familyExternalIds as $family_external_id) {
            $this->reference_resolver->remember('contents', $family_external_id, $content_id, $dto->sourceType, $context);
        }

        $this->locator->register(
            $content,
            $dto->sourceType,
            $dto->externalId,
            $dto->originLabel,
            $dto->originUrl,
            ImportFingerprint::of($dto),
            $dto->updatedAt,
        );

        $this->progress_logger->contentImported($dto, $created, $output);

        return $content_id;
    }
}
