<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Tag;
use Modules\CMS\Models\Translations\TagTranslation;
use Modules\CMS\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $tag = new Tag;
    $translation = (new TagTranslation)->setConnection($tag->getConnection()->getName());

    if (
        ! method_exists(Tag::class, 'determineOrderColumnName')
        || ! $translation->getConnection()->getSchemaBuilder()->hasTable($translation->getTable())
    ) {
        $this->markTestSkipped('Tag integration features require full Core runtime.');
    }

    setupCMSEntities();
});

it('can be created with factory', function (): void {
    $tag = Tag::factory()->create();

    expect($tag)->toBeInstanceOf(Tag::class);
    expect($tag->id)->not->toBeNull();
});

it('has translatable attributes', function (): void {
    $tag = Tag::factory()->create();

    // Set translation for default locale
    $default_locale = config('app.locale');
    $tag->setTranslation($default_locale, [
        'name' => 'Laravel',
        'slug' => 'laravel',
    ]);
    $tag->save();

    expect($tag->name)->toBe('Laravel');
    expect($tag->slug)->toBe('laravel');
});

it('stores translated fields in translations table for translatable-only models', function (): void {
    $tag = Tag::factory()->create();
    $default_locale = config('app.locale');

    $tag->name = 'Stored In Translation';
    $tag->slug = 'stored-in-translation';
    $tag->save();

    $translation = (new TagTranslation)->setConnection($tag->getConnection()->getName());
    $tag_row = (array) $tag->getConnection()->table($tag->getTable())->where('id', $tag->id)->first();
    $translation_row = (array) $translation->getConnection()->table($translation->getTable())
        ->where('tag_id', $tag->id)
        ->where('locale', $default_locale)
        ->first();

    expect($tag_row)->not->toHaveKey('name')
        ->and($tag_row)->not->toHaveKey('slug')
        ->and($translation_row['name'])->toBe('Stored In Translation')
        ->and($translation_row['slug'])->toBe('stored-in-translation');
});

it('has hidden attributes', function (): void {
    $tag = Tag::factory()->create();
    $tagArray = $tag->toArray();

    expect($tagArray)->not->toHaveKey('order_column');
    expect($tagArray)->toHaveKey('created_at');
    expect($tagArray)->toHaveKey('updated_at');
});

it('belongs to many contents', function (): void {
    $tag = Tag::factory()->create();
    $validity = [
        'valid_from' => now()->subDay(),
        'valid_to' => null,
    ];
    $content1 = Content::factory()->create($validity);
    $content2 = Content::factory()->create($validity);

    $tag->contents()->attach([$content1->id, $content2->id]);

    expect($tag->contents)->toHaveCount(2);
});

it('has slug trait', function (): void {
    expect(method_exists(TagTranslation::class, 'generateSlug'))->toBeTrue();
});

it('has path trait', function (): void {
    expect(method_exists(Tag::class, 'getPath'))->toBeTrue();
});

it('has sortable trait', function (): void {
    expect(method_exists(Tag::class, 'moveToEnd'))->toBeTrue();
});

it('has soft deletes trait', function (): void {
    $tag = Tag::factory()->create();
    $tag->delete();

    expect($tag->trashed())->toBeTrue();
    expect(Tag::withTrashed()->find($tag->id))->not->toBeNull();
});

it('has validations trait', function (): void {
    expect(method_exists(Tag::class, 'getRules'))->toBeTrue();
});

it('has translations trait', function (): void {
    $tag = Tag::factory()->create();

    expect(method_exists($tag, 'translations'))->toBeTrue();
    expect(method_exists($tag, 'translation'))->toBeTrue();
    expect(method_exists($tag, 'getTranslation'))->toBeTrue();
    expect(method_exists($tag, 'setTranslation'))->toBeTrue();
    expect(method_exists($tag, 'hasTranslation'))->toBeTrue();
    expect(method_exists($tag, 'getTranslatableFields'))->toBeTrue();
});

it('tag translation resolves inverse tag relation', function (): void {
    $tag = Tag::factory()->create();
    $locale = config('app.locale');
    $tag->setTranslation($locale, [
        'name' => 'Inverse Relation',
        'slug' => 'inverse-relation',
    ]);
    $tag->save();

    $translation = $tag->translations()->first();

    expect($translation)->toBeInstanceOf(TagTranslation::class)
        ->and($translation->tag->is($tag))->toBeTrue();
});

it('can find or create tags', function (): void {
    $tag = Tag::findOrCreateFromString('Laravel');

    expect($tag)->toBeInstanceOf(Tag::class);
    expect($tag->name)->toBe('Laravel');

    // Should return the same tag if called again
    $sameTag = Tag::findOrCreateFromString('Laravel');
    expect($sameTag->id)->toBe($tag->id);
});

it('can find or create multiple tags', function (): void {
    $tags = Tag::findOrCreate(['Laravel', 'PHP', 'Web Development']);

    expect($tags)->toHaveCount(3);
    expect($tags->pluck('name')->toArray())->toContain('Laravel', 'PHP', 'Web Development');
});

it('can be created with specific translation attributes', function (): void {
    $tag = Tag::factory()->create(['type' => 'programming']);
    $tag->updateQuietly(['order_column' => 2]);
    $default_locale = config('app.locale');
    $tag->setTranslation($default_locale, [
        'name' => 'JavaScript',
        'slug' => 'javascript',
    ]);
    $tag->save();

    expect($tag->name)->toBe('JavaScript');
    expect($tag->slug)->toBe('javascript');
    expect($tag->type)->toBe('programming');
    expect($tag->order_column)->toBe(2);
});

it('can be found by name through translation', function (): void {
    $tag = Tag::factory()->create();
    $default_locale = config('app.locale');
    $tag->setTranslation($default_locale, [
        'name' => 'Unique Tag',
        'slug' => 'unique-tag',
    ]);
    $tag->save();

    $foundTag = Tag::query()->whereHas('translations', static function ($q): void {
        $q->where('name', 'Unique Tag');
    })->first();

    expect($foundTag->id)->toBe($tag->id);
    expect($foundTag->name)->toBe('Unique Tag');
});

it('can be found by slug through translation', function (): void {
    $tag = Tag::factory()->create();
    $default_locale = config('app.locale');
    $tag->setTranslation($default_locale, [
        'name' => 'Test Tag',
        'slug' => 'unique-slug',
    ]);
    $tag->save();

    $foundTag = Tag::query()->whereHas('translations', static function ($q): void {
        $q->where('slug', 'unique-slug');
    })->first();

    expect($foundTag->id)->toBe($tag->id);
    expect($foundTag->slug)->toBe('unique-slug');
});

it('can be found by type', function (): void {
    $tag = Tag::factory()->create(['type' => 'technology']);

    $foundTag = Tag::query()->where('type', 'technology')->first();

    expect($foundTag->id)->toBe($tag->id);
});

it('has proper timestamps', function (): void {
    $tag = Tag::factory()->create();

    expect($tag->created_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($tag->updated_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('can be serialized to array with translations', function (): void {
    $tag = Tag::factory()->create(['type' => 'test']);
    $default_locale = config('app.locale');
    $tag->setTranslation($default_locale, [
        'name' => 'Test Tag',
        'slug' => 'test-tag',
    ]);
    $tag->save();

    $tagArray = $tag->toArray();

    expect($tagArray)->toHaveKey('id');
    expect($tagArray)->toHaveKey('name');
    expect($tagArray)->toHaveKey('slug');
    expect($tagArray)->toHaveKey('type');
    expect($tagArray['name'])->toBe('Test Tag');
    expect($tagArray['slug'])->toBe('test-tag');
    expect($tagArray['type'])->toBe('test');
});

it('can be restored after soft delete', function (): void {
    $tag = Tag::factory()->create();
    $tag->delete();

    expect($tag->trashed())->toBeTrue();

    $tag->restore();

    expect($tag->trashed())->toBeFalse();
});

it('can be permanently deleted', function (): void {
    $tag = Tag::factory()->create();
    $tagId = $tag->id;

    $tag->forceDelete();

    expect(Tag::withTrashed()->find($tagId))->toBeNull();
});

it('can be sorted by order column', function (): void {
    $tag1 = Tag::factory()->create();
    $tag2 = Tag::factory()->create();
    $tag3 = Tag::factory()->create();
    $tag1->updateQuietly(['order_column' => 2]);
    $tag2->updateQuietly(['order_column' => 1]);
    $tag3->updateQuietly(['order_column' => 3]);

    $sortedTags = Tag::ordered()->get();

    expect($sortedTags->first()->id)->toBe($tag2->id);
    expect($sortedTags->last()->id)->toBe($tag3->id);
});

it('can be moved in order', function (): void {
    $tag1 = Tag::factory()->create();
    $tag2 = Tag::factory()->create();
    $tag3 = Tag::factory()->create();
    $tag1->updateQuietly(['order_column' => 1]);
    $tag2->updateQuietly(['order_column' => 2]);
    $tag3->updateQuietly(['order_column' => 3]);

    $tag1->moveToEnd();

    expect($tag1->fresh()->order_column)->toBe(3);
    expect($tag2->fresh()->order_column)->toBe(1);
    expect($tag3->fresh()->order_column)->toBe(2);
});

it('find or create with array returns a collection of tags', function (): void {
    $tags = Tag::findOrCreate(['Alpha', 'Beta']);

    expect($tags)->toHaveCount(2)
        ->and($tags->first())->toBeInstanceOf(Tag::class);
});

it('get with type returns tags for that type', function (): void {
    Tag::factory()->create(['type' => 'news']);

    $list = Tag::getWithType('news');

    expect($list)->not->toBeEmpty();
    expect($list->first()->type)->toBe('news');
});

it('find from string of any type matches translation name', function (): void {
    $tag = Tag::factory()->create();
    $locale = config('app.locale');
    $tag->setTranslation($locale, [
        'name' => 'GlobUnique',
        'slug' => 'glob-unique',
    ]);
    $tag->save();

    $found = Tag::findFromStringOfAnyType('GlobUnique');

    expect($found->pluck('id')->contains($tag->id))->toBeTrue();
});

it('get types lists distinct type values', function (): void {
    Tag::factory()->create(['type' => 'alpha-type']);

    $types = Tag::getTypes();

    expect($types->contains('alpha-type'))->toBeTrue();
});

it('with type scope restricts by type when argument is not null', function (): void {
    Tag::factory()->create(['type' => 'scope-me']);

    expect(Tag::query()->withType('scope-me')->count())->toBeGreaterThanOrEqual(1);
});

it('exposes merged validation rules', function (): void {
    $tag = Tag::factory()->create();
    $rules = $tag->getRules();

    expect($rules)->toHaveKey('create')
        ->and($rules)->toHaveKey('update')
        ->and($rules['create'])->toHaveKey('translations');
});

it('slug placeholders include name token', function (): void {
    $tag = Tag::factory()->create();
    $method = new ReflectionMethod(Tag::class, 'slugPlaceholders');
    $method->setAccessible(true);

    expect($method->invoke($tag))->toBe(['{name}']);
});

it('containing scope matches translation name substring for default locale', function (): void {
    $locale = config('app.locale');
    $tag_match = Tag::factory()->create();
    $tag_match->setTranslation($locale, [
        'name' => 'Unique Beta String',
        'slug' => 'unique-beta-string',
    ]);
    $tag_match->save();

    $tag_other = Tag::factory()->create();
    $tag_other->setTranslation($locale, [
        'name' => 'Other Name',
        'slug' => 'other-name',
    ]);
    $tag_other->save();

    $ids = Tag::query()->containing('beta')->pluck('id')->all();

    expect($ids)->toContain($tag_match->id)
        ->and($ids)->not->toContain($tag_other->id);
});

it('writes again a translation that was removed, instead of inserting a duplicate', function (): void {
    $tag = Tag::factory()->create();
    $tag->setTranslation('de', ['name' => 'Alt', 'slug' => 'alt']);
    $tag->translations()->where('locale', 'de')->delete();

    $tag->setTranslation('de', ['name' => 'Neu', 'slug' => 'neu']);

    $translations = TagTranslation::query()->withTrashed()->where('tag_id', $tag->id)->where('locale', 'de')->get();

    expect($translations)->toHaveCount(1)
        ->and($translations->sole()->trashed())->toBeFalse()
        ->and($translations->sole()->name)->toBe('Neu');
});

it('saves a translated field into a translation that was removed', function (): void {
    $tag = Tag::factory()->create();
    $tag->setTranslation('de', ['name' => 'Alt', 'slug' => 'alt']);
    $tag->translations()->where('locale', 'de')->delete();

    Modules\Core\Helpers\LocaleContext::set('de');
    $tag->name = 'Neu';
    $tag->slug = 'neu';
    $tag->save();

    $translation = TagTranslation::query()->withTrashed()->where('tag_id', $tag->id)->where('locale', 'de')->sole();

    expect($translation->trashed())->toBeFalse()
        ->and($translation->name)->toBe('Neu');
});

it('stores the type as a slug, and a type with nothing to slug as no type', function (?string $given, ?string $stored): void {
    $tag = Tag::factory()->create(['type' => $given]);

    expect($tag->fresh()->type)->toBe($stored);
})->with([
    'mixed case with spaces' => [' Genre ', 'genre'],
    'several words' => ['Musical Instrument', 'musical-instrument'],
    'underscores' => ['musical_instrument', 'musical-instrument'],
    'blank' => ['   ', null],
    'none' => [null, null],
]);

it('finds the tag of a type whatever way the type is spelled', function (): void {
    $tag = Tag::findOrCreateFromString('Piano', 'musical-instrument');

    expect(Tag::findOrCreateFromString('Piano', 'Musical Instrument')->id)->toBe($tag->id)
        ->and(Tag::getWithType('MUSICAL_INSTRUMENT')->pluck('id')->all())->toBe([$tag->id]);
});

it('matches the tags of a model by type whatever way the type is spelled', function (): void {
    $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    $content->attachTag('Jazz', 'Genre');

    $content->load('tags');

    expect($content->tagsWithType('genre')->pluck('name')->all())->toBe(['Jazz'])
        ->and($content->hasTag('Jazz', 'GENRE'))->toBeTrue();
});

it('refuses a second live translation with the same slug in a locale', function (): void {
    $locale = Modules\Core\Helpers\LocaleContext::getDefault();
    Tag::factory()->create()->setTranslation($locale, ['name' => 'Jazz', 'slug' => 'jazz']);

    expect(fn () => Tag::factory()->create()->setTranslation($locale, ['name' => 'Jazz', 'slug' => 'jazz']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('lets two locales share a slug on different tags', function (): void {
    $default_tag = Tag::factory()->create();
    $default_tag->setTranslation(Modules\Core\Helpers\LocaleContext::getDefault(), ['name' => 'Jazz', 'slug' => 'jazz']);
    $german_tag = Tag::factory()->create();
    $german_tag->setTranslation('de', ['name' => 'Jazz', 'slug' => 'jazz']);

    expect(Tag::findByIdentity('Jazz', 'de')?->id)->toBe($german_tag->id)
        ->and(Tag::findByIdentity('Jazz')?->id)->toBe($default_tag->id);
});

it('finds a tag by the slug of a name, whatever its case', function (): void {
    $tag = Tag::findOrCreateFromString('Libri');

    expect(Tag::findOrCreateFromString('libri')->id)->toBe($tag->id)
        ->and(Tag::query()->count())->toBe(1);
});

it('restores a trashed tag instead of creating a second one', function (): void {
    $tag = Tag::findOrCreateFromString('Jazz');
    $tag->delete();

    $found = Tag::findOrCreateFromString('Jazz');

    expect($found->id)->toBe($tag->id)
        ->and($found->trashed())->toBeFalse()
        ->and(Tag::withTrashed()->count())->toBe(1);
});

it('gives a tag without type the type asked for', function (): void {
    $tag = Tag::findOrCreateFromString('Jazz');

    expect(Tag::findOrCreateFromString('Jazz', 'genre')->id)->toBe($tag->id)
        ->and($tag->fresh()->type)->toBe('genre');
});

it('refuses a type different from the one the tag has', function (): void {
    Tag::findOrCreateFromString('Jazz', 'genre');

    expect(fn () => Tag::findOrCreateFromString('Jazz', 'era'))->toThrow(InvalidArgumentException::class);
});

it('finds from string of any type when no type is given', function (): void {
    $tag = Tag::findOrCreateFromString('Jazz', 'genre');

    expect(Tag::findFromString('Jazz')?->id)->toBe($tag->id)
        ->and(Tag::findFromString('Jazz', 'era'))->toBeNull();
});
