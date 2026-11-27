<?php

declare(strict_types=1);

namespace Modules\CMS\Models\Pivot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Tag;
use Modules\Core\Models\Concerns\TouchesOwnerOnChange;
use Override;

/**
 * The assignment of a tag to any taggable model (content, …). Modelled
 * explicitly so the moment a tag was attached (or last touched) is recorded and
 * observable, rather than living in an anonymous polymorphic pivot row.
 *
 * @property int $tag_id
 * @property string $taggable_type
 * @property int $taggable_id
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class Taggable extends MorphPivot
{
    use TouchesOwnerOnChange;

    #[Override]
    public $incrementing = false;

    #[Override]
    public $timestamps = true;

    /**
     * @var string
     */
    #[Override]
    protected $table = CMSTables::Taggables->value;

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'tag_id' => 'integer',
            'taggable_id' => 'integer',
        ];
    }

    /**
     * The tagged record this link belongs to, resolved through the morph map: its `updated_at` moves when the
     * link changes (D37).
     *
     * @return array{0: Model, 1: int|string}|null
     */
    protected function ownerToTouch(): ?array
    {
        $type = $this->getAttribute('taggable_type');
        $key = $this->getAttribute('taggable_id');

        if (! is_string($type) || ! (is_int($key) || is_string($key))) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? [new $class, $key] : null;
    }
}
