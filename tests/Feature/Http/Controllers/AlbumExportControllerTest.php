<?php

use App\Jobs\GenerateAlbumZip;
use App\Models\AuditLog;
use App\Models\EventConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::create(['name' => 'manage-images']);

    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

// --- Store (request export) ---

test('authorized user can request album export', function () {
    Queue::fake();

    $user = User::factory()->create();
    $user->givePermissionTo('manage-images');
    $eventConfig = EventConfiguration::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('album-export.store', $eventConfig));

    $response->assertRedirect();
    $response->assertSessionHas('status');

    Queue::assertPushed(GenerateAlbumZip::class, function ($job) use ($eventConfig, $user) {
        return $job->eventConfigurationId === $eventConfig->id
            && $job->userId === $user->id;
    });
});

test('unauthorized user cannot request album export', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('album-export.store', $eventConfig));

    $response->assertForbidden();
});

test('guest cannot request album export', function () {
    $eventConfig = EventConfiguration::factory()->create();

    $response = $this->post(route('album-export.store', $eventConfig));

    $response->assertRedirect(route('login'));
});

// --- Download ---

test('authorized user can download existing zip', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $user->givePermissionTo('manage-images');
    $eventConfig = EventConfiguration::factory()->create();

    $filename = 'album-'.time().'.zip';
    $path = "exports/gallery/{$eventConfig->id}/{$filename}";
    Storage::disk('local')->put($path, 'fake-zip-content');

    $response = $this->actingAs($user)
        ->get(route('album-export.download', [
            'eventConfiguration' => $eventConfig,
            'filename' => $filename,
        ]));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/zip');
});

test('download returns 404 for missing zip', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-images');
    $eventConfig = EventConfiguration::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('album-export.download', [
            'eventConfiguration' => $eventConfig,
            'filename' => 'album-99999.zip',
        ]));

    $response->assertNotFound();
});

test('unauthorized user cannot download zip', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('album-export.download', [
            'eventConfiguration' => $eventConfig,
            'filename' => 'album-12345.zip',
        ]));

    $response->assertForbidden();
});

test('download rejects path traversal in filename', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-images');
    $eventConfig = EventConfiguration::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('album-export.download', [
            'eventConfiguration' => $eventConfig,
            'filename' => '../../../etc/passwd',
        ]));

    $response->assertNotFound();
});

describe('audit logging', function () {
    test('requesting album export logs to audit log', function () {
        Queue::fake();

        $user = User::factory()->create();
        $user->givePermissionTo('manage-images');
        $eventConfig = EventConfiguration::factory()->create();

        $this->actingAs($user)
            ->post(route('album-export.store', $eventConfig));

        $auditLog = AuditLog::where('action', 'album.export.requested')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($user->id);
        expect($auditLog->auditable_type)->toBe(EventConfiguration::class);
        expect($auditLog->auditable_id)->toBe($eventConfig->id);
    });

    test('downloading album export logs to audit log', function () {
        Storage::fake('local');

        $user = User::factory()->create();
        $user->givePermissionTo('manage-images');
        $eventConfig = EventConfiguration::factory()->create();

        $path = "exports/gallery/{$eventConfig->id}/album-{$eventConfig->id}.zip";
        Storage::disk('local')->put($path, 'fake zip content');

        $this->actingAs($user)
            ->get(route('album-export.download', [$eventConfig, "album-{$eventConfig->id}.zip"]));

        $auditLog = AuditLog::where('action', 'album.export.downloaded')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($user->id);
        expect($auditLog->new_values['filename'])->toBe("album-{$eventConfig->id}.zip");
    });
});
