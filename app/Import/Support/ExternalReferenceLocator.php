<?php

declare(strict_types=1);

namespace Modules\CMS\Import\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Location;
use Modules\CMS\Models\Tag;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\CMS\Models\Translations\ContributorTranslation;
use Modules\CMS\Models\Translations\TagTranslation;
use Modules\Core\Import\Support\RecordOriginRegistry;
use Modules\Core\Import\ValueObjects\ExternalRecordIdentity;
use Modules\Core\Models\RecordOrigin;
use Modules\Core\Models\Translations\TaxonomyTranslation;
use Throwable;

/**
 * Resolves and registers import identity/provenance through the generic
 * {@see RecordOrigin} registry (core_record_origins).
 */
final class ExternalReferenceLocator
{
    /**
     * Translation tables used for the deterministic import-slug fallback.
     *
     * @var array<class-string<Model>, array{translation_model: class-string<Model>, foreign_key: string}>
     */
    private const IMPORT_SLUG_TARGETS = [
        Content::class => [
            'translation_model' => ContentTranslation::class,
            'foreign_key' => 'content_id',
        ],
        Category::class => [
            'translation_model' => TaxonomyTranslation::class,
            'foreign_key' => 'taxonomy_id',
        ],
        Contributor::class => [
            'translation_model' => ContributorTranslation::class,
            'foreign_key' => 'contributor_id',
        ],
        Tag::class => [
            'translation_model' => TagTranslation::class,
            'foreign_key' => 'tag_id',
        ],
    ];

    public function __construct(
        private readonly RecordOriginRegistry $registry,
        private readonly string $locale,
    ) {}

    public function findImportedRecordId(
        Model|string $referable,
        int $external_id,
        string $source_type,
        ?Model $target_model = null,
        ?ImportConnectionContext $context = null,
    ): ?int {
        if ($referable instanceof Model) {
            if ($target_model instanceof Model && $referable::class !== $target_model::class) {
                throw new LogicException(sprintf(
                    'Declared import model [%s] does not match target model [%s].',
                    $referable::class,
                    $target_model::class,
                ));
            }

            $target_model = $referable;
        } else {
            if ($target_model instanceof Model && ! $target_model instanceof $referable) {
                throw new LogicException(sprintf(
                    'Declared import model [%s] does not match target model [%s].',
                    $referable,
                    $target_model::class,
                ));
            }

            $target_model ??= $context?->model($referable) ?? new $referable;
        }

        if ($context instanceof ImportConnectionContext) {
            $context->assertModel($target_model);
        }

        return $this->findByOrigin($target_model, $external_id, $source_type)
            ?? $this->findByImportSlug($target_model, $external_id, $source_type);
    }

    public function hasImportedRecord(
        Model|string $referable,
        int $external_id,
        string $source_type,
        ?Model $target_model = null,
        ?ImportConnectionContext $context = null,
    ): bool {
        return $this->findImportedRecordId(
            $referable,
            $external_id,
            $source_type,
            $target_model,
            $context,
        ) !== null;
    }

    /**
     * Whether a source other than `$source_key` brought this record too: a record shared by several sources is
     * not renamed or deleted by any one of them.
     */
    public function hasOriginFromAnotherSource(Model $referable, string $source_key): bool
    {
        return $referable->getConnection()->table((new RecordOrigin)->getTable())
            ->where('referable_type', $referable->getMorphClass())
            ->where('referable_id', $referable->getKey())
            ->where('source_key', '!=', $source_key)
            ->exists();
    }

    /**
     * Persist (or refresh) the origin of a record in the registry. Keyed by the
     * external identity so repeated imports remain idempotent.
     */
    public function register(
        Model $referable,
        string $source_key,
        ?int $external_id,
        ?string $source_label = null,
        ?string $url = null,
        ?string $fingerprint = null,
        ?string $source_updated_at = null,
    ): void {
        $external = $external_id !== null ? (string) $external_id : null;

        $this->registry->register(
            $referable,
            new ExternalRecordIdentity(
                $source_key,
                $external,
                $fingerprint,
                $this->parseSourceTimestamp($source_updated_at),
            ),
            $source_label,
            $url,
        );
    }

    public function importSlug(int $external_id, string $source_type): string
    {
        return 'import-' . preg_replace('/[^a-z0-9_-]+/i', '-', $source_type) . '-' . $external_id;
    }

    /**
     * Source timestamps arrive as whatever string the source system prints. One that
     * cannot be read leaves the column empty instead of failing the row.
     */
    private function parseSourceTimestamp(?string $value): ?CarbonImmutable
    {
        if ($value === null || mb_trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function findByOrigin(Model $referable, int $external_id, string $source_type): ?int
    {
        return $this->registry->referableId($referable, $source_type, (string) $external_id);
    }

    private function findByImportSlug(Model $target_model, int $external_id, string $source_type): ?int
    {
        if ($target_model instanceof Location) {
            return null;
        }

        $target = self::IMPORT_SLUG_TARGETS[$target_model::class] ?? null;

        if ($target === null) {
            return null;
        }

        $translation_model = new $target['translation_model'];

        $local_id = $target_model->getConnection()->table($translation_model->getTable())
            ->where('locale', $this->locale)
            ->where('slug', $this->importSlug($external_id, $source_type))
            ->value($target['foreign_key']);

        return $local_id !== null ? (int) $local_id : null;
    }
}
