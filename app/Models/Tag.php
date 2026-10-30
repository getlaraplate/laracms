<?php

declare(strict_types=1);

namespace Modules\CMS\Models;

use ArrayAccess;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as DbCollection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\CMS\Database\Factories\TagFactory;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Pivot\Taggable;
use Modules\CMS\Models\Translations\TagTranslation;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Concerns\HasPath;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\Concerns\SortableTrait;
use Modules\Core\Overrides\LocaleScope;
use Modules\Core\Overrides\Model;
use Override;
use Spatie\EloquentSortable\Sortable;

final class Tag extends Model implements Sortable
{
    use HasPath;
    use HasTranslations;
    use SortableTrait;

    /**
     * @var string
     */
    #[Override]
    protected $table = CMSTables::Tags->value;

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    protected $fillable = [
        // name, slug are now in translations table
        'type',
        'order_column',
    ];

    #[Override]
    protected $hidden = [
        'order_column',
    ];

    protected bool $translation_fallback_enabled = true;

    /**
     * @var array<string, mixed>
     */
    protected array $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    /**
     * @param  string|Tag|array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>  $values
     * @return ($values is string ? self : ($values is Tag ? self : Collection<int, self>))
     */
    public static function findOrCreate(
        string|self|array|ArrayAccess $values,
        ?string $type = null,
    ): Collection|self {
        if ($values instanceof self) {
            return $values;
        }

        $tags = collect(self::normalizeFindOrCreateInput($values))->map(function (string|self $value) use ($type): self {
            if ($value instanceof self) {
                return $value;
            }

            return self::findOrCreateFromString($value, $type);
        });

        if (is_string($values)) {
            $first = $tags->first();

            if (! $first instanceof self) {
                throw new InvalidArgumentException('Unable to resolve tag from string value.');
            }

            return $first;
        }

        /** @var Collection<int, self> $tags */
        return $tags;
    }

    /**
     * @return DbCollection<int, self>
     */
    public static function getWithType(string $type): DbCollection
    {
        return self::query()->withType($type)->get();
    }

    /**
     * The tag a name identifies: the one, trashed included, whose live translation in `$locale` (the default
     * locale when null) has the slug of that name. A tag is one per locale and slug, whatever its type.
     */
    public static function findByIdentity(string $name, ?string $locale = null): ?self
    {
        $tag_id = TagTranslation::query()
            ->where('locale', $locale ?? LocaleContext::getDefault())
            ->where('slug', TagTranslation::slugFromName($name))
            ->value('tag_id');

        if ($tag_id === null) {
            return null;
        }

        return self::withTrashed()->withoutGlobalScope(LocaleScope::class)->whereKey($tag_id)->first();
    }

    /**
     * Whether another tag already has a live translation with `$slug` in `$locale`.
     */
    public static function slugTakenInLocale(string $slug, string $locale, ?int $except_tag_id = null): bool
    {
        return TagTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->when($except_tag_id !== null, static fn (Builder $query): Builder => $query->where('tag_id', '!=', $except_tag_id))
            ->exists();
    }

    /**
     * The tag a name identifies, not trashed; with a type, only if the tag has that type. No type means any type.
     */
    public static function findFromString(string $name, ?string $type = null): ?self
    {
        $tag = self::findByIdentity($name);

        if (! $tag instanceof self || $tag->trashed()) {
            return null;
        }

        $type = self::normalizeType($type);

        return $type === null || $tag->type === $type ? $tag : null;
    }

    /**
     * @return DbCollection<int, self>
     */
    public static function findFromStringOfAnyType(string $name): DbCollection
    {
        return self::query()
            ->whereHas('translations', static function (Builder $query) use ($name): void {
                /** @var Builder<TagTranslation> $query */
                $query->where('name', $name)
                    ->orWhere('slug', $name);
            })
            ->get();
    }

    /**
     * The tag a name identifies, restored if trashed and given `$type` if it has none; created when there is none.
     *
     * @throws InvalidArgumentException When the tag has a type other than `$type`
     */
    public static function findOrCreateFromString(string $name, ?string $type = null): self
    {
        $tag = self::findByIdentity($name);

        if ($tag instanceof self) {
            if ($tag->trashed()) {
                $tag->restore();
            }

            throw_unless($tag->adoptType($type), InvalidArgumentException::class, sprintf('Tag %s has type %s, not %s', $name, $tag->type, self::normalizeType($type)));

            if ($tag->isDirty('type')) {
                $tag->save();
            }

            return $tag;
        }

        $tag = self::query()->create([
            'type' => $type,
        ]);
        // Set name in default locale translation
        $tag->setTranslation(LocaleContext::getDefault(), [
            'name' => $name,
        ]);

        return $tag;
    }

    /**
     * @return Collection<int, string|null>
     */
    public static function getTypes(): Collection
    {
        return self::query()
            ->groupBy('type')
            ->pluck('type')
            ->map(static fn (mixed $type): ?string => is_string($type) ? $type : null)
            ->values();
    }

    /**
     * The form a tag type is stored and compared in: a slug, so "Musical Instrument", " musical_instrument "
     * and "musical-instrument" name the same type. A type with nothing left to slug is no type.
     */
    public static function normalizeType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $normalized = Str::slug($type);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Gives this tag `$type` when it has none, without saving. False when it already has another type, which it
     * keeps: the type is one per tag, never translated.
     */
    public function adoptType(?string $type): bool
    {
        $type = self::normalizeType($type);

        if ($type === null || $this->type === $type) {
            return true;
        }

        if ($this->type === null) {
            $this->type = $type;

            return true;
        }

        return false;
    }

    /**
     * Contents that reference this tag via the taggables morph pivot.
     *
     * @return MorphToMany<Content, $this>
     */
    public function contents(): MorphToMany
    {
        return $this->morphedByMany(Content::class, 'taggable', CMSTables::Taggables->value)
            ->using(Taggable::class)
            ->withTimestamps();
    }

    /**
     * @return array<string, mixed>
     */
    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules['create'] = array_merge($rules['create'], [
            // 'name' => 'required|string|max:255', // Validated in translation
            // 'slug' => 'sometimes|nullable|string|max:255', // Validated in translation
            'translations' => 'sometimes|array',
            'translations.*.locale' => 'required|string|max:10',
            'translations.*.slug' => ['sometimes', 'nullable', 'string', 'max:255', self::freeSlugRule('slug')],
            'translations.*.name' => ['required', 'string', 'max:255', self::freeSlugRule('name')],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            // 'name' => 'sometimes|required|string|max:255', // Validated in translation
            // 'slug' => 'sometimes|nullable|string|max:255', // Validated in translation
            'translations' => 'sometimes|array',
            'translations.*.locale' => 'required|string|max:10',
            'translations.*.slug' => ['sometimes', 'nullable', 'string', 'max:255', self::freeSlugRule('slug', $this->id)],
            'translations.*.name' => ['sometimes', 'required', 'string', 'max:255', self::freeSlugRule('name', $this->id)],
        ]);

        return $rules;
    }

    #[Override]
    public function getPath(): null
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $array = parent::toArray();

        // Merge translatable fields from translation (trasparente)
        $translation = $this->getRelationValue('translation');

        if ($translation) {
            foreach ($this::getTranslatableFields() as $field) {
                if (isset($translation->{$field})) {
                    $array[$field] = $translation->{$field};
                }
            }
        }

        return $array;
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withType(Builder $query, ?string $type = null): void
    {
        $type = self::normalizeType($type);

        if (! is_null($type)) {
            $query->where('type', $type)->ordered();
        }
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function containing(Builder $query, string $name, ?string $locale = null): void
    {
        $resolved_locale = $locale ?? $this->getCurrentLocale();

        $this->applyContainingByTranslatedName($query, $name, $resolved_locale);
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::normalizeType($value),
        );
    }

    protected function casts(): array
    {
        return [
            'order_column' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function dynamicSlugFields(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function slugPlaceholders(): array
    {
        return [...array_map(fn (string $field): string => '{' . $field . '}', $this->dynamicSlugFields()), '{name}'];
    }

    /**
     * A rule that fails on `$field` when another tag already has, in the translation item's locale, the item's
     * slug, given or generated from its name. The name is checked only when the item carries no slug, so one
     * collision is reported once. A plain rule object, because the CRUD request normalizes rules as arrays.
     */
    private static function freeSlugRule(string $field, ?int $except_tag_id = null): ValidationRule
    {
        return new class($field, $except_tag_id) implements DataAwareRule, ValidationRule
        {
            /**
             * @var array<string, mixed>
             */
            private array $data = [];

            public function __construct(private readonly string $field, private readonly ?int $except_tag_id) {}

            /**
             * @param  array<string, mixed>  $data
             */
            public function setData(array $data): static
            {
                $this->data = $data;

                return $this;
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $item_path = Str::beforeLast($attribute, '.');
                $locale = data_get($this->data, $item_path . '.locale');
                $given_slug = data_get($this->data, $item_path . '.slug');

                if (! is_string($locale) || ! is_string($value) || $value === '') {
                    return;
                }

                if ($this->field === 'name' && is_string($given_slug) && $given_slug !== '') {
                    return;
                }

                $slug = $this->field === 'slug' ? $value : TagTranslation::slugFromName($value);

                if (Tag::slugTakenInLocale($slug, $locale, $this->except_tag_id)) {
                    $fail(__('validation.unique', ['attribute' => $this->field]));
                }
            }
        };
    }

    /**
     * @param  array<int|string, string|Tag>|ArrayAccess<int|string, string|Tag>|string|Tag  $values
     * @return list<string|Tag>
     */
    private static function normalizeFindOrCreateInput(array|ArrayAccess|string|self $values): array
    {
        if ($values instanceof self || is_string($values)) {
            return [$values];
        }

        if (is_array($values)) {
            return array_values($values);
        }

        $normalized = [];
        $index = 0;

        while ($values->offsetExists($index)) {
            $value = $values->offsetGet($index);

            if (is_string($value) || $value instanceof self) {
                $normalized[] = $value;
            }

            $index++;
        }

        return $normalized;
    }

    /**
     * @param  Builder<static>  $query
     */
    private function applyContainingByTranslatedName(Builder $query, string $name, string $locale): void
    {
        $needle = '%' . mb_strtolower($name) . '%';

        $query->whereHas('translations', function (Builder $translation_query) use ($needle, $locale): void {
            /** @var Builder<TagTranslation> $translation_query */
            $translation_query->where('locale', $locale)
                ->whereRaw('lower(name) like ?', [$needle]);
        });
    }
}
