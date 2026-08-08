<?php

namespace App\Services;

use App\Models\Device;
use App\Models\InventoryActionChallenge;
use App\Models\User;
use App\Notifications\InventoryActionApprovalPinNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

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

        // Send only after the challenge has been durably stored. The clear PIN
        // exists only in memory while the mail is being generated.
        $this->sendPin(new InventoryActionApprovalPinNotification(
            $pin,
            $this->labelFor($action),
            $targetLabel ?: trim("{$device->brand} {$device->model} ({$device->serial_number})"),
            $expiresAt,
        ));

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
            if ($locked->consumed_at || $locked->invalidated_at || $locked->expires_at?->isPast()) {
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

        $this->sendPin(new InventoryActionApprovalPinNotification(
            $pin,
            $this->labelFor($challenge->action),
            $targetLabel ?: trim("{$device->brand} {$device->model} ({$device->serial_number})"),
            $expiresAt,
        ));

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

            return ['verified' => true, 'result' => $result];
        });
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

    private function sendPin(InventoryActionApprovalPinNotification $notification): void
    {
        Notification::route('mail', $this->approvalEmail())->notify($notification);
    }

    private function generatePin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<int, int>|null $deviceIds
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
            }
        }
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
