<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureGates();
    }

    /**
     * Define application gates.
     */
    protected function configureGates(): void
    {
        // What the business paid for a device is competitive information. The
        // same DeviceResource is returned to customers on their own device
        // endpoints and to customer-service staff, so these fields used to reach
        // every viewer regardless of role.
        //
        // Deliberately role-only rather than device-aware: DevicePolicy's branch
        // check goes through branchScopeId(), which queries the database on every
        // call, so a per-device check would be an N+1 across a device list.
        // Branch scope is already enforced by DevicePolicy::view and by the admin
        // controllers' own query scoping, so anyone who can see a device at all
        // is already in the right branch.
        Gate::define('view-device-costs', function (User $user): bool {
            return $user->hasAnyRole(['super_admin', 'store_admin']);
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Login rate limiting: 5 attempts per minute per IP
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->input('email').'|'.$request->ip())
                ->response(function () {
                    return response()->json([
                        'success' => false,
                        'message' => 'Too many login attempts. Please try again in a minute.',
                    ], 429);
                });
        });

        // Registration rate limiting: 3 attempts per minute per IP
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinute(3)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'success' => false,
                        'message' => 'Too many registration attempts. Please try again in a minute.',
                    ], 429);
                });
        });

        // Password reset rate limiting: 3 attempts per minute per IP
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(3)
                ->by($request->input('email').'|'.$request->ip())
                ->response(function () {
                    return response()->json([
                        'success' => false,
                        'message' => 'Too many password reset attempts. Please try again in a minute.',
                    ], 429);
                });
        });

        // General API rate limiting: 60 requests per minute
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
