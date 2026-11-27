<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Jobs\ReindexTaggedContentsJob;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Tag;
use Modules\CMS\Models\Translations\TagTranslation;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/*
 * `tag_names` is part of the content's search document, so a change of a tag's name in any language must
 * re-send the contents carrying the tag: the tag row is not searchable and the content row does not change.
 */

beforeEach(function (): void {
    FakeVectorProfile::none();
    setupCMSEntities([EntityType::Contents]);
});

/**
 * A tag named "Cinema" in Italian and English, attached to a content, and a content without it.
 *
 * @return array{tag: Tag, tagged: Content, untagged: Content}
 */
function tag_names_reindex_fixture(): array
{
    $tag = Tag::factory()->create();
    $tag->translations()->forceDelete();
    $tag->translations()->create(['locale' => 'it', 'name' => 'Cinema', 'slug' => 'cinema']);
    $tag->translations()->create(['locale' => 'en', 'name' => 'Cinema', 'slug' => 'cinema']);

    $tagged = Content::factory()->create();
    $untagged = Content::factory()->create();
    $tagged->tags()->sync([$tag->id]);

    return ['tag' => $tag, 'tagged' => $tagged, 'untagged' => $untagged];
}

function english_translation_of(Tag $tag): TagTranslation
{
    return TagTranslation::query()->where('tag_id', $tag->id)->where('locale', 'en')->firstOrFail();
}

it('queues the re-indexing of the contents of a tag when its english translation is renamed', function (): void {
    ['tag' => $tag] = tag_names_reindex_fixture();

    Queue::fake();
    english_translation_of($tag)->update(['name' => 'Movies']);

    Queue::assertPushed(ReindexTaggedContentsJob::class, static fn (ReindexTaggedContentsJob $job): bool => $job->tagId === $tag->id);
});

it('sends the contents carrying the renamed tag, and only those, for re-indexing', function (): void {
    ['tag' => $tag, 'tagged' => $tagged, 'untagged' => $untagged] = tag_names_reindex_fixture();

    Event::fake([ModelRequiresIndexing::class]);
    english_translation_of($tag)->update(['name' => 'Movies']);

    Event::assertDispatched(ModelRequiresIndexing::class, static fn (ModelRequiresIndexing $event): bool => $event->model->is($tagged));
    Event::assertNotDispatched(ModelRequiresIndexing::class, static fn (ModelRequiresIndexing $event): bool => $event->model->is($untagged));
});

it('re-indexes the contents of a tag when a translation is added or deleted', function (): void {
    ['tag' => $tag, 'tagged' => $tagged] = tag_names_reindex_fixture();

    Event::fake([ModelRequiresIndexing::class]);
    $tag->translations()->create(['locale' => 'de', 'name' => 'Kino', 'slug' => 'kino']);
    Event::assertDispatched(ModelRequiresIndexing::class, static fn (ModelRequiresIndexing $event): bool => $event->model->is($tagged));

    Event::fake([ModelRequiresIndexing::class]);
    english_translation_of($tag)->delete();
    Event::assertDispatched(ModelRequiresIndexing::class, static fn (ModelRequiresIndexing $event): bool => $event->model->is($tagged));
});

it('does not queue a re-indexing when only the slug of a translation changes or the tag has no contents', function (): void {
    ['tag' => $tag] = tag_names_reindex_fixture();
    $unused = Tag::factory()->create();
    $unused->translations()->forceDelete();

    Queue::fake();
    english_translation_of($tag)->update(['slug' => 'cinema-en']);
    $unused->translations()->create(['locale' => 'de', 'name' => 'Kino', 'slug' => 'kino-unused']);

    Queue::assertNotPushed(ReindexTaggedContentsJob::class);
});
