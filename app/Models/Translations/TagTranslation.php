<?php

declare(strict_types=1);

namespace Modules\CMS\Models\Translations;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Tag;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Models\Concerns\HasSlug;
use Modules\Core\Models\Concerns\RecordsChangesOnOwner;
use Modules\Core\Models\Concerns\TouchesOwnerOnChange;
use Modules\Core\Overrides\Model;
use Modules\Core\Services\Translation\Definitions\ITranslated;
use Override;

/**
 * @property int $id
 * @property int $tag_id
 * @property string $locale
 * @property string $name
 * @property string $slug
 */
final class TagTranslation extends Model implements IsPartOfParent, ITranslated
{
    use HasSlug;
    use RecordsChangesOnOwner;
    use TouchesOwnerOnChange;

    /**
     * @var string
     */
    #[Override]
    protected $table = CMSTables::TagsTranslations->value;

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    protected $fillable = [
        'tag_id',
        'locale',
        'name',
        'slug',
    ];

    /**
     * The slug a tag named `$name` gets: the slugger `generateSlug()` uses, so a lookup by name meets the slug
     * a translation was saved with.
     */
    public static function slugFromName(string $name): string
    {
        $slugger = config('cms.slugger', Str::class . '::slug');

        return (string) call_user_func($slugger, mb_trim($name));
    }

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'tag';
    }

    /**
     * The tag that belongs to the translation.
     *
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    protected function casts(): array
    {
        return [];
    }

    /**
     * The record this translation belongs to: its `updated_at` moves when the translation changes (D30).
     *
     * @return array{0: \Illuminate\Database\Eloquent\Model, 1: int|string}|null
     */
    protected function ownerToTouch(): ?array
    {
        return $this->ownerThrough($this->tag());
    }
}
