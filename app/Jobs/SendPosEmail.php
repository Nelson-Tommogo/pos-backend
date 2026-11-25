<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use NumberFormatter;
use Throwable;

class SendPosEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [30, 120];

    private function __construct(private string $kind, private int $record, private ?int $shop)
    {
        $this->onQueue('mail');
    }

    public static function account(User $user): void
    {
        self::schedule(new self('account', $user->id, $user->shop_id));
    }

    public static function customer(int $shop, int $customer): void
    {
        self::schedule(new self('customer', $customer, $shop));
    }

    public static function receipt(int $shop, int $sale): void
    {
        self::schedule(new self('receipt', $sale, $shop));
    }

    private static function schedule(self $job): void
    {
        $queue = config('queue.connections.'.config('queue.default'));
        if (($queue['driver'] ?? null) === 'database'
            && ($queue['connection'] ?? config('database.default')) === config('database.default')) {
            // The pending job and its business record commit in the same database transaction.
            Bus::dispatch($job->beforeCommit());

            return;
        }

        DB::afterCommit(function () use ($job): void {
            try {
                Bus::dispatch($job);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function handle(): void
    {
        match ($this->kind) {
            'account' => $this->accountEmail(),
            'customer' => $this->customerEmail(),
            'receipt' => $this->receiptEmail(),
        };
    }

    private function accountEmail(): void
    {
        DB::transaction(function (): void {
            $user = User::where('shop_id', $this->shop)->lockForUpdate()->find($this->record);
            if ($user !== null) {
                Password::sendResetLink(['email' => $user->email], function (User $recipient, string $token): void {
                    $recipient->sendAccountSetupNotification($token);
                });
            }
        });
    }

    private function customerEmail(): void
    {
        $customer = DB::table('customers')->where('shop_id', $this->shop)->where('id', $this->record)->first();
        if ($customer?->email === null) {
            return;
        }
        $shop = DB::table('shops')->where('id', $this->shop)->value('name');
        Mail::raw(
            "Hello {$customer->name},\n\nYour customer record has been created with {$shop}.\n\nThank you.",
            fn ($message) => $message->to($customer->email)->subject('Welcome to your shop'),
        );
    }

    private function receiptEmail(): void
    {
        $sale = DB::table('sales')->where('shop_id', $this->shop)->where('id', $this->record)->first();
        if ($sale === null) {
            return;
        }
        $recipient = json_decode($sale->customer ?? 'null')?->email;
        if ($recipient === null) {
            return;
        }
        $shop = json_decode($sale->branding)->name ?? 'Northstar POS';
        $lines = [$shop, "Receipt #{$sale->id}", ''];
        foreach (json_decode($sale->items) as $item) {
            $amount = $this->formatMoney($item->total_minor, $sale->currency);
            $lines[] = "{$item->quantity} × {$item->name}: {$amount}";
        }
        $lines[] = 'Subtotal: '.$this->formatMoney($sale->subtotal_minor, $sale->currency);
        $lines[] = 'Tax: '.$this->formatMoney($sale->tax_minor, $sale->currency);
        $lines[] = 'Total: '.$this->formatMoney($sale->total_minor, $sale->currency);
        $lines[] = "\nThank you for your purchase.";
        Mail::raw(
            implode("\n", $lines),
            fn ($message) => $message->to($recipient)->subject("Receipt #{$sale->id}"),
        );
    }

    private function formatMoney(int $minor, string $currency): string
    {
        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter('en_KE', NumberFormatter::CURRENCY);
            $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);
            $digits = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
            $formatted = $formatter->formatCurrency($minor / (10 ** $digits), $currency);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return "{$currency} {$minor} (minor units)";
    }
}
