<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification
{
    use Queueable;

    protected $token;

    /**
     * Create a new notification instance.
     */
    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable)
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'https://demie-agum.github.io/GymBooking/frontend'), '/');
        $resetUrl = $frontendUrl . '/forgotpassword.html?token=' . $this->token . '&email=' . urlencode($notifiable->email);
        return (new MailMessage)
            ->subject('Password Reset Request - Gym Booking System')
            ->greeting('Hello ' . $notifiable->firstname . '!')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $resetUrl)
            ->line('This password reset link will expire in 24 hours.')
            ->line('If you did not request a password reset, please ignore this email and your password will remain unchanged.')
            ->line('For security reasons, never share this link with anyone.')
            ->salutation('Best regards, The Gym Booking Team');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable)
    {
        return [
            'token' => $this->token,
            'email' => $notifiable->email
        ];
    }
}