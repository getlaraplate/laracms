<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\CMS\Casts\EntityType;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Content;
use Modules\CMS\Models\Contributor;
use Modules\CMS\Tests\TestCase;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Media;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\FakeVectorProfile;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The history of a record is editorial data: the versions hold the values before they were published, and the raw
 * attributes of the record. The history route is therefore for who may update or approve the entity; a reader who
 * may only select it, and the anonymous caller, are refused whether the record exists or not. The record it returns
 * is serialized by the model, so the hidden attributes and the media projection still apply.
 */

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-10-09 12:00:00');
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupCMSEntities([EntityType::Contents, EntityType::Contributors]);

    Artisan::call('permission:refresh', ['--quiet' => true]);
    $this->seed(CoreDatabaseSeeder::class);
    $this->seed(CMSDatabaseSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * The records whose history is asked, each with the route entity that names it.
 *
 * @return array<string, array{class-string<Illuminate\Database\Eloquent\Model>, string, string}>
 */
function historyaccess_entities(): array
{
    return [
        'comments' => [Comment::class, 'cms', 'comments'],
        'contributors' => [Contributor::class, 'cms', 'contributors'],
        'contents' => [Content::class, 'cms', 'contents'],
        'media' => [Media::class, 'core', 'media'],
    ];
}

/**
 * One record of the entity.
 */
function historyaccess_record(string $class): Illuminate\Database\Eloquent\Model
{
    $content = Content::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);

    return match ($class) {
        Comment::class => Comment::factory()->create(['content_id' => $content->id]),
        Contributor::class => Contributor::factory()->create(['name' => 'historyaccess_contributor', 'user_id' => Modules\Core\Models\User::factory()->create()->id]),
        Content::class => $content,
        default => tap(new Media, static function (Media $media) use ($content): void {
            $media->forceFill([
                'collection_name' => 'images',
                'name' => 'image',
                'file_name' => 'history-original.jpg',
                'mime_type' => 'image/jpeg',
                'disk' => 'public',
                'conversions_disk' => 'public',
                'size' => 10,
                'model_type' => $content->getMorphClass(),
                'model_id' => $content->getKey(),
                'custom_properties' => ['content_hash' => 'secret-hash'],
                'manipulations' => [],
                'generated_conversions' => [],
                'responsive_images' => [],
            ]);
            $media->save();
        }),
    };
}

/**
 * The bearer header of a user whose `api` role holds the select permission of the entity and the given operations.
 *
 * @param  class-string<Illuminate\Database\Eloquent\Model>  $class
 * @param  list<string>  $operations
 * @return array<string, string>
 */
function historyaccess_bearer(string $class, array $operations): array
{
    $role = Role::factory()->create(['name' => 'historyaccess_' . uniqid(), 'guard_name' => 'api']);
    $abilities = [];

    foreach (['select', ...$operations] as $operation) {
        $name = PermissionName::forClass($class, $operation);
        $abilities[] = $name;
        $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api']));
    }

    $user = User::query()->findOrFail(Modules\Core\Models\User::factory()->create()->getKey());
    $user->assignRole($role);

    return ['Authorization' => 'Bearer ' . $user->fresh()->createToken('historyaccess', $abilities)->plainTextToken];
}

function historyaccess_url(string $module, string $entity, int|string $id): string
{
    return sprintf('/api/v1/history/%s/%s?%s', $module, $entity, http_build_query(['id' => $id]));
}

describe('the history route', function (): void {
    it('refuses the anonymous caller as unauthenticated, for a record that exists and for one that does not', function (string $key): void {
        [$class, $module, $entity] = historyaccess_entities()[$key];
        $record = historyaccess_record($class);

        $existing = $this->getJson(historyaccess_url($module, $entity, $record->getKey()));
        $missing = $this->getJson(historyaccess_url($module, $entity, 999999));

        expect($existing->status())->toBe(401)
            ->and($missing->status())->toBe(401)
            ->and($existing->json('data.record'))->toBeNull();
    })->with(['comments', 'contributors', 'contents', 'media']);

    it('refuses a reader who may only select the entity, for a record that exists and for one that does not', function (string $key): void {
        [$class, $module, $entity] = historyaccess_entities()[$key];
        $record = historyaccess_record($class);
        $bearer = historyaccess_bearer($class, []);

        $existing = $this->getJson(historyaccess_url($module, $entity, $record->getKey()), $bearer);
        $missing = $this->getJson(historyaccess_url($module, $entity, 999999), $bearer);

        expect($existing->status())->toBe(403)
            ->and($missing->status())->toBe(403)
            ->and($existing->json('data.record'))->toBeNull();
    })->with(['comments', 'contributors', 'contents', 'media']);

    it('gives the history to an editor holding update or approve', function (string $key, string $operation): void {
        [$class, $module, $entity] = historyaccess_entities()[$key];
        $record = historyaccess_record($class);

        $response = $this->getJson(historyaccess_url($module, $entity, $record->getKey()), historyaccess_bearer($class, [$operation]));

        $response->assertOk();
        expect($response->json('data'))->toHaveKeys(['record', 'history'])
            ->and($response->json('data.record.id'))->toBe($record->getKey());
    })->with(['comments', 'contributors', 'contents', 'media'])->with(['update', 'approve']);

    it('serializes the record through the model: an approver of media gets the projection, not the raw attributes', function (): void {
        $media = historyaccess_record(Media::class);

        $response = $this->getJson(historyaccess_url('core', 'media', $media->getKey()), historyaccess_bearer(Media::class, ['approve']));

        $response->assertOk();
        expect($response->json('data.record'))->not->toHaveKeys(['file_name', 'disk', 'conversions_disk', 'custom_properties'])
            ->and($response->json('data.record.id'))->toBe($media->getKey());
    });

    it('serializes the record through the model: the contributor record keeps its hidden columns out', function (): void {
        $contributor = historyaccess_record(Contributor::class);

        $response = $this->getJson(historyaccess_url('cms', 'contributors', $contributor->getKey()), historyaccess_bearer(Contributor::class, ['update']));

        $response->assertOk();
        expect($response->json('data.record'))->not->toHaveKeys(['shared_components', 'user_id']);
    });

    it('answers the same to a refused reader whether the record is hidden by its ACL or missing', function (): void {
        $record = historyaccess_record(Comment::class);
        $bearer = historyaccess_bearer(Comment::class, []);

        expect($this->getJson(historyaccess_url('cms', 'comments', $record->getKey()), $bearer)->status())
            ->toBe($this->getJson(historyaccess_url('cms', 'comments', 424242), $bearer)->status());
    });
});
