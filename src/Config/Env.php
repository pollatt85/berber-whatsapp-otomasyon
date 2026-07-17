<?php

declare(strict_types=1);

namespace App\Config;

final class Env
{
    private static bool $loaded = false;

    /**
     * .env'den okunan değerlerin istek-yerel önbelleği. Windows Apache (threaded MPM + ZTS)
     * altında getenv()/putenv() PROCESS-global ortam tablosunu kullanır ve thread-safe DEĞİLDİR;
     * eşzamanlı istekler tabloyu bozup rastgele değişkeni "missing" gösterir (aralıklı 401/500).
     * Değerleri bu diziden okuyarak paylaşımlı tabloya olan bağımlılığı ve yarışı kaldırıyoruz.
     * Statikler her istekte sıfırlanır → load() her istekte dosyadan yeniden doldurur.
     */
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (self::$loaded || !is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Okumalar buradan yapılır (yarışa bağışık).
            self::$vars[$key] = $value;
            // putenv, getenv bekleyen 3. parti kod için best-effort korunur (bizim okumamız $vars'tan).
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        // Önce istek-yerel önbellek (thread race'e bağışık), sonra gerçek ortam.
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }

        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }

        return $value;
    }
}
