<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject requests from accounts whose email address is not verified.
 *
 * DELIBERATELY NOT ATTACHED to any route group yet. Enforcement is a product
 * decision, not a code one: `AuthController::register()` never sets
 * `email_verified_at`, so every account created through the API is unverified
 * until the user clicks the emailed link — and because this middleware was never
 * enforced, nothing ever pressured anyone to click it. Switching it on without
 * grandfathering those accounts would lock an unknown share of existing users
 * out of their devices and theft reports.
 *
 * Before attaching this to a route group, run:
 *
 *     php artisan users:grandfather-email-verification
 *
 * That command verifies every currently-unverified account so only genuinely new
 * sign-ups are subject to verification. It is safe to re-run.
 *
 * Registered under the conventional `verified` alias (overriding the framework's
 * default) so that enabling enforcement later is a one-line change to a route
 * group, and so the structured error below is what clients receive.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                // A stable machine-readable code so the client can distinguish
                // "verify your email" from a plain permission denial, and offer
                // to resend rather than just showing a dead end.
                'code' => 'EMAIL_NOT_VERIFIED',
                'message' => 'Your email address is not verified. Check your inbox for the verification link, or request a new one.',
                'resend_endpoint' => route('auth.verification.resend'),
                'status_endpoint' => route('auth.verification.status'),
            ], 403);
        }

        return $next($request);
    }
}
