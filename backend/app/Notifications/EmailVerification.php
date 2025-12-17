<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerification extends Notification
{
    use Queueable;

    public $otp;
    public $email;
    public $verificationLink;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $otp, string $email = null, string $verificationLink = null)
    {
        $this->otp = $otp;
        $this->email = $email;
        $this->verificationLink = $verificationLink;
    }

    /**
     * Get the notification's delivery channels.
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
        $message = (new MailMessage)
            ->subject('Verify Your Email Address')
            ->greeting('Hello ' . $notifiable->firstname . '!')
            ->line('Thank you for registering. Please verify your email address to complete your registration.')
            ->line('**Your verification code is:**')
            ->line('# ' . $this->otp)
            ->line('This code will expire in 15 minutes.')
            ->line('---')
            ->line('**To verify your email:**')
            ->line('1. Click the link below (recommended), or')
            ->line('2. Go back to the verification page and enter the 6-digit code above')
            ->line('3. Click "Verify Email"')
            ->line('---');
        
        // Add clickable link with OTP if verification link is provided
        if ($this->verificationLink) {
            $message->action('Verify Email Now', $this->verificationLink);
        }
        
        $message->line('If you did not create an account, no further action is required.');
        
        return $message;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}