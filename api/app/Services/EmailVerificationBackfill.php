<?php

namespace App\Services;

use App\Models\User;

/**
 * Marks every currently-unverified account as email-verified.
 *
 * Exists to grandfather accounts that registered while email verification was not
 * enforced. `AuthController::register()` never sets `email_verified_at` — it only
 * emails the link — so those accounts are unverified, and attaching the
 * `verified` middleware without this step would lock them out of their own data.
 *
 * Only ever fills nulls: an existing verification timestamp is never overwritten
 * and nothing is ever cleared, so the operation is idempotent and safe to re-run
 * (which matters, because it must be run again immediately before enforcement is
 * switched on — accounts created in the meantime are genuinely new).
 */
class EmailVerificationBackfill
{
    /**
     * @return int the number of accounts marked verified
     */
    public function run(): int
    {
        return User::query()
            ->whereNull('email_verified_at')
            ->update([
                // The moment the system decided to treat these accounts as
                // verified. Deliberately not `created_at` (which would imply the
                // address was verified at sign-up, which it was not) and
                // deliberately not touching `updated_at` — a bulk backfill should
                // not look like every user edited their profile.
                'email_verified_at' => now(),
            ]);
    }
}
