<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Enums\AiAssistance;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Models\Preset;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\TranslationRequiresReembedding;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

// The observer fires Modules\Core\Events\TranslationRequiresReembedding, which
// Modules\AI's HandleTranslationReembeddingListener turns into a real
// GenerateEmbeddingsJob::dispatch() — executed synchronously under the test
// suite's QUEUE_CONNECTION=sync. Fake the queue for every test in this file up
// front so that setup helpers (which create/update ContentTranslation rows and
// thus trigger the observer) never execute that job for real. Tests that need
// to isolate the action under test additionally fake TranslationRequiresReembedding
// itself right before that action (discarding the setup noise and asserting CMS's
// side of the contract directly, independent of AI's listener), mirroring
// LocationObserverTest's convention.
//
// The observer only dispatches when Content::isEmbeddable() is true, which
// requires a vector profile (none by default, mirroring
// HandleModelIndexingListenerTest's own setup) — pin one here so the
// saved()-path tests exercise real behavior instead of the disabled no-op.
beforeEach(function (): void {
    Queue::fake();
    FakeVectorProfile::use();
});

/**
 * Build a Content with exactly two translations (it + en), bypassing the factory's
 * randomized extra-locale duplication so translation identity is deterministic
 * (mirrors createBilingualSearchableContent() in ContentSearchableArrayTest.php).
 */
function createReembedTestContent(string $itTitle, string $enTitle): Content
{
    setupCMSEntities([EntityType::Contents]);

    $entity = Entity::query()->where('name', 'contents')->firstOrFail();
    $preset = Preset::query()->where('entity_id', $entity->id)->where('name', 'default')->firstOrFail();
    $presettable = Presettable::query()
        ->where('entity_id', $entity->id)
        ->where('preset_id', $preset->id)
        ->whereNull('deleted_at')
        ->latest('version')
        ->firstOrFail();

    $content = Content::query()->create([
        'entity_id' => $entity->id,
        'presettable_id' => $presettable->id,
        'valid_from' => now(),
    ]);

    ContentTranslation::query()->create([
        'content_id' => $content->id,
        'locale' => 'it',
        'title' => $itTitle,
        'slug' => Str::slug($itTitle),
        'components' => [],
    ]);

    ContentTranslation::query()->create([
        'content_id' => $content->id,
        'locale' => 'en',
        'title' => $enTitle,
        'slug' => Str::slug($enTitle),
        'components' => [],
    ]);

    return $content->fresh(['translations']);
}

/**
 * Name of the sole (textual) dynamic field attached to the default "contents"
 * preset by setupCMSEntities(), so a components-only change can be exercised
 * without depending on a hardcoded field name.
 */
function reembedTestTextualFieldName(): string
{
    $entity = Entity::query()->where('name', 'contents')->firstOrFail();
    $preset = Preset::query()->where('entity_id', $entity->id)->where('name', 'default')->firstOrFail();

    return $preset->fields()->firstOrFail()->name;
}

// ─────────────────────────────────────────────────────────────────────────────
// saved(): new translation always re-embeds (wasRecentlyCreated)
// ─────────────────────────────────────────────────────────────────────────────

it('dispatches TranslationRequiresReembedding scoped to the new locale when a translation is created', function (): void {
    setupCMSEntities([EntityType::Contents]);

    $entity = Entity::query()->where('name', 'contents')->firstOrFail();
    $preset = Preset::query()->where('entity_id', $entity->id)->where('name', 'default')->firstOrFail();
    $presettable = Presettable::query()
        ->where('entity_id', $entity->id)
        ->where('preset_id', $preset->id)
        ->whereNull('deleted_at')
        ->latest('version')
        ->firstOrFail();

    $content = Content::query()->create([
        'entity_id' => $entity->id,
        'presettable_id' => $presettable->id,
        'valid_from' => now(),
    ]);

    Event::fake([TranslationRequiresReembedding::class]);

    ContentTranslation::query()->create([
        'content_id' => $content->id,
        'locale' => 'it',
        'title' => 'Titolo iniziale',
        'slug' => 'titolo-iniziale',
        'components' => [],
    ]);

    Event::assertDispatched(TranslationRequiresReembedding::class, 1);
    Event::assertDispatched(TranslationRequiresReembedding::class, fn (TranslationRequiresReembedding $event): bool => $event->model->is($content) && $event->locale === 'it');
});

// ─────────────────────────────────────────────────────────────────────────────
// saved(): title change re-embeds only that locale
// ─────────────────────────────────────────────────────────────────────────────

it('dispatches TranslationRequiresReembedding scoped to only the changed locale when a translation title changes', function (): void {
    $content = createReembedTestContent('Titolo di prova', 'Test title');

    Event::fake([TranslationRequiresReembedding::class]);

    $content->translations()->where('locale', 'it')->first()->update(['title' => 'Titolo aggiornato']);

    Event::assertDispatched(TranslationRequiresReembedding::class, 1);
    Event::assertDispatched(TranslationRequiresReembedding::class, fn (TranslationRequiresReembedding $event): bool => $event->model->is($content) && $event->locale === 'it');
});

// ─────────────────────────────────────────────────────────────────────────────
// saved(): derived-attribute trap — a components-only change (which feeds the
// derived `textual_only` embed field) must still trigger a re-embed, even
// though `getChanges()` never lists "textual_only" itself.
// ─────────────────────────────────────────────────────────────────────────────

it('dispatches TranslationRequiresReembedding when only components change (feeds the derived textual_only embed field)', function (): void {
    $content = createReembedTestContent('Titolo di prova', 'Test title');
    $field_name = reembedTestTextualFieldName();

    Event::fake([TranslationRequiresReembedding::class]);

    $translation = $content->translations()->where('locale', 'it')->first();
    $translation->update(['components' => [$field_name => 'Nuovo contenuto testuale']]);

    expect($translation->wasChanged('components'))->toBeTrue()
        ->and($translation->wasChanged('title'))->toBeFalse();

    Event::assertDispatched(TranslationRequiresReembedding::class, 1);
    Event::assertDispatched(TranslationRequiresReembedding::class, fn (TranslationRequiresReembedding $event): bool => $event->model->is($content) && $event->locale === 'it');
});

// ─────────────────────────────────────────────────────────────────────────────
// saved(): unrelated field changes must NOT dispatch a re-embed
// ─────────────────────────────────────────────────────────────────────────────

it('does not dispatch TranslationRequiresReembedding when only a non-embeddable field changes', function (): void {
    $content = createReembedTestContent('Titolo di prova', 'Test title');

    Event::fake([TranslationRequiresReembedding::class]);

    $translation = $content->translations()->where('locale', 'it')->first();
    $translation->update(['ai_assistance' => AiAssistance::Edited]);

    Event::assertNotDispatched(TranslationRequiresReembedding::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// saved(): the Content::isEmbeddable() gate — no dispatch when vector search
// itself is disabled, regardless of what changed. Without this, every
// ContentTranslation save anywhere (e.g. Content::factory()->create() across
// the wider CMS suite) would enqueue a real embedding call under the test
// suite's synchronous queue connection.
// ─────────────────────────────────────────────────────────────────────────────

it('does not dispatch TranslationRequiresReembedding when vector search is disabled, even on a title change', function (): void {
    $content = createReembedTestContent('Titolo di prova', 'Test title');

    FakeVectorProfile::none();
    Event::fake([TranslationRequiresReembedding::class]);

    $content->translations()->where('locale', 'it')->first()->update(['title' => 'Titolo aggiornato']);

    Event::assertNotDispatched(TranslationRequiresReembedding::class);
});

it('reads no content on a translation save when vector search is disabled', function (): void {
    $content = createReembedTestContent('Titolo di prova', 'Test title');
    FakeVectorProfile::none();
    $translation = $content->translations()->where('locale', 'it')->firstOrFail();
    $reads = [];
    DB::listen(static function ($query) use (&$reads): void {
        if (str_starts_with($query->sql, 'select') && (str_contains($query->sql, '"cms_contents"') || str_contains($query->sql, 'core_presettables'))) {
            $reads[] = $query->sql;
        }
    });

    $translation->update(['title' => 'Titolo aggiornato']);

    expect($reads)->toBe([]);
});

it('reads the content but not its preset to decide a re-embed', function (): void {
    $content = createReembedTestContent('Titolo di prova', 'Test title');
    $translation = $content->translations()->where('locale', 'it')->firstOrFail();
    Event::fake([TranslationRequiresReembedding::class]);
    $preset_reads = [];
    DB::listen(static function ($query) use (&$preset_reads): void {
        if (str_contains($query->sql, 'core_presettables')) {
            $preset_reads[] = $query->sql;
        }
    });

    $translation->update(['title' => 'Titolo aggiornato']);

    Event::assertDispatched(TranslationRequiresReembedding::class, 1);
    expect($preset_reads)->toBe([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// deleted(): drops that locale's embeddings only, and reindexes the parent
// ─────────────────────────────────────────────────────────────────────────────

it('deletes only the deleted translation locale embeddings and reindexes the parent content', function (): void {
    $content = createReembedTestContent('Titolo di prova', 'Test title');

    $content->embeddings()->create(['embedding' => [0.1, 0.2], 'locale' => 'it', 'model_key' => 'test-model']);
    $content->embeddings()->create(['embedding' => [0.3, 0.4], 'locale' => 'en', 'model_key' => 'test-model']);

    Event::fake(ModelRequiresIndexing::class);

    $content->translations()->where('locale', 'it')->first()->delete();

    expect($content->embeddings()->forLocale('it')->count())->toBe(0)
        ->and($content->embeddings()->forLocale('en')->count())->toBe(1);

    Event::assertDispatched(ModelRequiresIndexing::class, fn (ModelRequiresIndexing $event): bool => $event->model->is($content));
});

it('does not dispatch a re-embed event when a translation is deleted', function (): void {
    $content = createReembedTestContent('Titolo di prova', 'Test title');

    Event::fake([ModelRequiresIndexing::class, TranslationRequiresReembedding::class]);

    $content->translations()->where('locale', 'it')->first()->delete();

    Event::assertNotDispatched(TranslationRequiresReembedding::class);
});
