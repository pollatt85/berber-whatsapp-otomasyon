<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\ServiceRepository;

/**
 * `/services` CRUD (03_Backend_API.md §3.2). Panel JWT gerektirir (front controller'da uygulanır).
 */
final class ServiceController
{
    public function __construct(private ServiceRepository $services)
    {
    }

    public function index(Request $request, string $tenantId): Response
    {
        return Response::json(['data' => $this->services->all($tenantId)]);
    }

    public function store(Request $request, string $tenantId): Response
    {
        $name = trim((string) $request->input('name', ''));
        $duration = (int) $request->input('duration_minutes', 0);
        $price = (string) $request->input('price', '');

        if ($name === '' || $duration <= 0 || $price === '') {
            throw new ApiException('validation_error', 'name, duration_minutes, price are required.', 422);
        }

        return Response::json(['data' => $this->services->create($tenantId, $name, $duration, $price)], 201);
    }

    public function update(Request $request, string $tenantId, string $id): Response
    {
        $service = $this->services->update($tenantId, $id, $request->body);
        if ($service === null) {
            throw new ApiException('not_found', 'Service not found.', 404);
        }

        return Response::json(['data' => $service]);
    }

    public function destroy(Request $request, string $tenantId, string $id): Response
    {
        if (!$this->services->deactivate($tenantId, $id)) {
            throw new ApiException('not_found', 'Service not found.', 404);
        }

        return Response::json(['data' => ['id' => $id, 'active' => false]]);
    }
}
