<?php

declare(strict_types=1);

namespace Modules\CMS\Services;

use Modules\CMS\Models\Tag;

/**
 * One registered tag type: its normalized slug, the lang key of its label and the icon of each icon set
 * (Heroicon for the Filament backoffice, Lucide for the Vue frontend).
 */
final readonly class TagTypeDefinition
{
    public string $type;

    public function __construct(
        string $type,
        public string $labelKey,
        public string $heroicon,
        public string $lucide,
    ) {
        $this->type = (string) Tag::normalizeType($type);
    }
}
