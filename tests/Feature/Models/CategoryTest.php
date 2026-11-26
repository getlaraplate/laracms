<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;

uses(TestCase::class, RefreshDatabase::class)->group('db2');

beforeEach(function (): void {
    $category = new Category;

    if (
        ! method_exists(Category::class, 'determineOrderColumnName')
        || ! $category->getConnection()->getSchemaBuilder()->hasColumn($category->getTable(), 'shared_components')
    ) {
        $this->markTestSkipped('Category integration features require full Core runtime.');
    }

    setupCMSEntities();
    $this->category = Category::factory()->create();
});

it('can be created with factory', function (): void {
    expect($this->category)->toBeInstanceOf(Category::class);
    expect($this->category->id)->not->toBeNull();
});

it('has translatable attributes', function (): void {
    $category = Category::factory()->create();

    // Set translation for default locale
    $default_locale = config('app.locale');
    $category->setTranslation($default_locale, [
        'name' => 'Technology',
        'slug' => 'technology',
        'components' => [
            'description' => 'Technology category',
        ],
    ]);
    $category->save();

    expect($category->name)->toBe('Technology');
    expect($category->slug)->toBe('technology');
    expect($category->getTranslation($default_locale)->components['description'])->toBe('Technology category');
});

it('belongs to many contents', function (): void {
    $content1 = Content::factory()->create();
    $content1->setTranslation(config('app.locale'), ['title' => 'Article 1']);
    $content1->save();

    $content2 = Content::factory()->create();
    $content2->setTranslation(config('app.locale'), ['title' => 'Article 2']);
    $content2->save();

    $this->category->contents()->attach([$content1->id, $content2->id]);

    expect($this->category->contents)->toHaveCount(2);
    expect($this->category->contents->pluck('title')->toArray())->toContain('Article 1', 'Article 2');
});

it('has recursive relationships for parent-child categories', function (): void {
    $parentCategory = Category::factory()->create();
    $parentCategory->setTranslation(config('app.locale'), ['name' => 'Technology']);
    $parentCategory->save();

    $childCategory = Category::factory()->create();
    $childCategory->setTranslation(config('app.locale'), ['name' => 'Programming']);
    $childCategory->parent_id = $parentCategory->id;
    $childCategory->save();

    expect($childCategory->parent)->toBeInstanceOf(Category::class);
    expect($childCategory->parent->id)->toBe($parentCategory->id);
    expect($parentCategory->children)->toHaveCount(1);
    expect($parentCategory->children->first()->id)->toBe($childCategory->id);
});

it('has slug trait', function (): void {
    expect($this->category->isTranslatableField('slug'))->toBeTrue();
    expect(Category::getTranslatableFields())->toContain('slug');
});

it('has tags trait', function (): void {
    expect(method_exists($this->category, 'attachTags'))->toBeTrue();
    expect(method_exists($this->category, 'detachTags'))->toBeTrue();
});

it('has multimedia trait', function (): void {
    expect(method_exists($this->category, 'addMedia'))->toBeTrue();
    expect(method_exists($this->category, 'getMedia'))->toBeTrue();
});

it('has dynamic contents trait', function (): void {
    expect(class_uses_recursive($this->category))->toContain(Modules\Core\Models\Concerns\HasDynamicContents::class);
    expect(method_exists($this->category, 'getDynamicFields'))->toBeTrue();
});

it('has approvals trait', function (): void {
    expect(class_uses_recursive($this->category))->toContain(Modules\Core\Models\Concerns\HasApprovals::class);
});

it('has validity trait', function (): void {
    expect(method_exists($this->category, 'isValid'))->toBeTrue();
    expect(method_exists($this->category, 'isExpired'))->toBeTrue();
});

it('has versions trait', function (): void {
    expect(method_exists($this->category, 'versions'))->toBeTrue();
    expect(method_exists($this->category, 'createVersion'))->toBeTrue();
});

it('has soft deletes trait', function (): void {
    $this->category->delete();

    expect($this->category->trashed())->toBeTrue();
    expect(Category::withTrashed()->find($this->category->id))->not->toBeNull();
});

it('has sortable trait', function (): void {
    expect(class_uses_recursive($this->category))->toContain(Modules\Core\Models\Concerns\SortableTrait::class);
    expect(method_exists($this->category, 'buildSortQuery'))->toBeTrue();
});

it('has locks trait', function (): void {
    expect(method_exists($this->category, 'lock'))->toBeTrue();
    expect(method_exists($this->category, 'unlock'))->toBeTrue();
});

it('has validations trait', function (): void {
    expect(method_exists($this->category, 'getRules'))->toBeTrue();
});

it('can be created with specific translation attributes', function (): void {
    $category = Category::factory()->create();
    $default_locale = config('app.locale');
    $category->setTranslation($default_locale, [
        'name' => 'Science',
        'slug' => 'science',
        'components' => [
            'description' => 'Science category',
        ],
    ]);
    $category->save();

    expect($category->name)->toBe('Science');
    expect($category->slug)->toBe('science');
    expect($category->getTranslation($default_locale)->components['description'])->toBe('Science category');
});

it('can be found by name through translation', function (): void {
    $category = Category::factory()->create();
    $default_locale = config('app.locale');
    $category->setTranslation($default_locale, [
        'name' => 'Unique Category',
        'slug' => 'unique-category',
    ]);
    $category->save();

    $foundCategory = Category::query()->whereHas('translations', static function ($q): void {
        $q->where('name', 'Unique Category');
    })->first();

    expect($foundCategory->id)->toBe($category->id);
    expect($foundCategory->name)->toBe('Unique Category');
});

it('can be found by slug through translation', function (): void {
    $category = Category::factory()->create();
    $default_locale = config('app.locale');
    $category->setTranslation($default_locale, [
        'name' => 'Test Category',
        'slug' => 'unique-slug',
    ]);
    $category->save();

    $foundCategory = Category::query()->whereHas('translations', static function ($q): void {
        $q->where('slug', 'unique-slug');
    })->first();

    expect($foundCategory->id)->toBe($category->id);
    expect($foundCategory->slug)->toBe('unique-slug');
});

it('can be found by active status', function (): void {
    $activeCategory = Category::factory()->create(['is_active' => true]);
    $inactiveCategory = Category::factory()->create(['is_active' => false]);

    $ids = [$activeCategory->id, $inactiveCategory->id];

    // The `global_filters` global scope restricts default queries to active records.
    $activeCategories = Category::query()->whereIn('id', $ids)->get();
    $inactiveCategories = Category::query()
        ->withoutGlobalScope('global_filters')
        ->whereIn('id', $ids)
        ->where('is_active', false)
        ->get();

    expect($activeCategories)->toHaveCount(1);
    expect($inactiveCategories)->toHaveCount(1);
    expect($activeCategories->first()->id)->toBe($activeCategory->id);
    expect($inactiveCategories->first()->id)->toBe($inactiveCategory->id);
});

it('has proper timestamps', function (): void {
    $category = Category::factory()->create();

    expect($category->created_at)->toBeInstanceOf(Carbon\CarbonImmutable::class);
    expect($category->updated_at)->toBeInstanceOf(Carbon\CarbonImmutable::class);
});

it('can be serialized to array with translations', function (): void {
    $category = Category::factory()->create();
    $default_locale = config('app.locale');
    $category->setTranslation($default_locale, [
        'name' => 'Test Category',
        'slug' => 'test-category',
    ]);
    $category->save();

    $categoryArray = $category->toArray();

    expect($categoryArray)->toHaveKey('id');
    expect($categoryArray)->toHaveKey('name');
    expect($categoryArray)->toHaveKey('slug');
    expect($categoryArray)->toHaveKey('created_at');
    expect($categoryArray)->toHaveKey('updated_at');
    expect($categoryArray['name'])->toBe('Test Category');
    expect($categoryArray['slug'])->toBe('test-category');
});

it('can be restored after soft delete', function (): void {
    $category = Category::factory()->create();
    $category->delete();

    expect($category->trashed())->toBeTrue();

    $category->restore();

    expect($category->trashed())->toBeFalse();
});

it('can be permanently deleted', function (): void {
    $category = Category::factory()->create();
    $categoryId = $category->id;

    $category->forceDelete();

    expect(Category::withTrashed()->find($categoryId))->toBeNull();
});

it('has translations trait', function (): void {
    expect(method_exists($this->category, 'translations'))->toBeTrue();
    expect(method_exists($this->category, 'translation'))->toBeTrue();
    expect(method_exists($this->category, 'getTranslation'))->toBeTrue();
    expect(method_exists($this->category, 'setTranslation'))->toBeTrue();
    expect(method_exists($this->category, 'hasTranslation'))->toBeTrue();
    expect(method_exists($this->category, 'getTranslatableFields'))->toBeTrue();
});

it('loads the ancestors in depth order from the database whatever the sibling positions', function (int $root_position, int $child_position, int $grandchild_position): void {
    $locale = config('app.locale');
    $chain = [];

    foreach (['root' => $root_position, 'child' => $child_position, 'grandchild' => $grandchild_position] as $name => $position) {
        $category = Category::factory()->create();
        $category->setTranslation($locale, ['name' => $name, 'slug' => 'chain-' . $name]);
        $category->parent_id = $chain === [] ? null : end($chain)->id;
        $category->order_column = $position;
        $category->save();
        $chain[] = $category;
    }

    [$root, $child, $grandchild] = $chain;

    $loaded = Category::query()->with('ancestors')->findOrFail($grandchild->id);

    expect($loaded->ancestors->modelKeys())->toBe([$child->id, $root->id])
        ->and($loaded->ancestors->pluck('order_column')->all())->toBe([$child_position, $root_position]);
})->with([
    // Sibling positions that do not follow depth. On the first, the scope order sorts the ancestors root first.
    'root 1, child 3, grandchild 2' => [1, 3, 2],
    'root 3, child 1, grandchild 2' => [3, 1, 2],
]);

it('quotes the reserved locale column in the translation order of a taxonomy on Db2', function (): void {
    // Warm the fallback setting on SQLite: the pretend connection answers no query.
    expect($this->category->translationFallbackEnabledBySettings())->toBeTrue();
    $sql = '';

    Db2PretendConnection::capture(static function () use (&$sql): void {
        LocaleContext::set('it');
        $sql = (new Category)->setConnection('db2_pretend')->translation()->toSql();
    });

    expect($sql)->toContain('order by CASE WHEN "LOCALE" = ? THEN 0 ELSE 1 END');
});
