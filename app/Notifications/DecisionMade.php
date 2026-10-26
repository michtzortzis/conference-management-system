<?php

namespace App\Notifications;

use App\Models\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DecisionMade extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Decision $decision
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Decision Made: {$this->decision->submission->title}")
            ->line("A decision has been made on your submission \"{$this->decision->submission->title}\".")
            ->line("Decision: {$this->decision->decision->value}")
            ->action('View Submission', route('submissions.show', $this->decision->submission));
    }
}
