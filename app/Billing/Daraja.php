<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class Daraja
{
    public function configured(): bool
    {
        $config = config('billing.mpesa');

        return in_array($config['environment'], ['sandbox', 'production'], true)
            && filled($config['consumer_key']) && filled($config['consumer_secret'])
            && preg_match('/^[0-9]{5,10}$/D', (string) $config['shortcode'])
            && filled($config['passkey'])
            && in_array($config['transaction_type'], ['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'], true)
            && filter_var($config['callback_url'], FILTER_VALIDATE_URL)
            && parse_url($config['callback_url'], PHP_URL_SCHEME) === 'https';
    }

    public function push(int $invoice, int $amountMinor, string $phone): array
    {
        return $this->post('/mpesa/stkpush/v1/processrequest', $this->credentials() + [
            'TransactionType' => config('billing.mpesa.transaction_type'),
            'Amount' => intdiv($amountMinor, 100),
            'PartyA' => $phone,
            'PartyB' => (string) config('billing.mpesa.shortcode'),
            'PhoneNumber' => $phone,
            'CallBackURL' => config('billing.mpesa.callback_url'),
            'AccountReference' => 'NS'.strtoupper(base_convert((string) $invoice, 10, 36)),
            'TransactionDesc' => 'POS plan',
        ]);
    }

    public function query(string $checkout): array
    {
        return $this->post('/mpesa/stkpushquery/v1/query', $this->credentials() + [
            'CheckoutRequestID' => $checkout,
        ]);
    }

    private function credentials(): array
    {
        $timestamp = CarbonImmutable::now('Africa/Nairobi')->format('YmdHis');
        $shortcode = (string) config('billing.mpesa.shortcode');

        return [
            'BusinessShortCode' => $shortcode,
            'Timestamp' => $timestamp,
            'Password' => base64_encode($shortcode.config('billing.mpesa.passkey').$timestamp),
        ];
    }

    private function post(string $path, array $data): array
    {
        $base = config('billing.mpesa.environment') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
        try {
            $token = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->withBasicAuth(config('billing.mpesa.consumer_key'), config('billing.mpesa.consumer_secret'))
                ->get($base.'/oauth/v1/generate', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new DarajaNotSubmitted('M-Pesa authentication could not be reached.');
        }
        $access = $token->successful() ? $token->json('access_token') : null;
        if (! is_string($access) || $access === '') {
            throw new DarajaNotSubmitted('M-Pesa authentication failed.');
        }
        $result = Http::connectTimeout(3)->timeout(12)->withoutRedirecting()->withToken($access)->acceptJson()
            ->post($base.$path, $data);
        if (! $result->successful() || ! is_array($body = $result->json())) {
            throw new RuntimeException('M-Pesa returned an unavailable or invalid response.');
        }

        return $body;
    }
}
