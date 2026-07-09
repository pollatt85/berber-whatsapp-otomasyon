<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * `message_log` tablosu (02_Database_Design.md §3.12). Tenant-scoped bağlantı üzerinden çalışır.
 */
final class MessageLogRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM message_log WHERE tenant_id = :tenant_id AND idempotency_key = :key'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'key' => $idempotencyKey]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * 24 saatlik konuşma penceresi kontrolü (05_WhatsApp_Integration.md §4).
     */
    public function lastInboundAt(string $tenantId, string $customerId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT max(sent_at) AS last_inbound FROM message_log
             WHERE tenant_id = :tenant_id AND customer_id = :customer_id AND direction = 'inbound'"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'customer_id' => $customerId]);
        $row = $stmt->fetch();

        return $row['last_inbound'] ?? null;
    }

    /**
     * Panel mesaj logu görünümü (06§7): yön/durum/tarih aralığı/müşteri filtresi.
     * Sayfalama henüz yok — en yeni 200 satırla sınırlı (06§7 pagination tanımlamıyor).
     */
    public function listByFilters(
        string $tenantId,
        ?string $direction,
        ?string $status,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $customerId = null
    ): array {
        $sql = 'SELECT m.id, m.customer_id, m.appointment_id, m.direction, m.template_id,
                       m.content, m.status, m.meta_error_code, m.sent_at,
                       c.name AS customer_name, c.whatsapp_number AS customer_whatsapp
                FROM message_log m
                LEFT JOIN customers c ON c.id = m.customer_id
                WHERE m.tenant_id = :tenant_id';
        $params = ['tenant_id' => $tenantId];

        if ($direction !== null) {
            $sql .= ' AND m.direction = :direction';
            $params['direction'] = $direction;
        }
        if ($status !== null) {
            $sql .= ' AND m.status = :status';
            $params['status'] = $status;
        }
        if ($dateFrom !== null) {
            $sql .= ' AND m.sent_at >= :date_from::date';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $sql .= " AND m.sent_at < :date_to::date + interval '1 day'";
            $params['date_to'] = $dateTo;
        }
        if ($customerId !== null) {
            $sql .= ' AND m.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        $sql .= ' ORDER BY m.sent_at DESC LIMIT 200';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * AI sistem promptuna enjekte edilecek son N mesaj (07_AI_Module.md §4 madde 4) —
     * yalnızca o müşteri-tenant çifti (PII izolasyonu). Eskiden yeniye sıralı döner.
     *
     * @return array<int, array{direction:string, content:mixed, sent_at:string}>
     */
    public function recentForCustomer(string $tenantId, string $customerId, int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            'SELECT direction, content, sent_at FROM message_log
             WHERE tenant_id = :tenant_id AND customer_id = :customer_id
             ORDER BY sent_at DESC LIMIT :limit'
        );
        $stmt->bindValue('tenant_id', $tenantId);
        $stmt->bindValue('customer_id', $customerId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_reverse($stmt->fetchAll());
    }

    public function insert(
        string $tenantId,
        ?string $customerId,
        ?string $appointmentId,
        string $direction,
        ?string $templateId,
        array $content,
        string $status,
        ?string $metaErrorCode,
        ?string $idempotencyKey
    ): array {
        $stmt = $this->db->prepare(
            'INSERT INTO message_log
                (tenant_id, customer_id, appointment_id, direction, template_id, content, status, meta_error_code, idempotency_key)
             VALUES
                (:tenant_id, :customer_id, :appointment_id, :direction, :template_id, :content, :status, :meta_error_code, :idempotency_key)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'appointment_id' => $appointmentId,
            'direction' => $direction,
            'template_id' => $templateId,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'meta_error_code' => $metaErrorCode,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $stmt->fetch();
    }
}
