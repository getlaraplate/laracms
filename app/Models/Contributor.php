<?php

declare(strict_types=1);

namespace Modules\CMS\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Contracts\Taggable;
use Modules\CMS\Database\Factories\ContributorFactory;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Helpers\HasMultimedia;
use Modules\CMS\Helpers\HasTags;
use Modules\CMS\Models\Pivot\Contributable;
use Modules\Core\Contracts\IDynamicEntityTypable;
use Modules\Core\Contracts\IHidesAttributesFromAnonymous;
use Modules\Core\Contracts\IPreloadsAppendedRelations;
use Modules\Core\Models\Concerns\HasPath;
use Modules\Core\Models\Concerns\HasTranslatedDynamicContents;
use Modules\Core\Models\Concerns\HidesAttributesFromAnonymous;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Override;
use Spatie\MediaLibrary\HasMedia as IMediable;

/**
 * @phpstan-use HasMultimedia<Contributor>
 * @phpstan-use HasTranslatedDynamicContents<Contributor>
 *
 * @property int $id
 * @property int|null $user_id The user that the contributor belongs to
 * @property int $entity_id
 * @property int $presettable_id
 * @property string $name The name of the contributor
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed>|null $shared_components The shared dynamic components of the contributor
 * @property string|null $slug
 * @property array<array-key, mixed>|null $components
 */
final class Contributor extends Model implements IHidesAttributesFromAnonymous, IMediable, IPreloadsAppendedRelations, Taggable
{
    // region Traits
    use HasMultimedia;
    use HasPath;
    use HasTags;
    use HasTranslatedDynamicContents {
        HasTranslatedDynamicContents::getRules as private getRulesTranslatedDynamicContents;
        HasTranslatedDynamicContents::casts as private translatedDynamicContentsCasts;
    }
    use HidesAttributesFromAnonymous;
    // endregion

    /**
     * @var string
     */
    #[Override]
    protected $table = CMSTables::Contributors->value;

    #[Override]
    protected $fillable = [
        'name',
    ];

    #[Override]
    protected $hidden = [
        'user_id',
        'user',
    ];

    /**
     * Bridged user always comes from User on serialize / search / path, so it is
     * declared here rather than through a global scope: a closure scope is keyed by
     * spl_object_hash and cannot be lifted per query, which silently defeats
     * `->without('user')`. An always-loaded relation owned by a trait is declared the
     * same way, from the trait initializer (see HasPlace::initializeHasPlace).
     */
    #[Override]
    protected $with = ['user'];

    private ?User $tempUser = null;

    public static function getEntityModelClass(): string
    {
        return Entity::class;
    }

    /**
     * The bridged user and the contact data of the contributor are never returned to the anonymous user. The
     * contributor presets keep `public_email`, `phone` and the social profile addresses in `shared_components`,
     * so the whole column is declared, not only the keys known today.
     *
     * @return list<string>
     */
    #[Override]
    public function anonymousHiddenAttributes(): array
    {
        return ['user_id', 'user', 'shared_components'];
    }

    /**
     * Hold a not-yet-persisted user to attach on first save (e.g. create user and contributor in one flow).
     */
    public function setTempUser(?User $user): void
    {
        $this->tempUser = $user;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The contents that belong to the contributor.
     *
     * @return BelongsToMany<Content, $this, Contributable, 'pivot'>
     */
    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, CMSTables::Contributables->value)->using(Contributable::class)->withTimestamps();
    }

    #[Override]
    public function save(array $options = []): bool
    {
        if ($this->tempUser instanceof User && $this->tempUser->isDirty()) {
            $this->tempUser->save();
            $this->user_id = $this->tempUser->id;
            $this->tempUser = null;
            $this->load('user');
        } elseif ($this->user && $this->user->isDirty()) {
            $this->user->save();
        }

        return parent::save($options);
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
            'name' => ['required', 'string', 'max:255', 'unique:' . CMSTables::Contributors->value . ',name'],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255', 'unique:' . CMSTables::Contributors->value . ',name,' . $this->id],
        ]);

        return $rules;
    }

    public function getPathPrefix(): string
    {
        $entity = $this->entity;

        return $entity !== null ? $entity->slug : '';
    }

    #[Override]
    public function getPath(): null
    {
        return null;
    }

    protected static function getEntityType(): IDynamicEntityTypable
    {
        return EntityType::Contributors;
    }

    protected static function newFactory(): ContributorFactory
    {
        return ContributorFactory::new();
    }

    protected function casts(): array
    {
        return array_merge($this->translatedDynamicContentsCasts(), [
            'user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ]);
    }

    /**
     * @return list<string>
     */
    protected function slugPlaceholders(): array
    {
        return array_values([...$this->dynamicSlugFields(), 'name']);
    }

    protected function getCanLoginAttribute(): bool
    {
        return $this->user !== null || $this->tempUser instanceof User;
    }

    protected function getIsSignatureAttribute(): bool
    {
        return ! $this->getCanLoginAttribute();
    }

    protected function isUserAttribute(): bool
    {
        return $this->getCanLoginAttribute();
    }
}
