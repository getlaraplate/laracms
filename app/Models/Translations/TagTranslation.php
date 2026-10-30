<?php

declare(strict_types=1);

namespace Modules\CMS\Models\Translations;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * The slug a tag named `$name` gets: the one `generateSlug()` gives a translation with that name, so a lookup
     * by name meets the slug the translation was saved with ("Web 2.0" is `web-2-0`).
     */
    public static function slugFromName(string $name): string
    {
        $name = mb_trim($name);

        // generateSlug() keeps the placeholder of an empty value, which would turn no name into the slug "name".
        return $name === '' ? '' : (new self(['name' => $name]))->generateSlug();
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
