<?php

namespace App\Notifications;

use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CoAuthorInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Submission $submission
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Co-author invitation: {$this->submission->title}")
            ->line("You have been added as a co-author on the submission \"{$this->submission->title}\".")
            ->line('To claim authorship, register an account with the email address you received this invitation at.')
            ->action('Register', route('register'));
    }
}
