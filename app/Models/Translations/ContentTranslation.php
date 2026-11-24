<?php

declare(strict_types=1);

namespace Modules\CMS\Models\Translations;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CMS\Enums\AiAssistance;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Content;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Models\Concerns\HasSlug;
use Modules\Core\Models\Concerns\RecordsChangesOnOwner;
use Modules\Core\Models\Concerns\TouchesOwnerOnChange;
use Modules\Core\Overrides\Model;
use Modules\Core\Services\Translation\Definitions\ITranslated;
use Override;

/**
 * @property int $id
 * @property int $content_id
 * @property string $locale The locale of the translation
 * @property string $title The translated title of the content
 * @property string|null $slug The translated slug of the content
 * @property AiAssistance $ai_assistance How AI assisted this locale-specific version
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed> $components The translated content components
 */
final class ContentTranslation extends Model implements IsPartOfParent, ITranslated
{
    use HasSlug;
    use RecordsChangesOnOwner;
    use TouchesOwnerOnChange;

    /**
     * @var string
     */
    #[Override]
    protected $table = CMSTables::ContentsTranslations->value;

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    protected $fillable = [
        'content_id',
        'locale',
        'title',
        'slug',
        'components',
        'ai_assistance',
    ];

    #[Override]
    protected $attributes = [
        'components' => '[]',
        'ai_assistance' => 'none',
    ];

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'content';
    }

    /**
     * The content that belongs to the translation.
     *
     * @return BelongsTo<Content, $this>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    protected function casts(): array
    {
        return [
            'components' => 'json',
            'ai_assistance' => AiAssistance::class,
        ];
    }

    /**
     * The record this translation belongs to: its `updated_at` moves when the translation changes (D30).
     *
     * @return array{0: \Illuminate\Database\Eloquent\Model, 1: int|string}|null
     */
    protected function ownerToTouch(): ?array
    {
        return $this->ownerThrough($this->content());
    }
}
