<?php

namespace App\Http;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuditMutation
{
    public function handle(Request $request, Closure $next, string $action, ?string $subject = null): Response
    {
        try {
            return DB::transaction(function () use ($request, $next, $action, $subject): Response {
                $response = $next($request);
                $failure = $response->exception ?? null;
                if ($failure instanceof DeadlockException && $failure->getPrevious() !== null) {
                    throw $failure->getPrevious();
                }
                if (! $response->isSuccessful()) {
                    throw new HttpResponseException($response);
                }
                if ($request->attributes->get('audit_replay', false)
                    || ($response->getStatusCode() === 200 && in_array($action, ['sale.checkout', 'payment.create', 'credit.repay', 'subscription.invoice.create'], true))) {
                    return $response;
                }

                $data = $response instanceof JsonResponse ? $response->getData(true) : [];
                $resource = $subject ?? $request->route('resource');
                $workspace = in_array($action, ['shop.create', 'shop.administrator.create'], true) ? $data['shop_id'] : null;
                $id = $data['id'] ?? $workspace ?? $data['product_id'] ?? $request->attributes->get('audit_subject_id') ?? match ($resource) {
                    'shop' => $request->user()->shop_id,
                    'user' => $request->user()->id,
                    default => null,
                };
                $branch = $data['branch_id'] ?? $request->attributes->get('audit_branch_id');
                $details = $request->attributes->get('audit_details', []);
                if (in_array($action, ['user.create', 'user.update', 'user.invite', 'shop.administrator.create'], true)) {
                    $details['assigned_role'] = $data['role'];
                }
                if ($action === 'credit.repay') {
                    $details['customer_id'] = $data['customer_id'];
                }
                if ($action === 'records.import') {
                    $details['imported'] = $data['imported'];
                }

                AuditTrail::record($request->user(), $action, $resource, $id, $branch, $details, $workspace);

                return $response;
            }, 3);
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        }
    }
}
