<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\CustomerRepository;

/**
 * `/customers` (03_Backend_API.md §3.5). n8n ilk temasta upsert eder.
 */
final class CustomerController
{
    public function __construct(private CustomerRepository $customers)
    {
    }

    /**
     * `whatsapp_number` verilirse n8n'in tek-kayıt araması (geriye uyumlu: {data: obje} + 404),
     * verilmezse panelin liste/arama görünümü (06§6, {data: dizi}).
     */
    public function index(Request $request, string $tenantId): Response
    {
        $whatsappNumber = $request->query['whatsapp_number'] ?? null;
        if ($whatsappNumber !== null) {
            $customer = $this->customers->findByWhatsappNumber($tenantId, (string) $whatsappNumber);
            if ($customer === null) {
                throw new ApiException('not_found', 'Customer not found.', 404);
            }

            return Response::json(['data' => $customer]);
        }

        $search = isset($request->query['search']) ? (string) $request->query['search'] : null;

        return Response::json(['data' => $this->customers->list($tenantId, $search)]);
    }

    public function show(Request $request, string $tenantId, string $id): Response
    {
        $customer = $this->customers->find($tenantId, $id);
        if ($customer === null) {
            throw new ApiException('not_found', 'Customer not found.', 404);
        }

        return Response::json(['data' => $customer]);
    }

    public function store(Request $request, string $tenantId): Response
    {
        $whatsappNumber = (string) $request->input('whatsapp_number', '');
        if ($whatsappNumber === '') {
            throw new ApiException('validation_error', 'whatsapp_number is required.', 422);
        }

        $name = $request->input('name');

        return Response::json(['data' => $this->customers->upsert($tenantId, $whatsappNumber, $name)], 201);
    }

    /**
     * KVKK/GDPR anonimleştirme talebi (06_Admin_Panel.md §6, 09_SaaS_Deployment.md §6 madde 10).
     * Panel UI'ında bu buton yok (06§6) — destek/uyumluluk süreciyle tetiklenir, bu yüzden
     * owner/manager rolüyle sınırlanır.
     */
    public function destroy(Request $request, string $tenantId, string $id, string $role): Response
    {
        if (!in_array($role, ['owner', 'manager'], true)) {
            throw new ApiException('forbidden', 'Role does not permit this action.', 403);
        }

        if (!$this->customers->anonymize($tenantId, $id)) {
            throw new ApiException('not_found', 'Customer not found or already anonymized.', 404);
        }

        return Response::json(['data' => ['id' => $id, 'anonymized' => true]]);
    }
}
