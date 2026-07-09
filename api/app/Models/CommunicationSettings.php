<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationSettings extends Model
{
    protected $table = 'communications_settings';

    protected $fillable = [
        'admin_emails',
        'sale_notifications_enabled',
        'shift_summary_notifications_enabled',
    ];

    protected function casts(): array
    {
        return [
            'admin_emails' => 'array',
            'sale_notifications_enabled' => 'boolean',
            'shift_summary_notifications_enabled' => 'boolean',
        ];
    }

    /**
     * Get the current communication settings (singleton pattern).
     */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'admin_emails' => [],
            'sale_notifications_enabled' => true,
            'shift_summary_notifications_enabled' => true,
        ]);
    }

    /**
     * Get all admin emails as an array.
     */
    public function getEmailsArray(): array
    {
        return (array) $this->admin_emails;
    }

    /**
     * Add an email to the admin emails list.
     */
    public function addEmail(string $email): void
    {
        $emails = $this->getEmailsArray();

        if (! in_array($email, $emails)) {
            $emails[] = $email;
            $this->admin_emails = $emails;
            $this->save();
        }
    }

    /**
     * Remove an email from the admin emails list.
     */
    public function removeEmail(string $email): void
    {
        $emails = $this->getEmailsArray();
        $filtered = array_filter($emails, fn ($e) => $e !== $email);

        $this->admin_emails = array_values($filtered);
        $this->save();
    }
}
