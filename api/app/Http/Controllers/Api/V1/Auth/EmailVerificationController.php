<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Email Verification
 *
 * APIs for email verification - resend verification email and verify email address.
 */
class EmailVerificationController extends BaseController
{
    /**
     * Resend email verification notification.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->error('Email is already verified.', 400);
        }

        $user->sendEmailVerificationNotification();

        return $this->success(null, 'Verification email has been resent.');
    }

    /**
     * Verify email address.
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->error('Invalid verification link.', 400);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->success(null, 'Email is already verified.');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->success(null, 'Email verified successfully.');
    }

    /**
     * Check verification status.
     */
    public function status(Request $request): JsonResponse
    {
        return $this->success([
            'verified' => $request->user()->hasVerifiedEmail(),
            'email' => $request->user()->email,
        ]);
    }
}
