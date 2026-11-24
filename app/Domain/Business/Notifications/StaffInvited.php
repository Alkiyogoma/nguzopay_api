<?php

namespace App\Domain\Business\Notifications;

use App\Domain\Business\Enums\StaffRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly string $businessName,
        public readonly string $inviterName,
        public readonly StaffRole $role,
        public readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Join {$this->businessName} on NguzoPay")
            ->greeting('Hello,')
            ->line("{$this->inviterName} has invited you to join {$this->businessName} on NguzoPay as {$this->role->label()}.")
            ->action('Accept invitation', $this->url)
            ->line('This invitation expires in '.config('nguzopay.invitations.ttl_days').' days. If you were not expecting it, ignore this email.');
    }
}
