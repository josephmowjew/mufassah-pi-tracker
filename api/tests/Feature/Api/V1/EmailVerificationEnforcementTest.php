<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * S-06 — email verification enforcement.
 *
 * The middleware is registered but deliberately NOT attached to any route group.
 * `AuthController::register()` never sets `email_verified_at`, so every account
 * created through the API is unverified, and enabling enforcement without first
 * running `users:grandfather-email-verification` would lock existing users out.
 */
it('rejects an unverified account with a structured error code', function () {
    $user = User::factory()->unverified()->create();

    $request = Request::create('/api/v1/devices', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureEmailIsVerified)->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);

    $payload = json_decode($response->getContent(), true);

    expect($payload['success'])->toBeFalse();
    expect($payload['code'])->toBe('EMAIL_NOT_VERIFIED');
    // The client needs a way out of the dead end, not just a refusal.
    expect($payload['resend_endpoint'])->toContain('/auth/email/resend');
    expect($payload['status_endpoint'])->toContain('/auth/email/status');
});

it('lets a verified account through', function () {
    $user = User::factory()->create();

    $request = Request::create('/api/v1/devices', 'GET');
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureEmailIsVerified)->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

it('does not interfere with unauthenticated requests', function () {
    // No resolved user: authentication is someone else's job, and this middleware
    // must not turn a 401 into a 403.
    $request = Request::create('/api/v1/devices', 'GET');

    $response = (new EnsureEmailIsVerified)->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

it('registers the verified alias without attaching it to any route', function () {
    $router = app('router');

    // Wired under the conventional name so enabling enforcement later is a
    // one-line change to a route group.
    expect($router->getMiddleware()['verified'] ?? null)->toBe(EnsureEmailIsVerified::class);

    // Enforcement is deferred on purpose. If this ever fails, someone attached it
    // without grandfathering existing accounts first — that is a deliberate
    // decision that needs making, not a test to update.
    $routesUsingVerified = collect($router->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('verified', $route->middleware(), true))
        ->map(fn ($route) => $route->uri());

    expect($routesUsingVerified)->toBeEmpty();
});

it('grandfathers existing unverified accounts and leaves verified ones alone', function () {
    $unverified = User::factory()->unverified()->create();
    $verified = User::factory()->create();
    $originalVerifiedAt = $verified->email_verified_at;

    $this->artisan('users:grandfather-email-verification')->assertSuccessful();

    expect($unverified->fresh()->hasVerifiedEmail())->toBeTrue();

    // An existing verification must never be overwritten.
    expect($verified->fresh()->email_verified_at->equalTo($originalVerifiedAt))->toBeTrue();
});

it('can be run more than once without changing the outcome', function () {
    User::factory()->count(3)->unverified()->create();

    $this->artisan('users:grandfather-email-verification')->assertSuccessful();
    $this->artisan('users:grandfather-email-verification')->assertSuccessful();

    expect(User::whereNull('email_verified_at')->count())->toBe(0);
});
