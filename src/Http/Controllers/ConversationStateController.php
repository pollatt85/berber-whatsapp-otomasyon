<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\ConversationStateRepository;
use App\Repository\CustomerRepository;

/**
 * `GET /conversation-state?tenant_id=&customer_id=` (04_n8n_Workflows.md §2, §7). n8n servis
 * kanalı (HMAC) — her adımda "müşteri hangi aşamada" sorusu için. Satır henüz yoksa (ilk temas)
 * varsayılan `idle` durumu döner, satır oluşturulmaz (yalnızca okuma; state ilk mesajı işleyen
 * akışta yazılır).
 */
final class ConversationStateController
{
    public function __construct(
        private ConversationStateRepository $states,
        private CustomerRepository $customers
    ) {
    }

    public function show(Request $request): Response
    {
        $tenantId = (string) $request->input('tenant_id', '');
        $customerId = (string) $request->input('customer_id', '');

        if ($tenantId === '' || $customerId === '') {
            throw new ApiException('validation_error', 'tenant_id and customer_id are required.', 422);
        }

        $customer = $this->customers->find($tenantId, $customerId);
        if ($customer === null) {
            throw new ApiException('not_found', 'Customer not found.', 404);
        }

        $state = $this->states->find($tenantId, $customerId) ?? [
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'step' => 'idle',
            'context' => '{}',
        ];

        return Response::json(['data' => $state]);
    }

    /**
     * `PATCH /conversation-state` — n8n her adımda müşterinin durumunu ilerletir
     * (04_n8n_Workflows.md §2: "state ilerledikçe UPDATE edilir").
     */
    public function update(Request $request): Response
    {
        $tenantId = (string) $request->input('tenant_id', '');
        $customerId = (string) $request->input('customer_id', '');
        $step = (string) $request->input('step', '');
        $context = (array) $request->input('context', []);

        if ($tenantId === '' || $customerId === '' || $step === '') {
            throw new ApiException('validation_error', 'tenant_id, customer_id and step are required.', 422);
        }

        if ($this->customers->find($tenantId, $customerId) === null) {
            throw new ApiException('not_found', 'Customer not found.', 404);
        }

        return Response::json(['data' => $this->states->upsert($tenantId, $customerId, $step, $context)]);
    }
}
