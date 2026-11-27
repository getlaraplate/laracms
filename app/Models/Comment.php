<?php

declare(strict_types=1);

namespace Modules\CMS\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\CMS\Database\Factories\CommentFactory;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Translations\CommentTranslation;
use Modules\CMS\Scopes\CommentTranslationScope;
use Modules\CMS\Services\CommentApprovalCapture;
use Modules\CMS\Services\ContentRatingService;
use Modules\Core\Approvals\Operation;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Contracts\IHidesAttributesFromAnonymous;
use Modules\Core\Contracts\INeverVersionedModel;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Concerns\HasClosureTable;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\Concerns\HidesAttributesFromAnonymous;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Modules\Core\Support\PermissionName;
use Override;

/**
 * @property int $id
 * @property int $content_id
 * @property int $user_id
 * @property int|null $parent_id
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property string|null $body
 */
final class Comment extends Model implements IHidesAttributesFromAnonymous, INeverVersionedModel, ITranslatableModel
{
    use HasApprovals, HasClosureTable, HasTranslations, HidesAttributesFromAnonymous {
        HasApprovals::toArray as private approvalsToArray;
        HasTranslations::toArray as private translationsToArray;
    }

    public ?int $pending_rating_score = null;

    /**
     * @var string
     */
    #[Override]
    protected $table = CMSTables::Comments->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'content_id',
        'user_id',
        'parent_id',
        'body',
        'rating_score',
    ];

    /**
     * The captured request is kept on the instance, so callers such as the CRUD API report the
     * comment as sent for moderation rather than as created.
     */
    public static function captureSave(self $item): bool
    {
        $item->defaultAuthor();
        $modification = CommentApprovalCapture::capture($item);

        if ($modification === null) {
            return true;
        }

        $item->pendingModification = $modification;

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $contents = 'exists:' . CMSTables::Contents->value . ',id';
        $comments = 'exists:' . CMSTables::Comments->value . ',id';
        $users = 'exists:' . CoreTables::Users->value . ',id';

        $rules['create'] = array_merge($rules['create'], [
            'content_id' => ['required', 'integer', $contents],
            'user_id' => ['nullable', 'integer', $users],
            'parent_id' => ['nullable', 'integer', $comments],
            'body' => ['required', 'string', 'max:10000'],
            'rating_score' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'content_id' => ['sometimes', 'integer', $contents],
            'parent_id' => ['sometimes', 'nullable', 'integer', $comments],
            'body' => ['sometimes', 'string', 'max:10000'],
            'rating_score' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
        ]);

        return $rules;
    }

    /**
     * The text and the rating are not columns: the text is a translation and the rating is kept
     * until approval. Both are validated as they will be written.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getAttributesForValidation(): array
    {
        $attributes = parent::getAttributesForValidation();
        $locale = LocaleContext::get();

        if (isset($this->pending_translations[$locale]['body'])) {
            $attributes['body'] = $this->pending_translations[$locale]['body'];
        } elseif (! $this->exists) {
            $attributes['body'] = null;
        }

        if ($this->pending_rating_score !== null) {
            $attributes['rating_score'] = $this->pending_rating_score;
        }

        return $attributes;
    }

    /**
     * The author is never returned to the anonymous user.
     *
     * @return list<string>
     */
    #[Override]
    public function anonymousHiddenAttributes(): array
    {
        return ['user_id'];
    }

    /**
     * Set the author to the authenticated user when none was given.
     */
    public function defaultAuthor(): void
    {
        $user_id = auth()->id();

        // Read through getAttribute(): before the first save the column holds no value yet.
        if ($this->getAttribute('user_id') === null && $user_id !== null) {
            $this->user_id = (int) $user_id;
        }
    }

    /**
     * A comment author deletes their own comment; only its text goes through moderation.
     *
     * @return list<Operation>
     */
    public function approvalOperations(): array
    {
        return [Operation::Create, Operation::Update];
    }

    /**
     * @return BelongsTo<Content, $this>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<ContentRating, $this>
     */
    public function rating(): HasOne
    {
        return $this->hasOne(ContentRating::class, 'comment_id');
    }

    /**
     * @return HasOne<CommentTranslation, $this>
     */
    public function translation(): HasOne
    {
        $current_locale = LocaleContext::get();
        $fallback_enabled = $this->translationFallbackEnabledBySettings();

        $relation = $this->hasOne(CommentTranslation::class);

        if ($fallback_enabled) {
            $relation
                ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$current_locale])
                ->orderBy('created_at')
                ->orderBy('id');
        } else {
            $relation->where(
                (new CommentTranslation())->qualifyColumn('locale'),
                $current_locale,
            );
        }

        return $relation;
    }

    public function getTranslation(?string $locale = null, ?bool $with_fallback = null): ?CommentTranslation
    {
        $locale ??= LocaleContext::get();

        $translation = $this->translations()
            ->where((new CommentTranslation())->qualifyColumn('locale'), $locale)
            ->first();

        if ($translation instanceof CommentTranslation) {
            return $translation;
        }

        if ($with_fallback === false) {
            return null;
        }

        $fallback = $this->translations()
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $fallback instanceof CommentTranslation ? $fallback : null;
    }

    public function getOriginalTranslation(): ?CommentTranslation
    {
        $translation = $this->translations()
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $translation instanceof CommentTranslation ? $translation : null;
    }

    /**
     * @param  array<string, mixed>|null  $parsed
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(?array $parsed = null): array
    {
        if ($this->getPreviewAttribute()) {
            return $this->approvalsToArray($parsed);
        }

        return $this->translationsToArray($parsed);
    }

    public function applyModificationChanges(\Modules\Core\Models\Modification $modification, bool $approved): void
    {
        if (! $approved || ! $this->updateWhenApproved) {
            if ($approved === false) {
                $modification->active = false;
                $modification->save();
            }

            return;
        }

        $this->setForcedApprovalUpdate(true);

        /** @var array<string, array{original: mixed, modified: mixed}> $changes */
        $changes = $modification->modifications ?? [];

        foreach ($changes as $key => $change) {
            if ($key === 'locale') {
                continue;
            }

            if (in_array($key, ['body', 'rating_score'], true)) {
                if ($key === 'body') {
                    $locale_value = $changes['locale']['modified'] ?? LocaleContext::get();
                    $locale = is_string($locale_value) ? $locale_value : LocaleContext::get();
                    $modified_body = $change['modified'] ?? null;
                    $this->inLocale($locale)->body = is_string($modified_body) ? $modified_body : null;
                }

                continue;
            }

            $this->{$key} = $change['modified'];
        }

        $this->save();

        $modified_rating = $changes['rating_score']['modified'] ?? null;
        $rating_score = is_int($modified_rating)
            ? $modified_rating
            : (is_numeric($modified_rating) ? (int) $modified_rating : null);

        resolve(ContentRatingService::class)->syncFromApprovedComment($this, $rating_score);

        $modification->active = false;
        $modification->save();
    }

    public function setRatingScoreAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->pending_rating_score = null;

            return;
        }

        if (is_int($value)) {
            $this->pending_rating_score = $value;

            return;
        }

        if (is_numeric($value)) {
            $this->pending_rating_score = (int) $value;

            return;
        }

        $this->pending_rating_score = null;
    }

    public function hasPendingBodyForCurrentLocale(): bool
    {
        $locale = LocaleContext::get();

        return isset($this->pending_translations[$locale]['body'])
            && $this->pending_translations[$locale]['body'] !== '';
    }

    protected static function bootHasTranslations(): void
    {
        self::addGlobalScope(new CommentTranslationScope());

        self::saved(function (self $comment): void {
            $comment->savePendingTranslations();
        });
    }

    /**
     * The comment's author is whoever posts it, unless the caller names one explicitly.
     */
    protected static function booted(): void
    {
        self::creating(static function (self $comment): void {
            $comment->defaultAuthor();
        });
    }

    protected static function newFactory(): CommentFactory
    {
        return CommentFactory::new();
    }

    protected function getTranslatableFieldValue(string $key): mixed
    {
        $locale = LocaleContext::get();

        if (isset($this->pending_translations[$locale][$key])) {
            return $this->pending_translations[$locale][$key];
        }

        return $this->getTranslation($locale)?->{$key};
    }

    /**
     * @param  array<string, mixed>  $modifications
     */
    protected function requiresApprovalWhen(array $modifications): bool
    {
        if ($this->hasPendingBodyForCurrentLocale()) {
            $locale = LocaleContext::get();
            $modifications['body'] = $this->pending_translations[$locale]['body'];
        }

        if (! array_key_exists('body', $modifications)) {
            return false;
        }

        $user = $this->modifier();

        // `approve.{table}` predates the `{connection}.{table}.{operation}` convention
        // and matched no registered permission.
        return ! ($user && ($user->isAdmin() || $user->isSuperAdmin() && $user->can(PermissionName::forModel($this, ActionEnum::Approve->value))));
    }

    protected function modifier(): ?User
    {
        return auth()->user();
    }

    protected function casts(): array
    {
        return [
            'content_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Fold the pending translated body and the pending rating into the change set the
     * capture decides on: both are staged off the attribute bag, so getDirty() misses them.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForApproval(): array
    {
        $dirty = $this->getDirty();

        if ($this->hasPendingBodyForCurrentLocale()) {
            $locale = LocaleContext::get();
            $dirty['body'] = $this->pending_translations[$locale]['body'];
        }

        if ($this->pending_rating_score !== null) {
            $dirty['rating_score'] = $this->pending_rating_score;
        }

        return $dirty;
    }
}
