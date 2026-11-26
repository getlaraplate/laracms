<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CMS\Enums\CMSTables;
use Modules\CMS\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Translations\CommentTranslation;
use Modules\CMS\Scopes\CommentTranslationScope;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Modification;
use Modules\Core\Models\User;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;

beforeEach(function (): void {
    setupCMSEntities();
    $this->content = createMinimalTestContentForComments();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    LocaleContext::set('en');
});

it('requires approval when body is pending for current locale', function (): void {
    $comment = new Comment([
        'content_id' => $this->content->id,
        'user_id' => $this->user->id,
    ]);
    $comment->body = 'Pending comment';

    $method = new ReflectionMethod(Comment::class, 'requiresApprovalWhen');
    $method->setAccessible(true);

    expect($method->invoke($comment, []))->toBeTrue();
});

it('creates modification with body locale and content_id on save', function (): void {
    $comment = new Comment([
        'content_id' => $this->content->id,
        'user_id' => $this->user->id,
    ]);
    $comment->body = 'Needs moderation';
    $comment->save();

    $modification = Modification::query()
        ->where('modifiable_type', Comment::class)
        ->where('active', true)
        ->first();

    expect($modification)->not->toBeNull()
        ->and($modification->modifications['body']['modified'])->toBe('Needs moderation')
        ->and($modification->modifications['locale']['modified'])->toBe('en')
        ->and($modification->modifications['content_id']['modified'])->toBe($this->content->id);

    expect(Comment::query()->count())->toBe(0);
});

it('belongs to content', function (): void {
    $comment = Comment::factory()->approved()->create([
        'content_id' => $this->content->id,
        'user_id' => $this->user->id,
    ]);

    expect($comment->content->is($this->content))->toBeTrue();
    expect($this->content->comments()->count())->toBe(1);
});

it('uses cms comments table name from enum', function (): void {
    expect((new Comment())->getTable())->toBe(CMSTables::Comments->value);
});

it('quotes the reserved locale column in the comment translation orders on Db2', function (): void {
    // Warm the fallback setting on SQLite: the pretend connection answers no query.
    expect((new Comment)->translationFallbackEnabledBySettings())->toBeTrue();
    $orders = [];

    Db2PretendConnection::capture(static function () use (&$orders): void {
        $comment = (new Comment)->setConnection('db2_pretend');
        $orders[] = $comment->translation()->toSql();

        $builder = $comment->newQueryWithoutScopes();
        (new CommentTranslationScope)->apply($builder, $comment);
        $relation = $comment->hasOne(CommentTranslation::class);
        $builder->getEagerLoads()['translation']($relation);
        $orders[] = $relation->toSql();
    });

    expect($orders[0])->toContain('order by CASE WHEN "LOCALE" = ? THEN 0 ELSE 1 END')
        ->and($orders[1])->toContain('order by CASE WHEN "LOCALE" = ? THEN 0 ELSE 1 END');
});
