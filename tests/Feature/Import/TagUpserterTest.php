<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\CMS\Import\Dto\ImportTagDto;
use Modules\CMS\Import\Upserters\TagUpserter;
use Modules\CMS\Models\Tag;
use Modules\CMS\Models\Translations\TagTranslation;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Models\RecordOrigin;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config(['scout.driver' => 'null']);

    $this->locale = (string) config('cms.import.locale', Modules\Core\Helpers\LocaleContext::getDefault());
    $this->import = static fn (string $source, int $external_id, string $name, string $slug, ?string $type = null, ?string $deleted_at = null): int => resolve(TagUpserter::class)->upsert(new ImportTagDto(
        externalId: $external_id,
        name: $name,
        slug: $slug,
        type: $type,
        orderColumn: null,
        createdAt: null,
        updatedAt: null,
        deletedAt: $deleted_at,
        sourceType: $source,
    ));
});

it('reuses the tag another source created with the same slug and adds its origin', function (): void {
    $tag_id = ($this->import)('naxos_api@a', 35, 'Unione europea', 'unione-europea');

    expect(($this->import)('feed', 165796270, 'Unione europea', 'unione-europea'))->toBe($tag_id)
        ->and(Tag::withTrashed()->count())->toBe(1)
        ->and(RecordOrigin::query()->where('referable_id', $tag_id)->where('referable_type', (new Tag)->getMorphClass())->count())->toBe(2);
});

it('does not rename a tag another source brought', function (): void {
    $tag_id = ($this->import)('naxos_api@a', 117, 'Libri', 'libri');

    ($this->import)('feed', 240, 'libri', 'libri');

    expect(Tag::withTrashed()->findOrFail($tag_id)->getTranslation($this->locale)?->name)->toBe('Libri');
});

it('renames a tag only its own source brought', function (): void {
    $tag_id = ($this->import)('naxos_api@a', 117, 'Libri', 'libri');

    ($this->import)('naxos_api@a', 117, 'Libri e riviste', 'libri');

    expect(Tag::withTrashed()->findOrFail($tag_id)->getTranslation($this->locale)?->name)->toBe('Libri e riviste');
});

it('slugs the slug the source sends before matching', function (): void {
    $tag_id = ($this->import)('naxos_api@a', 35, 'Unione europea', 'unione-europea');

    expect(($this->import)('feed', 227, 'Unione europea', 'Unione_Europea'))->toBe($tag_id)
        ->and(TagTranslation::query()->where('tag_id', $tag_id)->pluck('slug')->all())->toBe(['unione-europea']);
});

it('stores the slug the source sends as a slug', function (): void {
    $tag_id = ($this->import)('feed', 227, 'Unione europea', 'Unione_Europea');

    expect(TagTranslation::query()->where('tag_id', $tag_id)->value('slug'))->toBe('unione-europea');
});

it('revives a trashed tag that has the slug', function (): void {
    $tag = Tag::findOrCreateFromString('Jazz');
    $tag->delete();

    expect(($this->import)('feed', 1, 'Jazz', 'jazz'))->toBe($tag->id)
        ->and($tag->fresh()->trashed())->toBeFalse();
});

it('keeps the type a tag has and logs the conflict', function (): void {
    Log::spy();
    $tag_id = ($this->import)('naxos_api@a', 1, 'Jazz', 'jazz', 'genre');

    ($this->import)('feed', 2, 'Jazz', 'jazz', 'era');

    expect(Tag::withTrashed()->findOrFail($tag_id)->type)->toBe('genre');
    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => $message === 'Tag type conflict during import');
});

it('gives a tag without type the type another source brings', function (): void {
    $tag_id = ($this->import)('naxos_api@a', 1, 'Jazz', 'jazz');

    ($this->import)('feed', 2, 'Jazz', 'jazz', 'Genre');

    expect(Tag::withTrashed()->findOrFail($tag_id)->type)->toBe('genre');
});

it('does not delete a tag another source still brings', function (): void {
    $tag_id = ($this->import)('naxos_api@a', 1, 'Jazz', 'jazz');
    ($this->import)('feed', 2, 'Jazz', 'jazz');

    ($this->import)('naxos_api@a', 1, 'Jazz', 'jazz', deleted_at: '2026-10-30 10:00:00');

    expect(Tag::withTrashed()->findOrFail($tag_id)->trashed())->toBeFalse();
});

it('deletes a tag only its own source brought when the source marks it deleted', function (): void {
    $tag_id = ($this->import)('naxos_api@a', 1, 'Jazz', 'jazz');

    ($this->import)('naxos_api@a', 1, 'Jazz', 'jazz', deleted_at: '2026-10-30 10:00:00');

    expect(Tag::withTrashed()->findOrFail($tag_id)->trashed())->toBeTrue();
});

it('uses the tag a concurrent import created', function (): void {
    $locale = $this->locale;
    $competitor_id = null;

    // Another import creates the tag right after this one looked for its slug and found nothing.
    DB::listen(static function (QueryExecuted $query) use (&$competitor_id, $locale): void {
        if ($competitor_id !== null || ! str_contains($query->sql, (new TagTranslation)->getTable()) || ! in_array('jazz', $query->bindings, true)) {
            return;
        }

        $competitor_id = (int) DB::table((new Tag)->getTable())->insertGetId(['order_column' => 99, 'created_at' => now(), 'updated_at' => now()]);
        DB::table((new TagTranslation)->getTable())->insert(['tag_id' => $competitor_id, 'locale' => $locale, 'name' => 'Jazz', 'slug' => 'jazz', 'created_at' => now(), 'updated_at' => now()]);
    });

    $tag_id = ($this->import)('feed', 1, 'Jazz', 'jazz');

    expect($tag_id)->toBe($competitor_id)
        ->and(Tag::withTrashed()->count())->toBe(1);
});

it('keeps the slug of a tag a source renames into a slug another tag has, and logs it', function (): void {
    Log::spy();
    $libri_id = ($this->import)('naxos_api@a', 1, 'Libri', 'libri');
    ($this->import)('feed', 2, 'Narrativa', 'narrativa');

    expect(($this->import)('naxos_api@a', 1, 'Narrativa', 'narrativa'))->toBe($libri_id)
        ->and(TagTranslation::query()->where('tag_id', $libri_id)->value('slug'))->toBe('libri');
    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => $message === 'Tag slug conflict during import');
});

it('does not revive a tag an editor trashed when a source sends it deleted', function (): void {
    $tag_id = ($this->import)('naxos_api@a', 1, 'Libri', 'libri');
    ($this->import)('feed', 2, 'Libri', 'libri');
    Tag::query()->findOrFail($tag_id)->delete();

    ($this->import)('naxos_api@a', 1, 'Libri', 'libri', deleted_at: '2026-10-30 10:00:00');

    expect(Tag::withTrashed()->findOrFail($tag_id)->trashed())->toBeTrue();
});

it('does not delete a tag it found only by slug', function (): void {
    $tag = Tag::findOrCreateFromString('Jazz');

    ($this->import)('feed', 2, 'Jazz', 'jazz', deleted_at: '2026-10-30 10:00:00');

    expect($tag->fresh()->trashed())->toBeFalse();
});
