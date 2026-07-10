<?php

declare(strict_types=1);

namespace App\Support;

/**
 * `tenants.access_token_encrypted` (05_WhatsApp_Integration.md §1, §7) için AES-256-GCM
 * simetrik şifreleme. Anahtar `APP_ENCRYPTION_KEY` (.env, base64 32 byte) — KMS entegrasyonu
 * kapsam dışı (05§1 notu), tek anahtar tüm tenant'lar için ortak.
 */
final class TokenCipher
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plaintext, string $base64Key): string
    {
        $key = base64_decode($base64Key, true);
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Token encryption failed.');
        }

        return $iv . $tag . $ciphertext;
    }

    public static function decrypt(string $encrypted, string $base64Key): string
    {
        $key = base64_decode($base64Key, true);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($encrypted, 0, $ivLength);
        $tag = substr($encrypted, $ivLength, 16);
        $ciphertext = substr($encrypted, $ivLength + 16);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Token decryption failed (tampered or wrong key).');
        }

        return $plaintext;
    }
}
