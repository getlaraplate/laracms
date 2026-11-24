<?php

declare(strict_types=1);

namespace Modules\CMS\Models\Pivot;

use Illuminate\Database\Eloquent\Model;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Content;
use Modules\Core\Models\Concerns\TouchesOwnerOnChange;
use Modules\Core\Overrides\Pivot;
use Override;

/**
 * @property int $content_id The content that the categorizable belongs to
 * @property int $taxonomy_id The category that the categorizable belongs to
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class Categorizable extends Pivot
{
    use TouchesOwnerOnChange;

    #[Override]
    public $incrementing = false;

    /**
     * @var string
     */
    #[Override]
    protected $table = CMSTables::Categorizables->value;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The content this link belongs to: its `updated_at` moves when the link changes (D37).
     *
     * @return array{0: Model, 1: int|string}|null
     */
    protected function ownerToTouch(): ?array
    {
        $key = $this->getAttribute('content_id');

        return is_int($key) || is_string($key) ? [new Content, $key] : null;
    }
}
