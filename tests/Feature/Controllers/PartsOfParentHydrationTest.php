<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\ContentRating;
use Modules\CMS\Models\ContentReference;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/*
 * A part of its parent (the references and ratings of a content) inherits the parent's visibility: hydrating it
 * accepts the parent's select permission or its own, within the token's abilities. A caller who reads the content
 * reads its references, whether or not its role or its token names the references' permission; a caller holding
 * the references' own permissions reads and updates them from their own endpoint without the content's; a caller
 * holding neither does not hydrate them.
 */

beforeEach(function (): void {
    Cache::flush();
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents]);
    Artisan::call('permission:refresh', ['--quiet' => true]);
});

/**
 * @param  list<class-string<Illuminate\Database\Eloquent\Model>>  $model_classes
 * @param  list<string>  $operations
 */
function partsparent_user(string $guard, array $model_classes, array $operations = ['select']): User
{
    $role = Role::factory()->create(['name' => 'partsparent_' . uniqid(), 'guard_name' => $guard]);

    foreach ($model_classes as $model_class) {
        foreach ($operations as $operation) {
            $role->givePermissionTo(Permission::query()->where(['name' => PermissionName::forClass($model_class, $operation), 'guard_name' => $guard])->firstOrFail());
        }
    }

    $user = User::query()->findOrFail(Modules\Core\Models\User::factory()->create()->getKey());
    $user->assignRole($role);

    return $user->fresh();
}

function partsparent_content(): Content
{
    $content = Content::factory()->create();
    ContentReference::factory()->create(['content_id' => $content->id, 'label' => 'partsparent-reference']);

    return $content;
}

it('loads the references of a content for a token carrying only the content ability', function (): void {
    $content = partsparent_content();
    $user = partsparent_user('api', [Content::class, ContentReference::class]);
    $token = $user->createToken('partsparent', [PermissionName::forClass(Content::class, 'select')])->plainTextToken;

    $response = $this->getJson(
        '/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['references']]),
        ['Authorization' => 'Bearer ' . $token],
    );

    $response->assertOk();
    expect(collect($response->json('data.references'))->pluck('label')->all())->toBe(['partsparent-reference']);
});

it('loads the references of a content for a session role holding only the content permission', function (): void {
    $content = partsparent_content();
    $user = partsparent_user('web', [Content::class]);

    $response = $this->actingAs($user)->getJson('/app/crud/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['references']]));

    $response->assertOk();
    expect(collect($response->json('data.references'))->pluck('label')->all())->toBe(['partsparent-reference']);
});

it('loads the ratings of a content for a token carrying only the content ability', function (): void {
    $content = partsparent_content();
    ContentRating::factory()->create(['content_id' => $content->id, 'score' => 4]);
    $user = partsparent_user('api', [Content::class, ContentRating::class]);
    $token = $user->createToken('partsparent', [PermissionName::forClass(Content::class, 'select')])->plainTextToken;

    $response = $this->getJson(
        '/api/v1/detail/cms/contents?' . http_build_query(['id' => $content->id, 'relations' => ['ratings']]),
        ['Authorization' => 'Bearer ' . $token],
    );

    $response->assertOk();
    expect(collect($response->json('data.ratings'))->pluck('score')->all())->toBe([4]);
});

it('reads a reference from its own endpoint with its own permission only', function (): void {
    $reference = partsparent_content()->references()->firstOrFail();
    $user = partsparent_user('api', [ContentReference::class]);
    $token = $user->createToken('partsparent', [PermissionName::forClass(ContentReference::class, 'select')])->plainTextToken;

    $this->getJson('/api/v1/detail/cms/content_reference?' . http_build_query(['id' => $reference->id]), ['Authorization' => 'Bearer ' . $token])
        ->assertOk()
        ->assertJsonPath('data.label', 'partsparent-reference');
});

it('reads and updates a rating from its own endpoint with its own permissions only', function (): void {
    $rating = ContentRating::factory()->create(['content_id' => partsparent_content()->id, 'score' => 2]);
    $user = partsparent_user('api', [ContentRating::class], ['select', 'update']);
    $token = $user->createToken('partsparent', [
        PermissionName::forClass(ContentRating::class, 'select'),
        PermissionName::forClass(ContentRating::class, 'update'),
    ])->plainTextToken;
    $headers = ['Authorization' => 'Bearer ' . $token];

    $this->getJson('/api/v1/detail/cms/content_rating?' . http_build_query(['id' => $rating->id]), $headers)
        ->assertOk()
        ->assertJsonPath('data.score', 2);

    $this->patchJson('/api/v1/update/cms/content_rating', ['id' => $rating->id, 'score' => 5], $headers)
        ->assertOk();

    expect(ContentRating::query()->toBase()->where('id', $rating->id)->value('score'))->toBe(5);
});

it('updates and deletes a reference with its own permissions only, and re-indexes its content', function (): void {
    $content = partsparent_content();
    $reference = $content->references()->firstOrFail();
    $operations = ['select', 'update', 'delete', 'forceDelete'];
    $user = partsparent_user('api', [ContentReference::class], $operations);
    $token = $user->createToken(
        'partsparent',
        array_map(static fn (string $operation): string => PermissionName::forClass(ContentReference::class, $operation), $operations),
    )->plainTextToken;
    $headers = ['Authorization' => 'Bearer ' . $token];
    Event::fake([ModelRequiresIndexing::class]);

    $this->patchJson('/api/v1/update/cms/content_reference', ['id' => $reference->id, 'label' => 'partsparent-renamed'], $headers)
        ->assertOk();

    expect(ContentReference::query()->toBase()->where('id', $reference->id)->value('label'))->toBe('partsparent-renamed');

    $this->deleteJson('/api/v1/delete/cms/content_reference', ['id' => $reference->id], $headers)
        ->assertOk();

    expect(ContentReference::query()->toBase()->where('id', $reference->id)->exists())->toBeFalse()
        ->and(Event::dispatched(
            ModelRequiresIndexing::class,
            fn (ModelRequiresIndexing $event): bool => $event->model instanceof Content && $event->model->is($content),
        ))->toHaveCount(2);
});

it('refuses a reference to a token holding neither its permission nor its content\'s', function (): void {
    $reference = partsparent_content()->references()->firstOrFail();
    $user = partsparent_user('api', [ContentRating::class]);
    $token = $user->createToken('partsparent', [PermissionName::forClass(ContentRating::class, 'select')])->plainTextToken;

    $this->getJson('/api/v1/detail/cms/content_reference?' . http_build_query(['id' => $reference->id]), ['Authorization' => 'Bearer ' . $token])
        ->assertForbidden();
});

it('hydrates a reference for a caller holding only its own select permission', function (): void {
    $reference = partsparent_content()->references()->firstOrFail();
    Auth::login(partsparent_user('web', [ContentReference::class]));

    expect(ContentReference::query()->find($reference->getKey())?->label)->toBe('partsparent-reference');
});

it('does not hydrate a reference for a caller who may read neither it nor its content', function (): void {
    $reference = partsparent_content()->references()->firstOrFail();
    Auth::login(partsparent_user('web', [ContentRating::class]));

    expect(fn (): ?ContentReference => ContentReference::query()->find($reference->getKey()))->toThrow(
        AuthorizationException::class,
        sprintf('User cannot select %s nor its owner %s', new ContentReference()->getTable(), new Content()->getTable()),
    );
});

it('checks the reference\'s own permission when its content has no registered permission', function (): void {
    $reference = partsparent_content()->references()->firstOrFail();
    Permission::query()->where('name', PermissionName::forClass(Content::class, 'select'))->delete();

    // Hydrating the reference above memoized the content's permission as registered, for the process: forget it,
    // or the owner branch would still be in charge and a fail-open fallback would go unnoticed.
    Once::flush();
    Auth::login(partsparent_user('web', [ContentRating::class]));

    // An exception instance is compared by its exact message, not as a substring.
    expect(fn (): ?ContentReference => ContentReference::query()->find($reference->getKey()))->toThrow(
        new AuthorizationException('User cannot select ' . new ContentReference()->getTable()),
    );
});
