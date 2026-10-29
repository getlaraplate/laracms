<?php

declare(strict_types=1);

namespace Modules\CMS\Tests\Stubs\ContentExtension;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersions;

/**
 * The versioned parent of a test extender, as `Core\Overrides\Model` is the parent of a real one: the extender's
 * `save()` then wraps the history's save wrapper instead of colliding with it.
 */
abstract class VersionedExtenderBase extends Model
{
    use HasVersions;
    use SoftDeletes {
        HasVersions::forceDelete insteadof SoftDeletes;
        SoftDeletes::forceDelete as protected forceDeleteWithTheBin;
    }

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

    /**
     * Force-deletes through the bin inside the revision scope opened by {@see HasVersions::forceDelete()}.
     */
    protected function forceDeleteRecord(): ?bool
    {
        return $this->forceDeleteWithTheBin();
    }
}
