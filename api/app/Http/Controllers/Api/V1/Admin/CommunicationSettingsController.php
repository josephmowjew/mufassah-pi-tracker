<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Admin\UpdateCommunicationSettingsRequest;
use App\Models\CommunicationSettings;
use Illuminate\Http\JsonResponse;

class CommunicationSettingsController extends BaseController
{
    /**
     * Get current communication settings.
     *
     * @response 200 scenario="Success" {"success":true,"data":{"id":1,"admin_emails":["admin@example.com"],"sale_notifications_enabled":true,"shift_summary_notifications_enabled":true}}
     */
    public function index(): JsonResponse
    {
        $settings = CommunicationSettings::current();

        return $this->success($settings);
    }

    /**
     * Update communication settings.
     *
     * @response 200 scenario="Success" {"success":true,"message":"Communication settings updated successfully.","data":{"id":1,"admin_emails":["admin@example.com"],"sale_notifications_enabled":true,"shift_summary_notifications_enabled":true}}
     */
    public function update(UpdateCommunicationSettingsRequest $request): JsonResponse
    {
        $settings = CommunicationSettings::current();

        \Log::info('Updating communication settings', [
            'admin_emails' => $request->admin_emails,
            'sale_notifications_enabled' => $request->sale_notifications_enabled,
            'shift_summary_notifications_enabled' => $request->shift_summary_notifications_enabled,
        ]);

        $settings->update([
            'admin_emails' => $request->admin_emails ?? [],
            'sale_notifications_enabled' => $request->sale_notifications_enabled ?? true,
            'shift_summary_notifications_enabled' => $request->shift_summary_notifications_enabled ?? true,
        ]);

        \Log::info('Settings updated', ['settings' => $settings->toArray()]);

        return $this->success($settings, 'Communication settings updated successfully.');
    }

    /**
     * Send a test email to all configured admin emails.
     *
     * @response 200 scenario="Success" {"success":true,"message":"Test email sent successfully to 2 recipient(s)."}
     */
    public function sendTestEmail(): JsonResponse
    {
        $settings = CommunicationSettings::current();
        $emails = $settings->getEmailsArray();

        if (empty($emails)) {
            return $this->error('No admin emails configured. Please add at least one email address.', 400);
        }

        // Send test notification
        foreach ($emails as $email) {
            \Illuminate\Support\Facades\Notification::route('mail', $email)
                ->notify(new \App\Notifications\TestEmailNotification);
        }

        return $this->success(null, 'Test email sent successfully to '.count($emails).' recipient(s).');
    }
}
