<?php

declare(strict_types=1);

namespace Modules\CMS\Filament\Resources\Categories\Pages;

use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\CMS\Filament\Resources\Categories\CategoryResource;
use Modules\CMS\Filament\Utils\HasRecords;
use Modules\CMS\Models\Category;
use Override;

final class ListCategories extends ListRecords
{
    use HasRecords;

    #[Override]
    protected static string $resource = CategoryResource::class;

    /**
     * @return Builder<Category>
     */
    protected function getTableQuery(): Builder
    {
        /** @var class-string<Category> $model */
        $model = self::getResource()::getModel();

        return $model::query()->with('ancestors');
    }
}
