<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Models\Location;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Models\Tag;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Casts\FieldType as CoreFieldType;
use Modules\Core\Models\Field;
use Modules\Core\Services\DynamicContentsService;
use Modules\Core\Services\PresetVersioningService;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $content = new Content;

    if (
        ! method_exists(Content::class, 'determineOrderColumnName')
        || ! $content->getConnection()->getSchemaBuilder()->hasColumn($content->getTable(), 'shared_components')
    ) {
        $this->markTestSkipped('Content integration features require full Core runtime.');
    }

    setupCMSEntities();
    $this->content = Content::factory()->create();
});

it('can be created with factory', function (): void {
    expect($this->content)->toBeInstanceOf(Content::class);
    expect($this->content->id)->not->toBeNull();
});

it('has translatable attributes', function (): void {
    $content = Content::factory()->create();

    // Set translation for default locale
    $default_locale = config('app.locale');
    $content->setTranslation($default_locale, [
        'title' => 'Test Content',
        'slug' => 'test-content',
        'components' => [
            'body' => 'Test content body',
            'excerpt' => 'Test excerpt',
        ],
    ]);
    $content->save();

    expect($content->title)->toBe('Test Content');
    expect($content->slug)->toBe('test-content');
    expect($content->getTranslation($default_locale)->components['body'])->toBe('Test content body');
    expect($content->getTranslation($default_locale)->components['excerpt'])->toBe('Test excerpt');
});

it('stores dynamic translated and shared fields in the correct containers', function (): void {
    $content = Content::factory()->create();
    $preset = $content->preset;

    $translatable_field_name = 'dyn_translatable_' . uniqid();
    $shared_field_name = 'dyn_shared_' . uniqid();

    $translatable_field = Field::query()->create([
        'name' => $translatable_field_name,
        'type' => CoreFieldType::Text,
        'options' => new stdClass(),
    ]);
    $translatable_field->is_translatable = true;
    $translatable_field->save();

    $shared_field = Field::query()->create([
        'name' => $shared_field_name,
        'type' => CoreFieldType::Text,
        'options' => new stdClass(),
    ]);
    $shared_field->is_translatable = false;
    $shared_field->save();

    $preset->fields()->attach($translatable_field->id, [
        'default' => null,
        'is_required' => false,
    ]);
    $preset->fields()->attach($shared_field->id, [
        'default' => null,
        'is_required' => false,
    ]);

    resolve(PresetVersioningService::class)->createVersion($preset);
    DynamicContentsService::getInstance()->clearAllCaches();

    $latest_presettable = Presettable::query()
        ->where('entity_id', $content->entity_id)
        ->where('preset_id', $preset->id)
        ->whereNull('deleted_at')
        ->latest('version')
        ->firstOrFail();

    $content->setRelation('presettable', $latest_presettable);
    $content->{$translatable_field_name} = 'Translated dynamic value';
    $content->{$shared_field_name} = 'Shared dynamic value';
    $content->save();

    $translation = (new ContentTranslation)->setConnection($content->getConnection()->getName());
    $content_row = (array) $content->getConnection()->table($content->getTable())->where('id', $content->id)->first();
    $translation_row = (array) $translation->getConnection()->table($translation->getTable())
        ->where('content_id', $content->id)
        ->where('locale', config('app.locale'))
        ->first();

    $shared_components = json_decode((string) ($content_row['shared_components'] ?? '{}'), true);
    $translated_components = json_decode((string) ($translation_row['components'] ?? '{}'), true);

    expect($translated_components[$translatable_field_name] ?? null)->toBe('Translated dynamic value')
        ->and($translated_components[$shared_field_name] ?? null)->toBeNull()
        ->and($shared_components[$shared_field_name] ?? null)->toBe('Shared dynamic value')
        ->and($shared_components)->not->toHaveKey($translatable_field_name);
});

it('belongs to many categories', function (): void {
    $default_locale = config('app.locale');

    $category1 = Category::factory()->create();
    $category1->setTranslation($default_locale, ['name' => 'Technology']);
    $category1->save();

    $category2 = Category::factory()->create();
    $category2->setTranslation($default_locale, ['name' => 'Science']);
    $category2->save();

    $this->content->categories()->attach([$category1->id, $category2->id]);

    expect($this->content->categories)->toHaveCount(2);
    expect($this->content->categories->pluck('name')->toArray())->toContain('Technology', 'Science');
});

it('belongs to many contributors', function (): void {
    $default_locale = config('app.locale');

    // Contributor `name` is a direct column, but LocaleScope still requires a
    // translation row for the current locale, so seed one via setTranslation.
    $contributor1 = Contributor::factory()->create(['name' => 'John Doe']);
    $contributor1->setTranslation($default_locale, ['slug' => 'john-doe', 'components' => []]);
    $contributor1->save();

    $contributor2 = Contributor::factory()->create(['name' => 'Jane Smith']);
    $contributor2->setTranslation($default_locale, ['slug' => 'jane-smith', 'components' => []]);
    $contributor2->save();

    $this->content->contributors()->attach([$contributor1->id, $contributor2->id]);

    expect($this->content->contributors)->toHaveCount(2);
    expect($this->content->contributors->pluck('name')->toArray())->toContain('John Doe', 'Jane Smith');
});

it('belongs to many tags', function (): void {
    $tag1 = Tag::factory()->create(['name' => 'laravel']);
    $tag2 = Tag::factory()->create(['name' => 'php']);

    $this->content->tags()->attach([$tag1->id, $tag2->id]);

    expect($this->content->tags)->toHaveCount(2);
    expect($this->content->tags->pluck('name')->toArray())->toContain('laravel', 'php');
});

it('keeps its tags when it is soft deleted', function (): void {
    $this->content->attachTags(['laravel', 'php']);

    $this->content->delete();

    expect($this->content->tags()->count())->toBe(2);
});

it('has its tags back when it is restored from the bin', function (): void {
    $this->content->attachTags(['laravel', 'php']);
    $this->content->delete();

    $this->content->restore();

    expect($this->content->tags()->get()->pluck('name')->all())->toContain('laravel', 'php');
});

it('detaches its tags when it is force deleted', function (): void {
    $this->content->attachTags(['laravel', 'php']);

    $this->content->forceDelete();

    $this->assertDatabaseCount(CMSTables::Taggables->value, 0);
});

it('belongs to many locations', function (): void {
    $location1 = Location::factory()->create(['name' => 'Milan']);
    $location2 = Location::factory()->create(['name' => 'Rome']);

    $this->content->locations()->attach([$location1->id, $location2->id]);

    expect($this->content->locations)->toHaveCount(2);
    expect($this->content->locations->pluck('name')->toArray())->toContain('Milan', 'Rome');
});

it('has slug trait', function (): void {
    expect($this->content->isTranslatableField('slug'))->toBeTrue();
    expect(Content::getTranslatableFields())->toContain('slug');
});

it('has tags trait', function (): void {
    expect(method_exists($this->content, 'attachTags'))->toBeTrue();
    expect(method_exists($this->content, 'detachTags'))->toBeTrue();
});

it('has multimedia trait', function (): void {
    expect(method_exists($this->content, 'addMedia'))->toBeTrue();
    expect(method_exists($this->content, 'getMedia'))->toBeTrue();
});

it('has dynamic contents trait', function (): void {
    expect(class_uses_recursive($this->content))->toContain(Modules\Core\Models\Concerns\HasDynamicContents::class);
    expect(method_exists($this->content, 'getDynamicFields'))->toBeTrue();
});

it('has approvals trait', function (): void {
    expect(class_uses_recursive($this->content))->toContain(Modules\Core\Models\Concerns\HasApprovals::class);
});

it('has related contents relationship', function (): void {
    expect(method_exists($this->content, 'related'))->toBeTrue();
    expect($this->content->related())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
});

it('has validity trait', function (): void {
    expect(method_exists($this->content, 'isValid'))->toBeTrue();
    expect(method_exists($this->content, 'isExpired'))->toBeTrue();
});

it('has versions trait', function (): void {
    expect(method_exists($this->content, 'versions'))->toBeTrue();
    expect(method_exists($this->content, 'createVersion'))->toBeTrue();
});

it('has soft deletes trait', function (): void {
    $this->content->delete();

    expect($this->content->trashed())->toBeTrue();
    expect(Content::withTrashed()->find($this->content->id))->not->toBeNull();
});

it('has sortable trait', function (): void {
    expect(class_uses_recursive($this->content))->toContain(Modules\Core\Models\Concerns\SortableTrait::class);
    expect(method_exists($this->content, 'determineOrderColumnName'))->toBeTrue();
});

it('has locks trait', function (): void {
    expect(method_exists($this->content, 'lock'))->toBeTrue();
    expect(method_exists($this->content, 'unlock'))->toBeTrue();
});

it('has validations trait', function (): void {
    expect(method_exists($this->content, 'getRules'))->toBeTrue();
});

it('has searchable trait', function (): void {
    expect(method_exists($this->content, 'toSearchableArray'))->toBeTrue();
    expect(method_exists($this->content, 'shouldBeSearchable'))->toBeTrue();
});

it('has optimistic locking trait', function (): void {
    expect(method_exists($this->content, 'lockVersionColumn'))->toBeTrue();
    expect(method_exists($this->content, 'currentLockVersion'))->toBeTrue();
});

it('can be created with specific translation attributes', function (): void {
    $content = Content::factory()->create();
    $default_locale = config('app.locale');
    $content->setTranslation($default_locale, [
        'title' => 'Custom Content',
        'slug' => 'custom-content',
        'components' => [
            'body' => 'Custom content body',
            'excerpt' => 'Custom excerpt',
        ],
    ]);
    $content->save();

    expect($content->title)->toBe('Custom Content');
    expect($content->slug)->toBe('custom-content');
    expect($content->getTranslation($default_locale)->components['body'])->toBe('Custom content body');
    expect($content->getTranslation($default_locale)->components['excerpt'])->toBe('Custom excerpt');
});

it('can be found by title through translation', function (): void {
    $content = Content::factory()->create();
    $default_locale = config('app.locale');
    $content->setTranslation($default_locale, [
        'title' => 'Unique Content',
        'slug' => 'unique-content',
    ]);
    $content->save();

    // Title is now in translations, so we need to search through the relation
    $foundContent = Content::query()->whereHas('translations', static function ($q): void {
        $q->where('title', 'Unique Content');
    })->first();

    expect($foundContent->id)->toBe($content->id);
    expect($foundContent->title)->toBe('Unique Content');
});

it('can be found by slug through translation', function (): void {
    $content = Content::factory()->create();
    $default_locale = config('app.locale');
    $content->setTranslation($default_locale, [
        'title' => 'Test Content',
        'slug' => 'unique-slug',
    ]);
    $content->save();

    // Slug is now in translations, so we need to search through the relation
    $foundContent = Content::query()->whereHas('translations', static function ($q): void {
        $q->where('slug', 'unique-slug');
    })->first();

    expect($foundContent->id)->toBe($content->id);
    expect($foundContent->slug)->toBe('unique-slug');
});

it('has translations trait', function (): void {
    expect(method_exists($this->content, 'translations'))->toBeTrue();
    expect(method_exists($this->content, 'translation'))->toBeTrue();
    expect(method_exists($this->content, 'getTranslation'))->toBeTrue();
    expect(method_exists($this->content, 'setTranslation'))->toBeTrue();
    expect(method_exists($this->content, 'hasTranslation'))->toBeTrue();
    expect(method_exists($this->content, 'getTranslatableFields'))->toBeTrue();
});

it('has proper timestamps', function (): void {
    $content = Content::factory()->create();

    expect($content->created_at)->toBeInstanceOf(Carbon\CarbonImmutable::class);
    expect($content->updated_at)->toBeInstanceOf(Carbon\CarbonImmutable::class);
});

it('can be serialized to array with translations', function (): void {
    $content = Content::factory()->create();
    $default_locale = config('app.locale');
    $content->setTranslation($default_locale, [
        'title' => 'Test Content',
        'slug' => 'test-content',
    ]);
    $content->save();

    $contentArray = $content->toArray();

    expect($contentArray)->toHaveKey('id');
    expect($contentArray)->toHaveKey('title');
    expect($contentArray)->toHaveKey('slug');
    expect($contentArray)->toHaveKey('created_at');
    expect($contentArray)->toHaveKey('updated_at');
    expect($contentArray['title'])->toBe('Test Content');
    expect($contentArray['slug'])->toBe('test-content');
});

it('can be restored after soft delete', function (): void {
    $content = Content::factory()->create();
    $content->delete();

    expect($content->trashed())->toBeTrue();

    $content->restore();

    expect($content->trashed())->toBeFalse();
});

it('can be permanently deleted', function (): void {
    $content = Content::factory()->create();
    $contentId = $content->id;

    $content->forceDelete();

    expect(Content::withTrashed()->find($contentId))->toBeNull();
});

/**
 * A search hit carries the content's own columns and the text lives in its translations, so a reranker
 * was given an empty text for every content until the content said what to read.
 */
it('provides the text a reranker reads: the title and the text components in the language asked', function (): void {
    $content = Content::factory()->create();
    $content->setTranslation('it', [
        'title' => 'Titolo italiano',
        'slug' => 'titolo-italiano',
        'components' => ['short_content' => 'Sommario breve', 'content' => 'Corpo in italiano'],
    ]);
    $content->setTranslation('en', [
        'title' => 'English title',
        'slug' => 'english-title',
        'components' => ['content' => 'English body'],
    ]);
    $content->save();

    $text = Content::rerankerTexts([$content->id], 'it')[$content->id] ?? null;

    expect($text)->toContain('Titolo italiano')
        ->toContain('Sommario breve')
        ->toContain('Corpo in italiano')
        ->not->toContain('English');
});

/**
 * An Editor component is read as its plain text, the same text for the reranker and the search index, and a plain
 * string component loses its line breaks. Pins the output before the readers moved to Core `EditorPlainText`.
 */
it('reads an Editor component as its plain text for the reranker and the search index', function (): void {
    $content = Content::factory()->create();
    $content->setTranslation('it', [
        'title' => 'Titolo',
        'slug' => 'titolo',
        'components' => [
            'content' => ['blocks' => [
                ['type' => 'header', 'data' => ['text' => 'Intestazione <b>forte</b>']],
                ['type' => 'paragraph', 'data' => ['text' => 'Caff&egrave; aperto,<br>ogni giorno.']],
                ['type' => 'list', 'data' => ['items' => ['Uno', ['text' => 'Due']]]],
                ['type' => 'table', 'data' => ['content' => [['A', 'B']]]],
            ]],
            'short_content' => "riga uno\nriga due",
        ],
    ]);
    $content->save();

    $editor_text = 'Intestazione forte Caffè aperto, ogni giorno. Uno Due A B';
    $reranker_text = Content::rerankerTexts([$content->id], 'it')[$content->id] ?? null;
    $document = Content::query()->findOrFail($content->id)->toSearchableArray();

    expect($reranker_text)->toContain($editor_text)
        ->toContain('riga unoriga due')
        ->and($document['content']['it'])->toBe($editor_text)
        ->and($document['short_content']['it'])->toBe('riga unoriga due');
});

it('leaves out the contents with no translation in the language asked', function (): void {
    $kept = Content::factory()->create();
    // The factory translates into a random subset of the locales, so the one asked is set explicitly.
    $kept->setTranslation('it', ['title' => 'Tenuto', 'slug' => 'tenuto', 'components' => []]);
    $without = Content::factory()->create();
    $without->translations()->where('locale', 'it')->delete();

    $texts = Content::rerankerTexts([$kept->id, $without->id], 'it');

    expect(array_keys($texts))->toBe([$kept->id]);
});

it('limits the text it provides, so a long article does not make a heavy request', function (): void {
    $content = Content::factory()->create();
    $content->setTranslation('it', ['title' => 'Lungo', 'slug' => 'lungo', 'components' => ['content' => str_repeat('parola ', 2000)]]);
    $content->save();

    expect(mb_strlen(Content::rerankerTexts([$content->id], 'it')[$content->id]))->toBeLessThanOrEqual(2000);
});
