<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject requests from suspended accounts.
 *
 * AdminUserController::suspend already revokes the account's tokens, so this
 * middleware is a second line of defence. It also covers the cases that token
 * revocation does not: a token minted before suspension, and any future code
 * path that sets suspended_at without revoking.
 */
class EnsureUserIsNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Unauthenticated requests are handled by the auth middleware; nothing to do here.
        if (! $user) {
            return $next($request);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact support for assistance.',
            ], 403);
        }

        return $next($request);
    }
}
