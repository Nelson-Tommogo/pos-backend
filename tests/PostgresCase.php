<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

abstract class PostgresCase extends TestCase
{
    protected const PASSWORD = 'Test-only-password-123!';
    private string $schema;
    private bool $schemaCreated = false;
    private ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = 'pos_test_'.bin2hex(random_bytes(8));
        $this->application();
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        $this->schemaCreated = true;
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
    }

    protected function tearDown(): void
    {
        if ($this->schemaCreated) {
            $this->application();
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            DB::disconnect();
            File::deleteDirectory(sys_get_temp_dir().'/'.$this->schema);
        }
        parent::tearDown();
    }

    protected function application(): Application
    {
        $database = getenv('POS_TEST_DATABASE');
        if (! is_string($database) || ! preg_match('/\A[a-zA-Z0-9_]+_test\z/', $database)) {
            throw new RuntimeException('Set POS_TEST_DATABASE to a dedicated PostgreSQL database ending in _test.');
        }

        if ($this->app?->resolved('db')) {
            $this->app->make('db')->disconnect('pgsql');
        }
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->make(ConsoleKernel::class)->bootstrap();
        restore_error_handler();
        restore_exception_handler();
        $this->app = $app;
        if ($app->configurationIsCached()) {
            throw new RuntimeException('Run php artisan config:clear before executing the integration tests.');
        }
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'app.debug' => false,
            'cache.default' => 'file',
            'cache.stores.file.path' => sys_get_temp_dir().'/'.$this->schema,
            'cache.stores.file.lock_path' => sys_get_temp_dir().'/'.$this->schema,
            'cors.allowed_origins' => ['https://register.example.test'],
            'database.default' => 'pgsql',
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql.database' => $database,
            'database.connections.pgsql.search_path' => $this->schema,
            'hashing.bcrypt.rounds' => 4,
            'mail.default' => 'array',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'pgsql',
            'queue.failed.database' => 'pgsql',
            'billing.mpesa' => [
                'environment' => 'sandbox',
                'consumer_key' => 'stub-key',
                'consumer_secret' => 'stub-secret',
                'shortcode' => '174379',
                'passkey' => 'stub-passkey',
                'callback_url' => 'https://example.test/api/v1/billing/mpesa/callback',
                'transaction_type' => 'CustomerPayBillOnline',
            ],
        ]);
        Http::preventStrayRequests();

        return $app;
    }

    protected function request(string $method, string $path, array $data = [], ?string $token = null, array $files = [], array $headers = []): Response
    {
        $kernel = $this->application()->make(HttpKernel::class);
        $this->fakeProvider();
        $headers += ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $content = null;
        if ($files === []) {
            $headers['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($data, JSON_THROW_ON_ERROR);
        }
        $request = Request::create('/api/v1/'.$path, $method, [], [], $files, $headers, $content);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

        return $response;
    }

    protected function fakeProvider(): void {}

    protected function api(int $status, string $method, string $path, array $data = [], ?string $token = null, array $files = []): array
    {
        $response = $this->request($method, $path, $data, $token, $files);
        $this->assertSame($status, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    protected function platform(): string
    {
        $user = User::create(['email' => 'platform@example.test', 'name' => 'Platform', 'password' => self::PASSWORD, 'role' => 'platform_admin']);

        return $user->createToken('test')->plainTextToken;
    }

    protected function shop(string $platform, string $label = 'shop'): array
    {
        $email = $label.'@example.test';
        $shop = $this->api(201, 'POST', 'shops', ['shop_name' => $label, 'name' => 'Administrator', 'email' => $email, 'password' => self::PASSWORD], $platform);
        $token = $this->api(200, 'POST', 'auth/login', ['email' => $email, 'password' => self::PASSWORD])['token'];

        return [$token, $shop['shop_id'], $email];
    }

    protected function branch(string $admin, string $name = 'Main'): int
    {
        return $this->api(201, 'POST', 'branches', ['name' => $name], $admin)['id'];
    }

    protected function cashier(string $admin, int $branch): array
    {
        $email = 'cashier@example.test';
        $user = $this->api(201, 'POST', 'users', [
            'name' => 'Cashier', 'email' => $email, 'password' => self::PASSWORD, 'role' => 'cashier', 'branch_id' => $branch,
        ], $admin);
        $token = $this->api(200, 'POST', 'auth/login', ['email' => $email, 'password' => self::PASSWORD])['token'];

        return [$token, $user['id']];
    }

    protected function key(): string
    {
        return Str::uuid()->toString();
    }
}
