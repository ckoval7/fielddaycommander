<?php

use App\Http\Middleware\CheckSystemSetupComplete;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Run the middleware directly against a request for the given path.
 */
function runSetupMiddleware(string $path): Response
{
    return (new CheckSystemSetupComplete)->handle(
        Request::create($path),
        fn () => response('passed through'),
    );
}

function markSetupComplete(): void
{
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true'],
    );
}

describe('before setup is complete', function () {
    test('redirects application routes to the setup wizard', function (string $path) {
        $response = runSetupMiddleware($path);

        expect($response->isRedirect(url('/setup/welcome')))->toBeTrue();
    })->with(['/', '/logbook', '/settings', '/events/1']);

    test('allows the setup wizard routes through', function () {
        expect(runSetupMiddleware('/setup/welcome')->getContent())->toBe('passed through');
        expect(runSetupMiddleware('/setup/branding')->getContent())->toBe('passed through');
    });

    test('allows invitation routes through', function () {
        expect(runSetupMiddleware('/register/invite/some-token')->getContent())->toBe('passed through');
    });

    test('treats a non-"true" setup_completed value as incomplete', function () {
        DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'false']);

        expect(runSetupMiddleware('/')->isRedirect(url('/setup/welcome')))->toBeTrue();
    });

    test('the full web stack sends guests to the setup wizard', function () {
        $this->get(route('logbook.index'))->assertRedirect('/setup/welcome');
    });

    test('the full web stack sends authenticated users to the setup wizard', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('profile'))
            ->assertRedirect('/setup/welcome');
    });
});

describe('after setup is complete', function () {
    beforeEach(fn () => markSetupComplete());

    test('passes application routes through', function () {
        expect(runSetupMiddleware('/logbook')->getContent())->toBe('passed through');
    });

    test('redirects setup wizard routes back home', function () {
        expect(runSetupMiddleware('/setup/welcome')->isRedirect(url('/')))->toBeTrue();
    });

    test('still allows invitation routes through', function () {
        expect(runSetupMiddleware('/register/invite/some-token')->getContent())->toBe('passed through');
    });
});
