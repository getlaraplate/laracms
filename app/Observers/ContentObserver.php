<?php

declare(strict_types=1);

namespace Modules\CMS\Observers;

use Modules\CMS\Models\Content;

final class ContentObserver
{
    /**
     * Handle the Content "creating" event.
     */
    public function creating(Content $model): void
    {
        // Auto-assign entity and preset if not already set
        // Read through getAttribute(): on creating the columns may hold no value yet.
        if ($model->getAttribute('entity_id') === null || $model->getAttribute('presettable_id') === null) {
            $model->setDefaultEntityAndPreset();
        }
    }
}
