<?php

declare(strict_types=1);

namespace Modules\CMS\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CMS\Models\Content;
use Modules\CMS\Services\ContentExtensionCascade;
use Modules\Core\Search\Extensions\HidesExtendedRows;
use Override;

/**
 * Wires a domain model as a content extender for the content-extension seam.
 *
 * The using class must implement {@see \Modules\CMS\Contracts\ExtendsContent} and own a
 * `content_id` foreign key (unique — one extender per content, C15). The trait provides the
 * back-relation with the hide scope removed (C8), always-loads the content, and stamps
 * `extended_type` on the content when the extender is saved (C2). It is base-class agnostic:
 * a real extender extends `Modules\Core\Overrides\Model`, this test-friendly wiring works on any
 * Eloquent model.
 *
 * @property int|string|null $content_id
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-require-implements \Modules\CMS\Contracts\ExtendsContent
 */
trait ExtendsContentTrait
{
    private ?Content $tempContent = null;

    /**
     * Cascade the extender's own lifecycle to its content (C9): soft/force delete and restore travel
     * to the content, guarded so the reverse handler does not re-fire (C10).
     */
    public static function bootExtendsContentTrait(): void
    {
        // The content's search document carries the extender's `searchableExtension()` fields, but
        // the content is saved (and indexed) before its extender in `save()`, and an extender edit
        // alone never touches the content. Re-send the content after every extender save so its
        // document never lags behind the extension.
        static::saved(static function (Model $model): void {
            $content = $model->getRelationValue('content');

            if ($content instanceof Content && ! $content->trashed()) {
                $content->searchable();
            }
        });

        static::deleting(static function (Model $model): void {
            ContentExtensionCascade::guard(static function () use ($model): void {
                $content = $model->getRelationValue('content');

                if (! $content instanceof Content) {
                    return;
                }

                if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                    $content->forceDelete();

                    return;
                }

                $content->delete();
            });
        });

        static::restoring(static function (Model $model): void {
            ContentExtensionCascade::guard(static function () use ($model): void {
                $contentId = $model->getAttribute('content_id');

                if ($contentId === null) {
                    return;
                }

                $content = Content::withExtended()->withTrashed()->find($contentId);

                if ($content === null) {
                    return;
                }

                // reviveInMemory() + save() is Core's restore path for optimistic-locked models;
                // a bare restore() trips the "cannot update a softdeleted model" guard.
                $content->reviveInMemory();
                $content->save();
            });
        });
    }

    /**
     * Always eager-load the extended content alongside the extender.
     */
    public function initializeExtendsContentTrait(): void
    {
        if (! in_array('content', $this->with, true)) {
            $this->with[] = 'content';
        }
    }

    /**
     * Whether this extender cannot exist without its content (C9). Extenders whose content is
     * optional override this to `false`.
     */
    public function contentIsMandatory(): bool
    {
        return true;
    }

    /**
     * The extended content, with the hide scope removed so the owner can always resolve it (C8).
     *
     * @return BelongsTo<Content, $this>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'content_id')
            ->withoutGlobalScope(HidesExtendedRows::class);
    }

    /**
     * The host of the Core extension contracts this model extends.
     *
     * @return class-string<Content>
     */
    public function extendedModel(): string
    {
        return Content::class;
    }

    /**
     * The alias stored in `contents.extended_type`: the content alias of the extender.
     */
    public function extensionAlias(): string
    {
        return $this->contentAlias();
    }

    public function extensionHostKey(): string
    {
        return 'content_id';
    }

    public function extensionHostRelation(): string
    {
        return 'content';
    }

    /**
     * The entity whose presets shape the extender's contents, so a dynamic field named like one of its own columns
     * is refused there (XS17). Null by default: an extender with its own content entity returns its type.
     */
    public function extensionEntityType(): ?string
    {
        return null;
    }

    /**
     * The columns of the extender's own table. An extender that keeps an explicit list of its columns (the keys
     * its merge routes to itself) returns that list instead.
     *
     * @return list<string>
     */
    public function extensionOwnColumns(): array
    {
        return array_values($this->getConnection()->getSchemaBuilder()->getColumnListing($this->getTable()));
    }

    /**
     * Stage a content to be persisted and linked when this extender is saved.
     */
    public function setTempContent(?Content $content): void
    {
        $this->tempContent = $content;
    }

    /**
     * Persist the staged content (stamping `extended_type`), link it, then save the extender —
     * all in one transaction. Mirrors CMS `Contributor`'s `save()` bridge to its `User`.
     *
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function save(array $options = []): bool
    {
        if (! $this->tempContent instanceof Content) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            $content = $this->tempContent;

            // extended_type is guarded; set it directly so it is written only through this path (C17).
            $content->extended_type = $this->contentAlias();
            $content->save();

            $this->content_id = $content->getKey();
            $this->tempContent = null;

            $saved = parent::save($options);

            $this->setRelation('content', $content);

            return $saved;
        });
    }
}
