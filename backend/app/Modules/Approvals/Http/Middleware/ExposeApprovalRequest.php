<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Middleware;

use App\Modules\Approvals\Contracts\ApprovalGate;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Signale au client qu'une opération a été soumise à approbation (meta.approval_request_id, en-tête). */
final class ExposeApprovalRequest
{
    public function __construct(private readonly ApprovalGate $approvals) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->approvals->reset();
        $response = $next($request);
        $id = $this->approvals->lastRequestId();

        if ($id !== null && $response instanceof JsonResponse && $response->isSuccessful()) {
            /** @var array<string, mixed> $payload */
            $payload = $response->getData(true);
            $payload['meta'] = ['approval_request_id' => $id] + (array) ($payload['meta'] ?? []);
            $response->setData($payload);
            $response->headers->set('X-Approval-Request', $id);
        }

        return $response;
    }
}
