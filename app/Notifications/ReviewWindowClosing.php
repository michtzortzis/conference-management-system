<?php

namespace App\Notifications;

use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewWindowClosing extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Submission $submission,
        public string $closeAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Review Window Closing: {$this->submission->title}")
            ->line("The review window for \"{$this->submission->title}\" closes on {$this->closeAt}.")
            ->line('Please submit your review before the review window closes.')
            ->action('View Submission', route('submissions.show', $this->submission));
    }
}
