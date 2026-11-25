<?php

namespace Tests;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

final class ApiSecurityTest extends PostgresCase
{
    public function testTenantRoleAndBranchBoundaries(): void
    {
        $platform = $this->platform();
        [$admin, $shopId] = $this->shop($platform);
        [$other] = $this->shop($platform, 'other');
        $branch = $this->branch($admin);
        $second = $this->branch($admin, 'Second');
        [$cashier] = $this->cashier($admin, $branch);

        $this->api(401, 'GET', 'me');
        $this->api(403, 'GET', 'products', token: $platform);
        $this->api(403, 'GET', 'analytics', token: $cashier);
        $this->api(403, 'GET', 'audit-events', token: $cashier);
        $this->api(403, 'GET', "inventory/$second/1", token: $cashier);
        $this->api(404, 'GET', "branches/$branch", token: $other);
        $this->api(404, 'GET', 'receipts/'.str_repeat('9', 30), token: $admin);

        $payload = [
            'name' => 'Second admin', 'email' => 'second-admin@example.test', 'password' => self::PASSWORD,
            'role' => 'platform_admin', 'shop_id' => -1, 'branch_id' => $second,
        ];
        $this->api(403, 'POST', "shops/$shopId/administrators", $payload, $admin);
        $this->api(422, 'POST', 'users', $payload, $admin);
        $created = $this->api(201, 'POST', "shops/$shopId/administrators", $payload, $platform);
        $this->assertSame('administrator', $created['role']);
        $this->assertSame($shopId, $created['shop_id']);
        $this->assertNull($created['branch_id']);
        $this->assertArrayNotHasKey('password', $created);
        $query = 'audit-events?action=shop.administrator.create&subject_id='.$created['id'];
        $this->assertCount(1, $this->api(200, 'GET', $query, token: $admin)['data']);
        $this->assertSame([], $this->api(200, 'GET', $query, token: $other)['data']);
    }

    public function testResetLinksAreSingleUseAndRevokeAccessTokens(): void
    {
        [$token, , $email] = $this->shop($this->platform());
        $user = User::where('email', $email)->firstOrFail();
        $reset = Password::createToken($user);
        $newPassword = 'New-test-password-123!';
        $credentials = [
            'email' => $email, 'token' => $reset, 'password' => $newPassword, 'password_confirmation' => $newPassword,
        ];

        $this->api(200, 'POST', 'auth/reset-password', $credentials);
        $this->api(422, 'POST', 'auth/reset-password', $credentials);
        $this->api(401, 'GET', 'me', token: $token);
        $bad = $this->api(422, 'POST', 'auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        $missing = $this->api(422, 'POST', 'auth/login', ['email' => 'unknown@example.test', 'password' => self::PASSWORD]);
        $this->assertSame($bad, $missing);
        $newToken = $this->api(200, 'POST', 'auth/login', ['email' => $email, 'password' => $newPassword])['token'];
        $this->api(204, 'POST', 'auth/logout', token: $newToken);
        $this->api(401, 'GET', 'me', token: $newToken);
    }

    public function testAuthThrottlingAndCorsOriginAllowlist(): void
    {
        [, , $email] = $this->shop($this->platform());
        $path = 'auth/login';
        $credentials = ['email' => $email, 'password' => 'incorrect-password'];
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $this->api(422, 'POST', $path, $credentials);
        }
        $throttled = $this->request('POST', $path, $credentials);
        $this->assertSame(429, $throttled->getStatusCode());
        $this->assertTrue($throttled->headers->has('Retry-After'));

        $allowed = $this->request('OPTIONS', 'me', headers: [
            'HTTP_ORIGIN' => 'https://register.example.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertSame('https://register.example.test', $allowed->headers->get('Access-Control-Allow-Origin'));
        $denied = $this->request('OPTIONS', 'me', headers: [
            'HTTP_ORIGIN' => 'https://untrusted.example.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertFalse($denied->headers->has('Access-Control-Allow-Origin'));
    }

    public function testImportValidationRollsBackRecordsMailAndAudit(): void
    {
        [$admin] = $this->shop($this->platform());
        [$cashier] = $this->cashier($admin, $this->branch($admin));
        $before = DB::table('jobs')->count();
        $file = tempnam(sys_get_temp_dir(), 'pos_csv_');
        $this->assertIsString($file);
        try {
            file_put_contents($file, "name,email\nValid,valid@example.test\nInvalid,not-an-email\n");
            $upload = ['file' => new UploadedFile($file, 'customers.csv', 'text/csv', null, true)];
            $this->api(403, 'POST', 'customers/import', token: $cashier, files: $upload);
            $this->api(422, 'POST', 'customers/import', token: $admin, files: $upload);
            $this->assertSame(0, DB::table('customers')->count());
            $this->assertSame($before, DB::table('jobs')->count());
            $this->assertSame(0, DB::table('audit_events')->where('action', 'records.import')->count());

            file_put_contents($file, "name,email\nValid,valid@example.test\n");
            $this->api(201, 'POST', 'customers/import', token: $admin, files: $upload);
            $this->assertSame($before + 1, DB::table('jobs')->count());
        } finally {
            unlink($file);
        }
    }

    public function testAuditHistoryCannotBeAlteredThroughOrdinarySql(): void
    {
        [$admin] = $this->shop($this->platform());
        $this->branch($admin);
        $event = DB::table('audit_events')->where('action', 'record.create')->firstOrFail();
        $this->assertSame('branches', $event->subject_type);
        foreach (['UPDATE audit_events SET action = action', 'DELETE FROM audit_events', 'TRUNCATE audit_events'] as $statement) {
            try {
                DB::statement($statement);
                $this->fail('Audit history accepted a mutation.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Audit events are append-only', $exception->getMessage());
            }
        }
    }
}
