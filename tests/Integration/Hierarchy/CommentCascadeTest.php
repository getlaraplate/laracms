<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Entity;
use Modules\CMS\Models\Pivot\Presettable;
use Modules\CMS\Models\Preset;
use Modules\CMS\Models\Translations\ContentTranslation;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Template;
use Modules\Core\Models\User;
use Modules\Core\Support\Hierarchy\ClosureTableMaintainer;
use Modules\Core\Versioning\HardDeleteCoordinator;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    LocaleContext::set('en');
    $this->comments = ClosureTableMaintainer::forModel(new Comment);
    $this->otherUser = User::factory()->create();
    $this->otherContent = commentCascadeContent(commentCascadePreset('other'));
    $this->actingAs($this->otherUser);

    /**
     * A thread under a comment of the other owner: a comment and its reply owned by the doomed owner, each with a
     * reply of the other owner, the first reply with a leaf.
     *
     * @return array{top: int, doomed: list<int>, replies: list<int>, leaf: int}
     */
    $this->addThread = function (array $doomedOwner): array {
        $other = ['content_id' => $this->otherContent->id, 'user_id' => $this->otherUser->id];
        $add = static fn (array $owner, ?int $parentId): int => (int) Comment::factory()->create([...$owner, 'parent_id' => $parentId])->id;

        $top = $add($other, null);
        $doomed = $add($doomedOwner, $top);
        $reply = $add($other, $doomed);
        $leaf = $add($other, $reply);
        $nested_doomed = $add($doomedOwner, $doomed);
        $nested_reply = $add($other, $nested_doomed);

        return ['top' => $top, 'doomed' => [$doomed, $nested_doomed], 'replies' => [$reply, $nested_reply], 'leaf' => $leaf];
    };
});

/**
 * A preset of its own entity of contents, with the presettable the presets insert trigger writes.
 */
function commentCascadePreset(string $name, ?int $templateId = null): Preset
{
    $entity = Entity::query()->create(['name' => 'cascade ' . $name, 'slug' => Str::slug('cascade ' . $name), 'type' => EntityType::Contents]);

    $attributes = ['entity_id' => $entity->id, 'name' => $name];

    if ($templateId !== null) {
        $attributes['template_id'] = $templateId;
    }

    return Preset::query()->create($attributes);
}

function commentCascadePresettableOf(Preset $preset): Presettable
{
    return Presettable::query()->where('preset_id', $preset->id)->latest('version')->firstOrFail();
}

/**
 * A minimal content of the preset's entity and presettable.
 */
function commentCascadeContent(Preset $preset): Content
{
    $content = Content::query()->create([
        'entity_id' => $preset->entity_id,
        'presettable_id' => commentCascadePresettableOf($preset)->id,
        'valid_from' => now(),
    ]);

    ContentTranslation::query()->create([
        'content_id' => $content->id,
        'locale' => 'en',
        'title' => 'Cascade content ' . $preset->name,
        'slug' => Str::slug('cascade content ' . $preset->name),
        'components' => [],
    ]);

    return $content;
}

/**
 * Every comment closure row as "ancestor>descendant:depth", sorted.
 *
 * @return list<string>
 */
function commentCascadeClosureRows(): array
{
    $rows = DB::table(CMSTables::CommentsClosure->value)->get()
        ->map(static fn (object $row): string => sprintf('%d>%d:%d', $row->ancestor_id, $row->descendant_id, $row->depth))
        ->all();
    sort($rows);

    return $rows;
}

it('roots the comments left by an owner hard delete', function (Closure $owner): void {
    /** @var array{owner: array{content_id: int, user_id: int}, delete: Closure(): mixed} $doomed */
    $doomed = $owner->call($this);
    $thread = ($this->addThread)($doomed['owner']);

    expect(($doomed['delete'])())->toBeTrue();

    $maintained = commentCascadeClosureRows();

    expect(DB::table(CMSTables::Comments->value)->whereIn('id', $thread['doomed'])->exists())->toBeFalse()
        ->and(DB::table(CMSTables::Comments->value)->whereIn('id', $thread['replies'])->pluck('parent_id')->all())->toBe([null, null])
        ->and(DB::table(CMSTables::CommentsClosure->value)->where('ancestor_id', $thread['top'])->pluck('descendant_id')->all())->toBe([$thread['top']])
        ->and($maintained)->toContain(sprintf('%d>%d:1', $thread['replies'][0], $thread['leaf']));

    $this->comments->rebuild();

    expect(commentCascadeClosureRows())->toBe($maintained);
})->with([
    'user, by user_id, purged' => [function (): array {
        $user = User::factory()->create();

        return [
            'owner' => ['content_id' => $this->otherContent->id, 'user_id' => $user->id],
            'delete' => static fn (): bool => app(HardDeleteCoordinator::class)->purge($user),
        ];
    }],
    'content, by content_id' => [function (): array {
        $content = commentCascadeContent(commentCascadePreset('doomed'));

        return ['owner' => ['content_id' => $content->id, 'user_id' => $this->otherUser->id], 'delete' => static fn (): ?bool => $content->forceDelete()];
    }],
    'entity, through its contents' => [function (): array {
        $preset = commentCascadePreset('doomed');
        $content = commentCascadeContent($preset);
        $entity = Entity::query()->findOrFail($preset->entity_id);

        return ['owner' => ['content_id' => $content->id, 'user_id' => $this->otherUser->id], 'delete' => static fn (): ?bool => $entity->forceDelete()];
    }],
    'preset, through its presettables and contents' => [function (): array {
        $preset = commentCascadePreset('doomed');
        $content = commentCascadeContent($preset);

        return ['owner' => ['content_id' => $content->id, 'user_id' => $this->otherUser->id], 'delete' => static fn (): ?bool => $preset->forceDelete()];
    }],
    'presettable, through its contents' => [function (): array {
        $preset = commentCascadePreset('doomed');
        $content = commentCascadeContent($preset);
        $presettable = commentCascadePresettableOf($preset);

        return ['owner' => ['content_id' => $content->id, 'user_id' => $this->otherUser->id], 'delete' => static function () use ($presettable): bool {
            try {
                $presettable->forceDelete();
            } catch (TypeError) {
                // Pre-existing: Core SoftDeletes::delete() declares ?bool, a pivot's delete returns the row count, so
                // the return type throws after the row went and the events ran. Either outcome leaves the same rows.
            }

            return true;
        }];
    }],
    'template, through its presets, presettables and contents' => [function (): array {
        $template = Template::query()->create(['name' => 'cascade', 'content' => '<p>cascade</p>']);
        $content = commentCascadeContent(commentCascadePreset('doomed', (int) $template->id));

        return ['owner' => ['content_id' => $content->id, 'user_id' => $this->otherUser->id], 'delete' => static fn (): ?bool => $template->forceDelete()];
    }],
]);

it('leaves the comments and their closure as they are when a later listener cancels the user purge', function (): void {
    $user = User::factory()->create();
    $thread = ($this->addThread)(['content_id' => $this->otherContent->id, 'user_id' => $user->id]);
    $before = commentCascadeClosureRows();
    User::deleting(static fn (): bool => false);

    expect(app(HardDeleteCoordinator::class)->purge($user))->toBeFalse()
        ->and(DB::table(CMSTables::Comments->value)->whereIn('id', $thread['doomed'])->count())->toBe(2)
        ->and(commentCascadeClosureRows())->toBe($before);
});

it('leaves the comments and their closure as they are when the user only goes to the bin', function (): void {
    $user = User::factory()->create();
    ($this->addThread)(['content_id' => $this->otherContent->id, 'user_id' => $user->id]);
    $before = commentCascadeClosureRows();

    expect($user->delete())->toBeTrue()
        ->and(commentCascadeClosureRows())->toBe($before);
});
