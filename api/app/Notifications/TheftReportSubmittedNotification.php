<?php

namespace App\Notifications;

use App\Models\TheftReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TheftReportSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TheftReport $theftReport) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $device = $this->theftReport->device;

        return (new MailMessage)
            ->subject('Theft Report Submitted - Case #'.$this->theftReport->report_number)
            ->greeting('Hello, '.$notifiable->name)
            ->line('Your theft report has been submitted successfully.')
            ->line('Report Details:')
            ->line("- Case Number: {$this->theftReport->report_number}")
            ->line("- Device: {$device->name}")
            ->line("- Serial Number: {$device->serial_number}")
            ->line("- Incident Date: {$this->theftReport->incident_date->toLocalFormat('M d, Y')}")
            ->line("- Status: {$this->theftReport->status->value}")
            ->action('View Report', $frontendUrl.'/theft-reports/'.$this->theftReport->id)
            ->line('Our team will review your report and assist in the recovery process.')
            ->line('Please keep your case number for future reference.')
            ->salutation('Best regards, Mufasah Electronics Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'theft_report_submitted',
            'title' => 'Theft Report Submitted',
            'message' => "Theft report #{$this->theftReport->report_number} has been submitted for your device.",
            'theft_report_id' => $this->theftReport->id,
            'report_number' => $this->theftReport->report_number,
            'device_id' => $this->theftReport->device_id,
            'icon' => 'alert',
        ];
    }
}
