<?php

namespace App\Console\Commands;

use App\Services\EmailVerificationBackfill;
use Illuminate\Console\Command;

class GrandfatherEmailVerification extends Command
{
    protected $signature = 'users:grandfather-email-verification';

    protected $description = 'Mark every currently-unverified account as email-verified, so enforcement of the `verified` middleware does not lock out existing users.';

    public function handle(EmailVerificationBackfill $backfill): int
    {
        $verified = $backfill->run();

        if ($verified === 0) {
            $this->info('No unverified accounts found. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Marked {$verified} previously-unverified account(s) as email-verified.");
        $this->line('Accounts created from now on will need to verify their email if enforcement is enabled.');

        return self::SUCCESS;
    }
}
