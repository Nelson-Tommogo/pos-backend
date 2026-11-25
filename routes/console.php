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
        'password' => $this->option('demo') ? 'required' : ['required', 'string', 'min:12', 'regex:/\\A[^\\x00]{1,72}\\z/'],
    ])->validate();
    User::create(['name' => 'Platform Administrator', 'email' => $email, 'password' => $password, 'role' => 'platform_admin']);
    $this->info('Platform administrator created.');

    return 0;
})->purpose('Create a platform administrator');

Artisan::command('pos:reconcile-subscriptions', function (): int {
    $daraja = app(\App\Billing\Daraja::class);
    if (! $daraja->configured()) {
        return 0;
    }

    \Illuminate\Support\Facades\DB::table('subscription_payment_attempts')
        ->where('state', 'starting')->where('created_at', '<', now()->subMinutes(2))
        ->update(['state' => 'review']);

    $attempts = \Illuminate\Support\Facades\DB::table('subscription_payment_attempts')
        ->where('state', 'pending')
        ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinute()))
        ->orderByRaw('checked_at ASC NULLS FIRST')->orderBy('id')->limit(10)->get();
    $payments = app(\App\Billing\SubscriptionPayments::class);
    foreach ($attempts as $attempt) {
        $payments->reconcile($attempt);
    }
    $this->info("Checked {$attempts->count()} subscription payment attempts.");

    return 0;
})->purpose('Reconcile pending subscription STK payments against M-Pesa');

\Illuminate\Support\Facades\Schedule::command('pos:reconcile-subscriptions')->everyMinute()->withoutOverlapping(10);
