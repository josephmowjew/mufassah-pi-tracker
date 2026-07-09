<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * @group Password Reset
 *
 * APIs for password reset functionality - request reset link and reset password.
 *
 * @unauthenticated
 */
class PasswordResetController extends BaseController
{
    /**
     * Send password reset link to email.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return $this->success(null, 'Password reset link sent to your email.');
        }

        return $this->error('Unable to send password reset link. Please try again later.', 500);
    }

    /**
     * Reset password with token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                // Revoke all existing tokens for security
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(null, 'Password has been reset successfully. Please login with your new password.');
        }

        return $this->error($this->getResetErrorMessage($status), 400);
    }

    /**
     * Get human-readable error message for password reset status.
     */
    private function getResetErrorMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'This password reset token is invalid or has expired.',
            Password::INVALID_USER => 'We could not find a user with that email address.',
            Password::RESET_THROTTLED => 'Please wait before requesting another password reset.',
            default => 'Unable to reset password. Please try again.',
        };
    }
}
