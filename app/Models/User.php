<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $guarded = ['id'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['password' => 'hashed', 'preferences' => 'array'];

    public function sendPasswordResetNotification($token): void
    {
        $this->notifyPasswordLink($token, setup: false);
    }

    public function sendAccountSetupNotification(string $token): void
    {
        $this->notifyPasswordLink($token, setup: true);
    }

    private function notifyPasswordLink(string $token, bool $setup): void
    {
        $url = rtrim(config('pos.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $this->email,
        ]);

        $this->notify(new class($token, $url, $setup) extends \Illuminate\Auth\Notifications\ResetPassword {
            public function __construct($token, private string $url, private bool $setup)
            {
                parent::__construct($token);
            }

            protected function buildMailMessage($url)
            {
                if (! $this->setup) {
                    return parent::buildMailMessage($url);
                }

                $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

                return (new MailMessage)
                    ->subject('Set up your Northstar POS account')
                    ->line('An account has been created for you in Northstar POS.')
                    ->action('Set your password', $url)
                    ->line("This link expires in {$minutes} minutes. You can request a new link from the sign-in page.");
            }

            protected function resetUrl($notifiable): string
            {
                return $this->url;
            }
        });
    }
}
