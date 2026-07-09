<?php

declare(strict_types=1);

namespace App\Http;

/**
 * 03_Backend_API.md §6 ortak hata sözleşmesini taşıyan exception.
 * Standart error kodları: tenant_not_found, unauthorized, forbidden, validation_error,
 * slot_taken, not_found, rate_limited, internal_error.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        private string $errorCode,
        string $message,
        private int $status,
        private array $details = []
    ) {
        parent::__construct($message);
    }

    public function toResponse(): Response
    {
        return Response::error($this->errorCode, $this->getMessage(), $this->status, $this->details);
    }
}
