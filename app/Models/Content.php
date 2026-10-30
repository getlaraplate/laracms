<?php

declare(strict_types=1);

namespace Modules\CMS\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Casts\ReadingStatistics;
use Modules\CMS\Contracts\ExtendsContent;
use Modules\CMS\Contracts\Taggable;
use Modules\CMS\Database\Factories\ContentFactory;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Helpers\HasMultimedia;
use Modules\CMS\Helpers\HasTags;
use Modules\CMS\Models\Pivot\Categorizable;
use Modules\CMS\Models\Pivot\Contributable;
use Modules\CMS\Models\Pivot\Locatable;
use Modules\CMS\Models\Pivot\Relatable;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\CMS\Observers\ContentObserver;
use Modules\CMS\Scopes\HidesExtendedContent;
use Modules\CMS\Services\ContentExtenderRegistry;
use Modules\Core\Approvals\Operation;
use Modules\Core\Contracts\IDynamicContentModel;
use Modules\Core\Contracts\IDynamicEntityTypable;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Contracts\IOptimisticLockableModel;
use Modules\Core\Contracts\IPreloadsAppendedRelations;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Contracts\IValidatableModel;
use Modules\Core\Contracts\ProvidesDefaultSearchFilters;
use Modules\Core\Contracts\ProvidesFacetLabelSources;
use Modules\Core\Contracts\ProvidesSyncableRelations;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\Locking\Traits\HasOptimisticLocking;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Concerns\HasPath;
use Modules\Core\Models\Concerns\HasTranslatedDynamicContents;
use Modules\Core\Models\Concerns\HasValidity;
use Modules\Core\Models\Concerns\SortableTrait;
use Modules\Core\Models\RecordOrigin;
use Modules\Core\Overrides\Model;
use Modules\Core\Search\Contracts\IProvidesRerankerText;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\Search\Traits\Searchable;
use Modules\Core\Services\Crud\DTOs\FacetLabelSource;
use Override;
use Spatie\EloquentSortable\Sortable;
use Spatie\MediaLibrary\HasMedia;

/**
 * @phpstan-use HasMultimedia<Content>
 * @phpstan-use HasTranslatedDynamicContents<Content>
 * @phpstan-use HasValidity<Content>
 * @phpstan-use Searchable<Content>
 */
#[ObservedBy(ContentObserver::class)]
final class Content extends Model implements HasMedia, IDynamicContentModel, ILockableModel, IOptimisticLockableModel, IPreloadsAppendedRelations, IProvidesRerankerText, ISearchableModel, IValidatableModel, ProvidesDefaultSearchFilters, ProvidesFacetLabelSources, ProvidesSyncableRelations, Sortable, Taggable
{
    // region Traits
    use HasApprovals {
        HasApprovals::toArray as private approvalsToArray;
        HasApprovals::requiresApprovalWhen as private requiresApprovalWhenTrait;
        HasApprovals::requiresApprovalForOperation as private requiresApprovalForOperationTrait;
    }
    use HasLocks;
    use HasMultimedia;
    use HasOptimisticLocking;
    use HasPath;
    use HasTags;
    use HasTranslatedDynamicContents {
        HasTranslatedDynamicContents::getRules as private getRulesTranslatedDynamicContents;
        HasTranslatedDynamicContents::toArray as private translatedDynamicContentsToArray;
        HasTranslatedDynamicContents::casts as private translatedDynamicContentsCasts;
    }
    use HasValidity;
    use Searchable {
        Searchable::toSearchableArray as private toSearchableArrayTrait;
        Searchable::getSearchMapping as private getSearchMappingTrait;
    }
    use SortableTrait {
        SortableTrait::scopeOrdered as private scopePriorityOrdered;
    }

    /**
     * Characters of a content's text handed to a reranker: its model reads a few hundred tokens at most.
     */
    private const int RERANKER_TEXT_LIMIT = 2000;

    /**
     * @var string
     */
    #[Override]
    protected $table = CMSTables::Contents->value;

    #[Override]
    protected $hidden = [
        'withCaching',
        'withoutObjectCaching',
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $appends = [
        'statistics',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected array $embed = ['title', 'textual_only'];

    /**
     * @var array<string, mixed>
     */
    protected array $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    public static function getEntityModelClass(): string
    {
        return Entity::class;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function makeWithDefaults(array $attributes = []): static
    {
        $model = new self($attributes);
        $model->setDefaultEntityAndPreset();

        return $model;
    }

    /**
     * Public because five callers outside this class ask for it, and because
     * HasDynamicContents declares it abstract public: protected here was a
     * narrowing PHP tolerates from a trait and refuses from an interface.
     */
    public static function getEntityType(): IDynamicEntityTypable
    {
        return EntityType::Contents;
    }

    /**
     * The title and the text components of the translation in the language asked: all a reranker has to read,
     * since a search hit carries the content's own columns and the text lives in its translations. A content
     * with no translation in that language is left out, and the text is cut to a length a reranker can use.
     *
     * @param  list<int|string>  $keys
     * @return array<int|string, string>
     */
    #[Override]
    public static function rerankerTexts(array $keys, string $locale): array
    {
        $texts = [];

        $contents = self::query()
            ->withoutGlobalScope(\Modules\Core\Overrides\LocaleScope::class)
            ->whereKey($keys)
            ->with('translations')
            ->get();

        foreach ($contents as $content) {
            $translation = $content->findContentTranslation($locale);

            if (! $translation instanceof ContentTranslation) {
                continue;
            }

            $parts = [$translation->title];

            foreach (($translation->components ?? []) as $value) {
                $parts[] = $content->searchableComponentValue($value);
            }

            $text = mb_trim(implode(' ', array_filter($parts, static fn (mixed $part): bool => is_string($part) && $part !== '')));

            if ($text !== '') {
                $texts[$content->getKey()] = mb_substr($text, 0, self::RERANKER_TEXT_LIMIT);
            }
        }

        return $texts;
    }

    /**
     * Facet label sources for foreign keys exposed through accessors rather than a
     * BelongsTo: the content type (`entity_id`) labels from the entity name.
     *
     * @return array<string, FacetLabelSource>
     */
    #[Override]
    public function facetLabelSources(): array
    {
        return [
            'entity' => new FacetLabelSource(relatedClass: Entity::class, foreignKey: 'entity_id'),
        ];
    }

    /**
     * Generic content search excludes extended contents by filtering `extended_type IS NULL` at the
     * engine (`= null` becomes `IS NULL` in the query builder), so they never come back from the
     * engine only to be dropped at rehydration by the hide scope. An opt-in surface (e.g. a shop
     * search) can query them via the per-alias `extended_type` value.
     *
     * @return array<string, scalar|null>
     */
    #[Override]
    public function defaultSearchFilters(): array
    {
        return ['extended_type' => null];
    }

    /**
     * Relations the generic CRUD update may sync from lists of ids (authoring the
     * content's tags, categories, locations and contributors from the SPA).
     *
     * @return list<string>
     */
    #[Override]
    public function syncableRelations(): array
    {
        return ['tags', 'categories', 'locations', 'contributors'];
    }

    /**
     * The folders that belong to the content.
     *
     * @return BelongsToMany<Category, $this, Categorizable, 'pivot'>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, CMSTables::Categorizables->value, 'content_id', 'taxonomy_id')
            ->using(Categorizable::class)
            ->withTimestamps();
    }

    /**
     * The locations that belong to the content.
     *
     * @return BelongsToMany<Location, $this, Locatable, 'pivot'>
     */
    public function locations(): BelongsToMany
    {
        $relation = $this->belongsToMany(Location::class, CMSTables::Locatables->value)->using(Locatable::class)->withTimestamps();
        $relation->withTrashed();

        return $relation;
    }

    /**
     * The contributors that belong to the content.
     *
     * @return BelongsToMany<Contributor, $this, Contributable, 'pivot'>
     */
    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(Contributor::class, CMSTables::Contributables->value)->using(Contributable::class)->withTimestamps();
    }

    /**
     * Comments on this content (approved/persisted rows only).
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<ContentRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(ContentRating::class);
    }

    /**
     * The external references (bibliography) cited by this content.
     *
     * @return HasMany<ContentReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(ContentReference::class);
    }

    /**
     * The provenance of this content (external origin source or manual attribution).
     *
     * @return MorphOne<RecordOrigin, $this>
     */
    public function origin(): MorphOne
    {
        return $this->morphOne(RecordOrigin::class, 'referable');
    }

    /**
     * The related contents that belong to the content.
     *
     * @return BelongsToMany<Content, $this, Relatable, 'pivot'>
     */
    public function related(?bool $withInverse = false): BelongsToMany
    {
        $relation = $this->belongsToMany(
            self::class,
            CMSTables::Relatables->value,
            'content_id',
            'related_content_id',
        )->using(Relatable::class)->withTimestamps();

        if ($withInverse === true) {
            $relation->orWhere(
                fn (Builder $query) => $query->where(
                    DB::raw(CMSTables::Relatables->value . '.related_content_id'),
                    $this->id,
                ),
            );
        }

        return $relation;
    }

    /**
     * Get the relations to eager load when indexing the model.
     *
     * @return list<string>
     */
    public function toSearchableWith(): array
    {
        return [
            'contributors',
            'categories',
            // Categories are hierarchical: the `path` field serialized below walks
            // each category's ancestors (translated slugs). Eager-load them here so
            // building the document reads the loaded chain instead of firing one
            // recursive-ancestor query per category per content.
            'categories.translations',
            'categories.ancestors.translations',
            'tags',
            'locations',
            'references',
            'translations',
            'presettable.entity',
            'presettable.preset',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        // TODO: transform splitted values into objects? (contributors, categories, tags, locations)
        $document = $this->toSearchableArrayTrait();
        // $document['entity'] = $this->entity->name;
        // $document['entity_id'] = $this->entity->id;
        $preset = $this->preset;
        $document['preset'] = $preset !== null ? $preset->name : '';
        // $document['preset_id'] = $this->preset->id;
        $document['contributors'] = $this->contributors->map(fn (Contributor $contributor) => $contributor->only(['id', 'name', 'slug', 'path']))->values()->all();
        $document['categories'] = $this->categories->map(fn (Category $category) => $category->only(['id', 'name', 'slug', 'path']))->values()->all();
        $document['tags'] = $this->tags->map(fn (Tag $tag) => $tag->only(['id', 'name', 'slug', 'path']))->values()->all();
        $document['locations'] = $this->locations->map(fn (Location $location) => $location->only([
            'id',
            'name',
            'slug',
            'path',
            'address',
            'city',
            'province',
            'country',
            'postcode',
            'zone',
        ]))->values()->all();
        $document['type'] = $this->type;
        $document['references'] = $this->referencesSearchText();

        // Load all translations for indexing: title/slug/components become
        // locale-keyed objects instead of flat title_<locale>/slug_<locale> keys,
        // and `locales` records which translations actually exist.
        $available = LocaleContext::getAvailable();
        $title = [];
        $slug = [];
        $components = [];
        $locales = [];

        foreach ($available as $locale) {
            $translation = $this->findContentTranslation($locale);

            if (! $translation instanceof ContentTranslation) {
                continue;
            }

            $locales[] = $locale;
            $title[$locale] = $translation->title;
            $slug[$locale] = $translation->slug;

            foreach (($translation->components ?? []) as $field => $value) {
                $components[$field][$locale] = $this->searchableComponentValue($value);
            }
        }

        $document['title'] = $title;
        $document['slug'] = $slug;
        $document['locales'] = $locales;

        foreach ($components as $field => $byLocale) {
            $document[$field] = $byLocale;
        }

        // Content-extension seam (C11): a filterable extended_type plus a nested, typed extension
        // section the extender contributes. null for a normal content, so generic search can filter
        // extended_type = null without result holes.
        $document['extended_type'] = $this->extended_type;

        $extension = $this->extensionSearchDocument();

        if ($extension !== null) {
            $document['extension'] = $extension;
        }

        return $document;
    }

    /**
     * Include extended contents in the bulk search import (they are hidden by the default scope).
     * Per-save indexing already sees them, since it uses the model instance rather than a query.
     *
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public function makeAllSearchableUsing(Builder $query): Builder
    {
        // Mirror the Searchable trait default (drop LocaleScope) and additionally include extended
        // contents, which the HidesExtendedContent scope removes from the import query.
        return $query
            ->withoutGlobalScope(\Modules\Core\Overrides\LocaleScope::class)
            ->withoutGlobalScope(HidesExtendedContent::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchMapping(): array
    {
        $schema = $this->getSchemaDefinition();
        $schema->addField(new FieldDefinition('entity', FieldType::Keyword, [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable]));
        $schema->addField(new FieldDefinition('preset', FieldType::Keyword, [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable]));
        $schema->addField(new FieldDefinition('contributors', FieldType::Array, [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable], [
            'relation' => 'contributors',
            'properties' => [
                'name' => FieldType::Text,
                'id' => ['type' => FieldType::Integer, 'filterable' => true],
                'slug' => ['type' => FieldType::Keyword, 'filterable' => true],
                'path' => ['type' => FieldType::Keyword, 'filterable' => true],
            ],
        ]));
        $schema->addField(new FieldDefinition('categories', FieldType::Array, [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable], [
            'relation' => 'categories',
            'properties' => [
                'name' => FieldType::Text,
                'id' => ['type' => FieldType::Integer, 'filterable' => true],
                'slug' => ['type' => FieldType::Keyword, 'filterable' => true],
                'path' => ['type' => FieldType::Keyword, 'filterable' => true],
            ],
        ]));
        $schema->addField(new FieldDefinition('tags', FieldType::Array, [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable], [
            'relation' => 'tags',
            'properties' => [
                'name' => FieldType::Keyword,
                'id' => ['type' => FieldType::Integer, 'filterable' => true],
                'slug' => ['type' => FieldType::Keyword, 'filterable' => true],
                'path' => ['type' => FieldType::Keyword, 'filterable' => true],
            ],
        ]));
        $schema->addField(new FieldDefinition('locations', FieldType::Array, [IndexType::Searchable, IndexType::Filterable], [
            'relation' => 'locations',
            'properties' => [
                'name' => FieldType::Text,
                'id' => ['type' => FieldType::Integer, 'filterable' => true],
                'slug' => ['type' => FieldType::Keyword, 'filterable' => true],
                'path' => FieldType::Keyword,
                'address' => FieldType::Text,
                'city' => ['type' => FieldType::Keyword, 'filterable' => true],
                'province' => ['type' => FieldType::Keyword, 'filterable' => true],
                'country' => ['type' => FieldType::Keyword, 'filterable' => true],
                'postcode' => ['type' => FieldType::Keyword, 'filterable' => true],
                'zone' => ['type' => FieldType::Keyword, 'filterable' => true],
            ],
        ]));
        $schema->addField(new FieldDefinition('type', FieldType::Keyword, [IndexType::Searchable, IndexType::Filterable]));
        $schema->addField(new FieldDefinition('references', FieldType::Text, [IndexType::Searchable]));
        $schema->addField(new FieldDefinition('valid_from', FieldType::Date, [IndexType::Searchable, IndexType::Filterable, IndexType::Sortable]));
        $schema->addField(new FieldDefinition('valid_to', FieldType::Date, [IndexType::Searchable, IndexType::Filterable, IndexType::Sortable]));
        $schema->addField(new FieldDefinition('is_deleted', FieldType::Boolean, [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable]));

        // Add fields for each locale
        $available_locales = LocaleContext::getAvailable();
        $default_translation = $this->findContentTranslation(
            LocaleContext::getDefault(),
        );

        // Get component fields from default translation
        $component_fields = [];

        if ($default_translation instanceof ContentTranslation && $default_translation->components !== null) {
            $component_fields = array_keys($default_translation->components);
        }

        $analyzerMap = is_array(config('search.analyzers')) ? config('search.analyzers') : [];
        $analyzers = fn (string $locale): string => (string) ($analyzerMap[$locale] ?? 'standard');
        $localeText = [];
        $localeKeyword = [];

        foreach ($available_locales as $locale) {
            $localeText[$locale] = ['analyzer' => $analyzers($locale)];
            $localeKeyword[$locale] = ['analyzer' => 'keyword'];
        }

        $schema->addField(new FieldDefinition('title', FieldType::Object, [IndexType::Searchable, IndexType::Fuzzy], ['locale_properties' => $localeText]));
        $schema->addField(new FieldDefinition('slug', FieldType::Object, [IndexType::Searchable], ['locale_properties' => $localeKeyword]));
        $schema->addField(new FieldDefinition('locales', FieldType::Keyword, [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable]));
        $profile = VectorProfileContext::get();
        $schema->addField(new FieldDefinition('embeddings', FieldType::Array, [IndexType::Searchable, IndexType::Vector], $profile instanceof VectorProfile
            ? ['vector' => ['dimensions' => $profile->dimensions, 'similarity' => $profile->similarity]]
            : []));

        // Add base component fields, locale-keyed like title
        foreach ($component_fields as $field) {
            $schema->addField(new FieldDefinition($field, FieldType::Object, [IndexType::Searchable, IndexType::FullText], ['locale_properties' => $localeText]));
        }

        // Content-extension seam (C11, C14): a filterable extended_type, and a nested extension object
        // whose mapping is composed from every registered extender. Absent when no extender is
        // registered, so a plain CMS install maps neither.
        $schema->addField(new FieldDefinition('extended_type', FieldType::Keyword, [IndexType::Filterable]));

        $extension_properties = $this->composeExtensionMapping();

        if ($extension_properties !== []) {
            $schema->addField(new FieldDefinition('extension', FieldType::Object, [IndexType::Searchable, IndexType::Filterable], ['properties' => $extension_properties]));
        }

        return $this->getSearchMappingTrait($schema);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRules(): array
    {
        $rules = parent::getRules();
        $fields = $this->getRulesTranslatedDynamicContents();
        $rules[Model::DEFAULT_RULE] = array_merge($rules[Model::DEFAULT_RULE], $fields);
        $rules['create'] = array_merge($rules['create'], [
            // 'title' => 'required|string|max:255', // Validated in translation
            // 'slug' => 'sometimes|nullable|string|max:255', // Validated in translation
            'entity_id' => 'required|exists:' . CoreTables::Entities->value . ',id',
            'presettable_id' => 'required|exists:' . CoreTables::Presettables->value . ',id',
            'translations' => 'sometimes|array',
            'translations.*.locale' => 'required|string|max:10',
            'translations.*.title' => 'required|string|max:255',
            'translations.*.slug' => 'sometimes|nullable|string|max:255',
            'translations.*.components' => 'sometimes|array',
            'translations.*.ai_assistance' => 'sometimes|string|in:none,generated,translated,edited,summarized',
        ]);
        $rules['update'] = array_merge($rules['update'], [
            // 'title' => 'sometimes|required|string|max:255', // Validated in translation
            // 'slug' => 'sometimes|nullable|string|max:255', // Validated in translation
            'entity_id' => 'sometimes|required|exists:' . CoreTables::Entities->value . ',id',
            'presettable_id' => 'sometimes|required|exists:' . CoreTables::Presettables->value . ',id',
            'translations' => 'sometimes|array',
            'translations.*.locale' => 'required|string|max:10',
            'translations.*.title' => 'sometimes|required|string|max:255',
            'translations.*.slug' => 'sometimes|nullable|string|max:255',
            'translations.*.components' => 'sometimes|array',
            'translations.*.ai_assistance' => 'sometimes|string|in:none,generated,translated,edited,summarized',
        ]);

        return $rules;
    }

    public function getPathPrefix(): string
    {
        $entity = $this->entity;

        return $entity !== null ? $entity->slug : '';
    }

    #[Override]
    public function getPath(): ?string
    {
        return $this->categories->first()?->getPath();
    }

    #[Override]
    public function toArray(): array
    {
        $parsed = parent::toArray();

        return array_merge($parsed, $this->translatedDynamicContentsToArray(), $this->approvalsToArray($parsed));
    }

    /**
     * Set default entity and preset based on class name.
     */
    public function setDefaultEntityAndPreset(): void
    {
        $class_name = class_basename(self::class);
        $entity_name = Str::lower($class_name);

        // Find entity by name
        $entity = self::fetchAvailableEntities(EntityType::Contents)->firstWhere('name', $entity_name);

        if (! $entity) {
            return;
        }

        // Only set if not already set
        $this->entity_id = is_int($entity->id) ? $entity->id : (int) $entity->id;
        $this->setRelation('entity', $entity);

        // Find first available preset for this entity
        $presettable = self::fetchAvailablePresettables(EntityType::Contents)
            ->firstWhere('entity_id', $entity->id);

        if (! $presettable) {
            return;
        }

        $this->presettable_id = $presettable->id;
        $this->setRelation('presettable', $presettable);
    }

    protected static function booted(): void
    {
        // Validity (valid_from/valid_to) is no longer a global scope: publication
        // filtering is a row-level authorization concern, enforced by a role-scoped
        // ACL on `contents.select` seeded for the guest role (see CMSDatabaseSeeder).
        // The anonymous/public reader is thus limited to published contents through
        // the CRUD/ACL layer, while staff roles read every content.
        self::addGlobalScope('global_ordered', static function (Builder $query): void {
            /** @var Builder<Content> $query */
            $query->ordered();
        });

        // Extended contents (the body of a module extender) are hidden by default; opt back in
        // with withExtended(). See the content-extension seam (Modules/CMS/app/Scopes).
        self::addGlobalScope(new HidesExtendedContent());
    }

    protected static function newFactory(): ContentFactory
    {
        return ContentFactory::new();
    }

    /**
     * Include contents that are extended by a module (see {@see ExtendsContent}).
     *
     * Removes only the {@see HidesExtendedContent} scope; soft-delete and every other global scope
     * stay applied. On its own it yields plain `Content` rows; upcasting to the extender is a separate
     * explicit step.
     *
     * @param  Builder<Content>  $query
     */
    #[Scope]
    protected function withExtended(Builder $query): void
    {
        $query->withoutGlobalScope(HidesExtendedContent::class);
    }

    /**
     * Override components accessor to coordinate HasTranslations and HasDynamicContents.
     * When components is translatable, get it from translation and merge with defaults.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function components(): Attribute
    {
        return Attribute::make(
            get: function (): array {
                // components is a translatable field, get it from HasTranslations
                $raw_components = $this->translationsGet('components');

                // If we got components from translation, merge with defaults using HasDynamicContents
                if ($raw_components !== null) {
                    return $this->mergeComponentsValues(is_array($raw_components) ? $raw_components : []);
                }

                // Fallback: if no translation, merge empty array with defaults
                return $this->mergeComponentsValues([]);
            },
            set: function (array $components): void {
                // components is a translatable field, save it through HasTranslations
                $this->translationsSet('components', $components);
            },
        );
    }

    /**
     * @return list<string>
     */
    protected function slugPlaceholders(): array
    {
        // Use title from translation
        return array_values([...array_map(fn (string $field): string => '{' . $field . '}', $this->dynamicSlugFields()), '{title}']);
    }

    /**
     * Unpublished (draft) and expired contents are written directly, so they are deleted and
     * restored directly too; live and scheduled ones go through the shared rule.
     */
    protected function requiresApprovalForOperation(Operation $operation): bool
    {
        if (! $this->isPublished() && ! $this->isScheduled()) {
            return false;
        }

        return $this->requiresApprovalForOperationTrait($operation);
    }

    /**
     * @param  array<string, mixed>  $modifications
     */
    protected function requiresApprovalWhen(array $modifications): bool
    {
        if ($modifications === []) {
            return false;
        }

        // Unpublished (draft) and expired: write-through. Live + scheduled: protect.
        if (! $this->isPublished() && ! $this->isScheduled()) {
            return false;
        }

        return $this->requiresApprovalWhenTrait($modifications);
    }

    /**
     * @return Attribute<ReadingStatistics, never>
     */
    protected function statistics(): Attribute
    {
        return Attribute::make(
            get: function (): ReadingStatistics {
                // `content` is merged into the array form only later by the dynamic-content
                // trait, so during `toArray()` appends it may not be a loaded attribute yet.
                // Guard the read so strict attribute access does not throw — an absent
                // content simply yields empty reading statistics.
                $raw = array_key_exists('content', $this->attributes) ? $this->getAttribute('content') : null;
                $blocks = match (true) {
                    is_array($raw) => $raw['blocks'] ?? [],
                    is_object($raw) => $raw->blocks ?? [],
                    default => [],
                };

                if (! is_iterable($blocks)) {
                    $blocks = [];
                }

                return ReadingStatistics::fromBlocks($blocks);
            },
        );
    }

    /**
     * The cited sources as one text: each reference's label and URL host (the leading `www.` stripped, no
     * path or query), so a search for a source finds the contents that cite it.
     */
    private function referencesSearchText(): string
    {
        $terms = [];

        foreach ($this->references as $reference) {
            $terms[] = $reference->label;
            $host = is_string($reference->url) ? parse_url($reference->url, PHP_URL_HOST) : null;

            if (is_string($host) && $host !== '') {
                $terms[] = (string) preg_replace('/^www\./', '', mb_strtolower($host));
            }
        }

        return mb_trim(implode(' ', array_unique(array_filter($terms, static fn (string $term): bool => $term !== ''))));
    }

    /**
     * An Editor.js document is indexed as its plain text: its blocks differ in
     * shape (the same `data` key is a string in one block type and an object in
     * another), so indexing them as they are breaks the engine's field mapping,
     * and only their text is worth searching.
     */
    private function searchableComponentValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return Str::replaceMatches('/\\n|\\r|\\t/', '', $value);
        }

        $blocks = match (true) {
            is_array($value) && array_key_exists('blocks', $value) => $value['blocks'],
            is_object($value) && property_exists($value, 'blocks') => $value->blocks,
            default => null,
        };

        return is_iterable($blocks) ? ReadingStatistics::plainText($blocks) : $value;
    }

    /**
     * Union of every registered extender's `searchableExtensionMapping()` under the nested
     * `extension` object, plus the alias `type`. Empty when no extender is registered (C13, C14).
     *
     * @return array<string, mixed>
     */
    private function composeExtensionMapping(): array
    {
        $registry = app(ContentExtenderRegistry::class);
        $aliases = $registry->aliases();

        if ($aliases === []) {
            return [];
        }

        $properties = ['type' => ['type' => FieldType::Keyword, 'filterable' => true]];

        foreach ($aliases as $alias) {
            $extender = $registry->resolve($alias);

            foreach ((new $extender())->searchableExtensionMapping() as $field => $definition) {
                $properties[$field] ??= $definition;
            }
        }

        return $properties;
    }

    /**
     * The extender's `searchableExtension()` payload for this content, keyed by its alias `type`,
     * or null when the content is not extended. Resolved through the {@see ContentExtenderRegistry}
     * so CMS never references the concrete extender class (C11).
     *
     * @return array<string, mixed>|null
     */
    private function extensionSearchDocument(): ?array
    {
        $alias = $this->extended_type;

        if ($alias === null) {
            return null;
        }

        $registry = app(ContentExtenderRegistry::class);

        if (! $registry->has($alias)) {
            return null;
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $class */
        $class = $registry->resolve($alias);

        $extender = $class::query()->whereIn('content_id', [$this->getKey()])->first();

        if (! $extender instanceof ExtendsContent) {
            return null;
        }

        return ['type' => $alias, ...$extender->searchableExtension()];
    }

    private function findContentTranslation(string $locale): ?ContentTranslation
    {
        $translation = $this->translations->firstWhere('locale', $locale);

        return $translation instanceof ContentTranslation ? $translation : null;
    }
}
