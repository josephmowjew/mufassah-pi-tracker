<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShiftEndSummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Shift $shift) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // `breaks` is loaded so the break totals do not issue a query per
        // accessor — break_count, break_minutes and worked_minutes each read
        // the same collection.
        $this->shift->loadMissing(['user', 'branch', 'breaks']);

        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $staffName = $this->shift->user?->name ?? 'Unknown Staff';
        $branchName = $this->shift->branch?->name ?? 'Unknown Branch';

        // Calculate shift duration
        $startTime = $this->shift->started_at;
        $endTime = $this->shift->ended_at;
        $duration = $endTime->diffAsCarbonInterval($startTime);

        return (new MailMessage)
            ->subject('Shift Ended: '.$staffName)
            ->greeting('Hello Admin,')
            ->line('A customer service staff member has ended their shift.')
            ->line('**Shift Summary:**')
            ->line("- Staff: {$staffName}")
            ->line("- Branch: {$branchName}")
            ->line("- Started: {$startTime->format('Y-m-d H:i')}")
            ->line("- Ended: {$endTime->format('Y-m-d H:i')}")
            ->line("- Duration: {$duration->forHumans()}")
            ->line('')
            ->line('**Time on the clock:**')
            ->line('- Breaks: '.$this->shift->break_count.' ('.$this->formatMinutes($this->shift->break_minutes).')')
            ->line('- Time worked: '.$this->formatMinutes($this->shift->worked_minutes))
            ->line('')
            ->line('**Performance:**')
            ->line("- Total Sales: {$this->shift->sales_count}")
            ->line('- Revenue: MWK '.number_format($this->shift->revenue, 2))
            ->line('- Cash Sales: MWK '.number_format($this->shift->cash_sales, 2))
            ->action('View Shift Details', $frontendUrl."/admin/shifts/{$this->shift->id}")
            ->line('Thank you for using Mufasah Device Tracking!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'shift_id' => $this->shift->id,
            'staff_name' => $this->shift->user?->name,
            'revenue' => $this->shift->revenue,
            'sales_count' => $this->shift->sales_count,
            'break_count' => $this->shift->break_count,
            'break_minutes' => $this->shift->break_minutes,
            'worked_minutes' => $this->shift->worked_minutes,
        ];
    }

    /**
     * Render a minute count as `8h 30m` for the email body.
     */
    private function formatMinutes(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $total = (int) round($minutes);
        $hours = intdiv($total, 60);
        $remaining = $total % 60;

        if ($hours === 0) {
            return "{$remaining}m";
        }

        return "{$hours}h {$remaining}m";
    }
}
