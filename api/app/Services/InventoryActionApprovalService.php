<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\InventoryActionChallenge;
use App\Models\User;
use App\Notifications\InventoryActionApprovalPinNotification;
use App\Notifications\InventoryActionPinAvailableNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

class InventoryActionApprovalService
{
    public const EXPIRY_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Create a one-time challenge and deliver the PIN to the protected
     * administrator mailbox. The PIN is never persisted or included in the
     * response.
     */
    public function create(User $user, string $action, array $payload, Device $device, ?string $targetLabel = null): InventoryActionChallenge
    {
        $this->ensureVerifiedEmail($user);
        $payload = $this->normalizePayload($payload);
        $pin = $this->generatePin();
        $expiresAt = now()->addMinutes(self::EXPIRY_MINUTES);

        $challenge = DB::transaction(function () use ($user, $action, $payload, $pin, $expiresAt) {
            $this->invalidatePendingChallenges($user, $action, $this->payloadDeviceIds($payload));

            return InventoryActionChallenge::create([
                'requested_by_id' => $user->id,
                'action' => $action,
                'payload' => $payload,
                'payload_hash' => $this->payloadHash($payload),
                'pin_hash' => Hash::make($pin),
                'attempts' => 0,
                'max_attempts' => self::MAX_ATTEMPTS,
                'expires_at' => $expiresAt,
                'last_sent_at' => now(),
            ]);
        });

        $resolvedTargetLabel = $targetLabel ?: trim("{$device->brand} {$device->model} ({$device->serial_number})");
        $this->storePin($challenge, $pin);
        $this->deliverPin($challenge, $user, $pin, $this->labelFor($action), $resolvedTargetLabel, $expiresAt);

        return $challenge;
    }

    public function resend(InventoryActionChallenge $challenge, User $user, Device $device, ?string $targetLabel = null): InventoryActionChallenge
    {
        $this->ensureVerifiedEmail($user);

        $pin = $this->generatePin();
        $expiresAt = now()->addMinutes(self::EXPIRY_MINUTES);

        $challenge = DB::transaction(function () use ($challenge, $user, $pin, $expiresAt) {
            $locked = InventoryActionChallenge::query()->lockForUpdate()->findOrFail($challenge->id);

            if ($locked->requested_by_id !== $user->id) {
                abort(403, 'Only the initiating administrator can resend this PIN.');
            }
            if ($locked->consumed_at || $locked->invalidated_at) {
                throw ValidationException::withMessages(['challenge' => ['This challenge is no longer active. Request a new PIN.']]);
            }
            if ($locked->last_sent_at && $locked->last_sent_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
                throw ValidationException::withMessages(['challenge' => ['Please wait before requesting another PIN.']]);
            }

            $locked->update([
                'pin_hash' => Hash::make($pin),
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'last_sent_at' => now(),
            ]);

            return $locked->fresh();
        });

        $resolvedTargetLabel = $targetLabel ?: trim("{$device->brand} {$device->model} ({$device->serial_number})");
        $this->updateInAppNotificationStatus($challenge, 'superseded');
        $this->storePin($challenge, $pin);
        $this->deliverPin($challenge, $user, $pin, $this->labelFor($challenge->action), $resolvedTargetLabel, $expiresAt);

        return $challenge;
    }

    /**
     * Verify the PIN and execute the server-stored action payload atomically.
     * The callback must not accept browser-supplied action parameters.
     *
     * @return array{verified: bool, result?: mixed, message?: string, attempts_remaining?: int}
     */
    public function verify(InventoryActionChallenge $challenge, User $user, string $pin, callable $execute): array
    {
        return DB::transaction(function () use ($challenge, $user, $pin, $execute) {
            $locked = InventoryActionChallenge::query()->lockForUpdate()->findOrFail($challenge->id);

            if ($locked->requested_by_id !== $user->id) {
                abort(403, 'Only the initiating administrator can verify this PIN.');
            }

            if ($locked->consumed_at || $locked->invalidated_at) {
                return ['verified' => false, 'message' => 'This PIN has already been used or invalidated.'];
            }

            if ($locked->expires_at?->isPast()) {
                return ['verified' => false, 'message' => 'This PIN has expired. Request a new PIN.'];
            }

            if ($locked->attempts >= $locked->max_attempts) {
                return ['verified' => false, 'message' => 'Too many failed PIN attempts. Request a new PIN.'];
            }

            if (! Hash::check($pin, $locked->pin_hash)) {
                $locked->increment('attempts');
                $remaining = max(0, $locked->max_attempts - $locked->attempts);

                return [
                    'verified' => false,
                    'message' => $remaining > 0 ? 'Invalid PIN.' : 'Too many failed PIN attempts. Request a new PIN.',
                    'attempts_remaining' => $remaining,
                ];
            }

            // Validate the stored payload before executing it. Verification
            // never accepts a replacement payload from the browser.
            if (! hash_equals($locked->payload_hash, $this->payloadHash($locked->payload ?? []))) {
                return ['verified' => false, 'message' => 'This challenge payload is invalid. Request a new PIN.'];
            }

            $result = $execute($locked->payload, $locked);

            $locked->update([
                'consumed_at' => now(),
                'verified_at' => now(),
            ]);

            $this->forgetPin($locked);
            $this->updateInAppNotificationStatus($locked, 'completed', true);

            return ['verified' => true, 'result' => $result];
        });
    }

    public function reveal(InventoryActionChallenge $challenge, User $user, string $password): string
    {
        $locked = InventoryActionChallenge::query()->lockForUpdate()->findOrFail($challenge->id);

        if ($locked->requested_by_id !== $user->id) {
            abort(403, 'Only the initiating administrator can reveal this PIN.');
        }

        if (! Hash::check($password, $user->password)) {
            $this->logReveal($locked, $user, false, 'invalid_password');
            throw ValidationException::withMessages(['password' => ['The password is incorrect.']]);
        }

        if ($locked->consumed_at || $locked->invalidated_at) {
            throw ValidationException::withMessages(['challenge' => ['This challenge is no longer active. Request a new PIN.']]);
        }
        if ($locked->expires_at?->isPast()) {
            $this->forgetPin($locked);
            $this->updateInAppNotificationStatus($locked, 'expired');
            throw ValidationException::withMessages(['challenge' => ['This PIN has expired. Request a new PIN.']]);
        }
        if ($locked->attempts >= $locked->max_attempts) {
            throw ValidationException::withMessages(['challenge' => ['Too many failed PIN attempts. Request a new PIN.']]);
        }

        $encryptedPin = Cache::get($this->pinCacheKey($locked));
        if (! is_string($encryptedPin) || $encryptedPin === '') {
            throw ValidationException::withMessages(['challenge' => ['The in-app PIN is no longer available. Resend the PIN to continue.']]);
        }

        try {
            $pin = Crypt::decryptString($encryptedPin);
        } catch (Throwable $exception) {
            Log::warning('Unable to decrypt an inventory action PIN.', ['challenge_id' => $locked->id]);
            throw ValidationException::withMessages(['challenge' => ['The in-app PIN is no longer available. Resend the PIN to continue.']]);
        }

        if (! preg_match('/^\d{6}$/', $pin) || ! Hash::check($pin, $locked->pin_hash)) {
            $this->forgetPin($locked);
            throw ValidationException::withMessages(['challenge' => ['The in-app PIN is no longer available. Resend the PIN to continue.']]);
        }

        $this->updateInAppNotificationStatus($locked, 'active', true);
        $this->logReveal($locked, $user, true, 'password_confirmed');

        return $pin;
    }

    public function maskedEmail(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return 'your verified email';
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 1);

        return $visible.str_repeat('*', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }

    public function approvalEmail(): string
    {
        return trim((string) config('inventory.approval_email', 'elijahpchitedze@gmail.com'))
            ?: 'elijahpchitedze@gmail.com';
    }

    public function labelFor(string $action): string
    {
        return match ($action) {
            InventoryActionChallenge::ACTION_ADMINISTRATIVE_SALE => 'administrative sale',
            InventoryActionChallenge::ACTION_DEVICE_DELETION => 'device deletion',
            default => 'inventory action',
        };
    }

    private function ensureVerifiedEmail(User $user): void
    {
        if (! $user->email || ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['A verified email address is required for PIN-protected inventory actions.'],
            ]);
        }
    }

    private function deliverPin(
        InventoryActionChallenge $challenge,
        User $user,
        string $pin,
        string $actionLabel,
        string $targetLabel,
        \DateTimeInterface $expiresAt,
    ): void {
        $inAppDelivered = false;
        $emailDelivered = false;

        try {
            $user->notify(new InventoryActionPinAvailableNotification($challenge, $actionLabel, $targetLabel));
            $inAppDelivered = true;
        } catch (Throwable $exception) {
            Log::error('Unable to create an in-app inventory PIN notification.', [
                'challenge_id' => $challenge->id,
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);
        }

        try {
            Notification::route('mail', $this->approvalEmail())->notify(new InventoryActionApprovalPinNotification(
                $pin,
                $actionLabel,
                $targetLabel,
                $expiresAt,
            ));
            $emailDelivered = true;
        } catch (Throwable $exception) {
            Log::error('Unable to send an inventory PIN email.', [
                'challenge_id' => $challenge->id,
                'exception' => $exception::class,
            ]);
        }

        if (! $inAppDelivered && ! $emailDelivered) {
            $challenge->update(['invalidated_at' => now()]);
            $this->forgetPin($challenge);
            abort(503, 'The verification PIN could not be delivered. Please try again.');
        }
    }

    private function storePin(InventoryActionChallenge $challenge, string $pin): void
    {
        Cache::put($this->pinCacheKey($challenge), Crypt::encryptString($pin), $challenge->expires_at);
    }

    private function forgetPin(InventoryActionChallenge $challenge): void
    {
        Cache::forget($this->pinCacheKey($challenge));
    }

    private function pinCacheKey(InventoryActionChallenge $challenge): string
    {
        return "inventory-action-pin:{$challenge->id}";
    }

    private function generatePin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, int>|null  $deviceIds
     */
    private function invalidatePendingChallenges(User $user, string $action, ?array $deviceIds): void
    {
        $deviceIds = $deviceIds === null
            ? null
            : array_values(array_unique(array_map('intval', $deviceIds)));

        $pending = InventoryActionChallenge::query()
            ->where('requested_by_id', $user->id)
            ->where('action', $action)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->get();

        foreach ($pending as $challenge) {
            $payload = $challenge->payload ?? [];
            $pendingDeviceIds = $this->payloadDeviceIds($payload) ?? [];
            if ($deviceIds === null || array_intersect($deviceIds, $pendingDeviceIds) !== []) {
                $challenge->update(['invalidated_at' => now()]);
                $this->forgetPin($challenge);
                $this->updateInAppNotificationStatus($challenge, 'invalidated');
            }
        }
    }

    private function updateInAppNotificationStatus(InventoryActionChallenge $challenge, string $status, bool $markAsRead = false): void
    {
        $notifications = $challenge->requestedBy()
            ->first()?->notifications()
            ->where('data->type', 'inventory_action_pin')
            ->where('data->challenge_id', $challenge->id)
            ->get() ?? collect();

        foreach ($notifications as $notification) {
            $data = $notification->data;
            $data['status'] = $status;
            $notification->forceFill([
                'data' => $data,
                'read_at' => $markAsRead ? ($notification->read_at ?? now()) : $notification->read_at,
            ])->save();
        }
    }

    private function logReveal(InventoryActionChallenge $challenge, User $user, bool $successful, string $reason): void
    {
        ActivityLog::create([
            'log_name' => 'inventory_security',
            'description' => $successful ? 'Inventory action PIN revealed in app' : 'Inventory action PIN reveal rejected',
            'subject_type' => InventoryActionChallenge::class,
            'subject_id' => $challenge->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
            'event' => $successful ? 'inventory_pin_revealed' : 'inventory_pin_reveal_failed',
            'properties' => [
                'action' => $challenge->action,
                'successful' => $successful,
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Return the devices affected by a challenge payload. Supporting both
     * shapes keeps existing single-device challenges backwards compatible.
     *
     * @return array<int, int>|null
     */
    private function payloadDeviceIds(array $payload): ?array
    {
        if (array_key_exists('device_ids', $payload)) {
            return array_values(array_unique(array_map('intval', (array) $payload['device_ids'])));
        }

        if (array_key_exists('device_id', $payload)) {
            return [(int) $payload['device_id']];
        }

        return null;
    }

    private function normalizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_float($value)) {
                $payload[$key] = number_format($value, 2, '.', '');
            }
        }

        ksort($payload);

        return $payload;
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($this->sortRecursively($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }
        ksort($value);

        return $value;
    }
}
