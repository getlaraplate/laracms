<?php

declare(strict_types=1);

namespace Modules\CMS\Import;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\CMS\Import\Support\ExternalReferenceLocator;
use Modules\CMS\Models\Tag;
use Modules\CMS\Models\Translations\TagTranslation;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Import\Contracts\EntityImporterInterface;
use Modules\Core\Import\Enums\ImportRowOutcome;
use Modules\Core\Import\Exceptions\RowImportException;
use Modules\Core\Import\Support\ImportRowContext;
use Modules\Core\Import\Support\RecordOriginRegistry;
use Modules\Core\Import\ValueObjects\ExternalRecordIdentity;
use Modules\Core\Import\ValueObjects\ImportField;
use Override;

/**
 * Bulk-imports CMS tags. A tag is one per locale and slug, so a row is matched by the
 * slug it gives, or the slug of its name, in the import locale, whatever its `type`;
 * a trashed match is restored. The row's type is given to a tag that has none. The
 * name is written into the import locale unless another source also brought the tag,
 * which then keeps its name.
 */
final readonly class TagImporter implements EntityImporterInterface
{
    public function __construct(
        private RecordOriginRegistry $origins,
        private ExternalReferenceLocator $locator,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'cms.tag';
    }

    #[Override]
    public function label(): string
    {
        return 'Tags';
    }

    /**
     * @return list<ImportField>
     */
    #[Override]
    public function fields(): array
    {
        return [
            new ImportField('name', 'Name', required: true, aliases: ['label', 'title']),
            new ImportField('type', 'Type', aliases: ['group', 'category']),
            new ImportField('slug', 'Slug'),
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    #[Override]
    public function import(array $row, ImportRowContext $context): ImportRowOutcome
    {
        $name = mb_trim($row['name'] ?? '');
        $type = mb_trim($row['type'] ?? '') ?: null;
        $slug = TagTranslation::slugFromName($row['slug'] ?? '');

        $validator = Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:255']]);

        if ($validator->fails()) {
            throw RowImportException::withErrors($validator->errors()->messages());
        }

        $existing = Tag::findByIdentity($slug !== '' ? $slug : $name, $this->locale());
        $tag = $existing ?? Tag::query()->create(['type' => $type]);

        if ($existing instanceof Tag) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            if (! $existing->adoptType($type)) {
                Log::warning('Tag type conflict during import', [
                    'tag_id' => $existing->id,
                    'tag_type' => $existing->type,
                    'source_type' => Tag::normalizeType($type),
                    'source' => $context->sourceKey(),
                ]);
            }

            $existing->save();
        }

        if (! $existing instanceof Tag || ! $existing->hasTranslation($this->locale()) || ! $this->locator->hasOriginFromAnotherSource($existing, $context->sourceKey())) {
            $translation = ['name' => $name];

            if ($slug !== '') {
                $translation['slug'] = $slug;
            }

            $tag->setTranslation($this->locale(), $translation);
        }

        $this->origins->register(
            $tag,
            new ExternalRecordIdentity($context->sourceKey(), null, hash('sha256', (string) json_encode($row))),
            $context->session->original_filename,
        );

        return $existing instanceof Tag ? ImportRowOutcome::Updated : ImportRowOutcome::Created;
    }

    private function locale(): string
    {
        $locale = config('cms.import.locale') ?? LocaleContext::getDefault();

        return is_string($locale) ? $locale : 'en';
    }
}
