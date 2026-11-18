<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Category;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Models\Role;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DTOs\FacetPage;
use Modules\Core\Services\Crud\DTOs\FacetQuery;
use Modules\Core\Services\Crud\DTOs\FacetSort;

uses(TestCase::class, RefreshDatabase::class);

function category_facet_list_data(Model $model, Request $request): Modules\Core\Casts\ListRequestData
{
    $data = new ReflectionClass(Modules\Core\Casts\ListRequestData::class)->newInstanceWithoutConstructor();

    $set = static function (object $obj, string $prop, mixed $value): void {
        new ReflectionProperty($obj, $prop)->setValue($obj, $value);
    };

    foreach ([
        'request' => $request, 'mainEntity' => $model->getTable(), 'primaryKey' => $model->getKeyName(),
        'connection' => $model->getConnectionName(), 'model' => $model, 'columns' => [], 'relations' => [],
        'sort' => [], 'filters' => null, 'group_by' => [], 'pagination' => 25, 'page' => null,
        'skip' => null, 'take' => null, 'from' => null, 'to' => null, 'limit' => null, 'count' => false,
    ] as $prop => $value) {
        $set($data, $prop, $value);
    }

    return $data;
}

function category_facet(FacetQuery $facet): FacetPage
{
    $role = Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole($role);
    auth()->login($admin);

    $request = new class extends Request
    {
        public function validated(?string $key = null, mixed $default = null): mixed
        {
            return $key === null ? [] : $default;
        }
    };
    $request->setUserResolver(fn (): User => $admin);

    return app(CrudService::class)->facetValues(category_facet_list_data(new Category, $request), $facet);
}

function root_category(string $name): Category
{
    $category = Category::factory()->create();
    $category->translations()->where('locale', 'en')->update(['name' => $name]);
    $category->parent_id = null;
    $category->save();

    return $category->refresh();
}

function child_category(Category $parent): Category
{
    $category = Category::factory()->create();
    $category->parent_id = $parent->id;
    $category->save();

    return $category->refresh();
}

function category_facet_by_label(FacetPage $page): Illuminate\Support\Collection
{
    return collect($page->values)->keyBy(fn (array $value): mixed => $value['attributes']['parent.name'] ?? null);
}

it('facets categories by parent with the parent translated name', function (): void {
    app()->setLocale('en');
    setupCMSEntities([EntityType::Categories]);

    $cinema = root_category('Cinema');
    $sport = root_category('Sport');
    child_category($cinema);
    child_category($cinema);
    child_category($sport);

    $page = category_facet(new FacetQuery(
        groupBy: 'parent_id',
        fields: ['parent.name'],
        labelField: 'parent.name',
        sort: FacetSort::LabelAsc,
    ));

    $byLabel = category_facet_by_label($page);

    expect($byLabel['Cinema']['count'])->toBe(2)
        ->and($byLabel['Cinema']['key'])->toBe($cinema->id)
        ->and($byLabel['Sport']['count'])->toBe(1);

    // Label sort orders Cinema before Sport (nulls sort to an end).
    $labels = collect($page->values)
        ->map(fn (array $value): mixed => $value['attributes']['parent.name'])
        ->filter(fn (mixed $name): bool => in_array($name, ['Cinema', 'Sport'], true))
        ->values()
        ->all();
    expect($labels)->toBe(['Cinema', 'Sport']);
});

it('searches the category parent facet by the translated parent name', function (): void {
    app()->setLocale('en');
    setupCMSEntities([EntityType::Categories]);

    $cinema = root_category('Cinema');
    $sport = root_category('Sport');
    child_category($cinema);
    child_category($sport);

    $page = category_facet(new FacetQuery(
        groupBy: 'parent_id',
        fields: ['parent.name'],
        labelField: 'parent.name',
        search: 'Cine',
    ));

    expect($page->distinctValues)->toBe(1)
        ->and(collect($page->values)->pluck('key')->all())->toBe([$cinema->id]);
});
