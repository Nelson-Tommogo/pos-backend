<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

Artisan::command('pos:platform-admin {email} {--demo}', function (): int {
    $email = strtolower($this->argument('email'));
    if ($this->option('demo') && (! app()->environment('local', 'testing') || $email !== 'alex@northstar.store')) {
        $this->error('Demo credentials are available only for alex@northstar.store in local or testing environments.');

        return 1;
    }
    $password = $this->option('demo') ? 'password123' : $this->secret('Platform administrator password');
    validator(['email' => $email, 'password' => $password], [
        'email' => 'required|email|max:255|unique:users',
        'password' => $this->option('demo') ? 'required' : 'required|string|min:12|max:72',
    ])->validate();
    User::create(['name' => 'Platform Administrator', 'email' => $email, 'password' => $password, 'role' => 'platform_admin']);
    $this->info('Platform administrator created.');

    return 0;
})->purpose('Create a platform administrator');
