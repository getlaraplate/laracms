<?php

declare(strict_types=1);

namespace Modules\CMS\Models\Pivot;

use Modules\CMS\Enums\CMSTables;
use Modules\Core\Overrides\Pivot;
use Override;

/**
 * @property int $content_id
 * @property int $related_content_id
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class Relatable extends Pivot
{
    /**
     * @var string
     */
    #[Override]
    protected $table = CMSTables::Relatables->value;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ];
    }
}
