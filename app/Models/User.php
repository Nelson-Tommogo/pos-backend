<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
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
        $url = rtrim(config('pos.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $this->email,
        ]);

        $this->notify(new class($token, $url) extends \Illuminate\Auth\Notifications\ResetPassword {
            public function __construct($token, private string $url)
            {
                parent::__construct($token);
            }

            protected function resetUrl($notifiable): string
            {
                return $this->url;
            }
        });
    }
}
