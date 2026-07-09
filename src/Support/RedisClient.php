<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal RESP (Redis Serialization Protocol) client, ham TCP soketiyle (`fsockopen`).
 * Composer bu makinede kurulu değil (bkz. PROJECT_MEMORY.md) ve `ext-redis` de yüklü değil,
 * bu yüzden `predis/predis` yerine yalnızca RateLimiter'ın ihtiyaç duyduğu birkaç komutu
 * (INCR/EXPIRE/TTL/GET/DEL/PING) konuşan bağımlılıksız bir istemci.
 */
final class RedisClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutSeconds = 0.5
    ) {
    }

    /** @throws \RuntimeException bağlantı kurulamazsa */
    private function connection()
    {
        if ($this->socket !== null) {
            return $this->socket;
        }

        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeoutSeconds);
        if ($socket === false) {
            throw new \RuntimeException("Redis connection failed ({$this->host}:{$this->port}): {$errstr}");
        }
        stream_set_timeout($socket, 0, (int) ($this->timeoutSeconds * 1_000_000));
        $this->socket = $socket;

        return $socket;
    }

    /**
     * @param list<string|int> $args ör. ['INCR', 'ai_rate:tenant123']
     * @return mixed simple string/bulk string -> string, integer -> int, array -> list, nil -> null
     */
    public function command(array $args): mixed
    {
        $socket = $this->connection();

        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string) $arg;
            $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        if (fwrite($socket, $payload) === false) {
            $this->close();
            throw new \RuntimeException('Redis write failed.');
        }

        return $this->readReply($socket);
    }

    private function readReply($socket): mixed
    {
        $line = fgets($socket);
        if ($line === false) {
            $this->close();
            throw new \RuntimeException('Redis read failed (connection closed).');
        }
        $line = rtrim($line, "\r\n");
        $type = $line[0] ?? '';
        $value = substr($line, 1);

        return match ($type) {
            '+' => $value,
            '-' => throw new \RuntimeException("Redis error: {$value}"),
            ':' => (int) $value,
            '$' => $this->readBulkString($socket, (int) $value),
            '*' => $this->readArray($socket, (int) $value),
            default => throw new \RuntimeException("Redis: unexpected reply type '{$type}'."),
        };
    }

    private function readBulkString($socket, int $length): ?string
    {
        if ($length === -1) {
            return null;
        }
        $data = '';
        $remaining = $length + 2; // trailing \r\n
        while ($remaining > 0) {
            $chunk = fread($socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $this->close();
                throw new \RuntimeException('Redis read failed (bulk string).');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return substr($data, 0, $length);
    }

    /** @return list<mixed>|null */
    private function readArray($socket, int $count): ?array
    {
        if ($count === -1) {
            return null;
        }
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->readReply($socket);
        }

        return $items;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
