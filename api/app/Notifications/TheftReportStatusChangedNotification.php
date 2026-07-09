<?php

namespace App\Notifications;

use App\Enums\TheftReportStatus;
use App\Models\TheftReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TheftReportStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TheftReport $theftReport,
        public TheftReportStatus $previousStatus
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $statusMessages = $this->getStatusMessage();

        return (new MailMessage)
            ->subject('Theft Report Update - Case #'.$this->theftReport->report_number)
            ->greeting('Hello, '.$notifiable->name)
            ->line("There's an update on your theft report.")
            ->line("Case Number: {$this->theftReport->report_number}")
            ->line("Previous Status: {$this->previousStatus->value}")
            ->line("New Status: {$this->theftReport->status->value}")
            ->line($statusMessages)
            ->action('View Report', $frontendUrl.'/theft-reports/'.$this->theftReport->id)
            ->salutation('Best regards, Mufasah Electronics Team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'theft_report_status_changed',
            'title' => 'Theft Report Status Updated',
            'message' => "Case #{$this->theftReport->report_number} status changed to {$this->theftReport->status->value}",
            'theft_report_id' => $this->theftReport->id,
            'report_number' => $this->theftReport->report_number,
            'previous_status' => $this->previousStatus->value,
            'new_status' => $this->theftReport->status->value,
            'icon' => 'update',
        ];
    }

    protected function getStatusMessage(): string
    {
        return match ($this->theftReport->status) {
            TheftReportStatus::Investigating => 'Our team is actively investigating your case.',
            TheftReportStatus::Recovered => 'Your device has been recovered! Please contact us for retrieval.',
            TheftReportStatus::Closed => 'Your case has been closed. Contact us if you have questions.',
            TheftReportStatus::FalseAlarm => 'This report has been marked as a false alarm.',
            default => 'Your report status has been updated.',
        };
    }
}
