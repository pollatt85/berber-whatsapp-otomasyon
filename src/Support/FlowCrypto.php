<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * WhatsApp Flows data-exchange endpoint şifreleme protokolü (Meta resmi dokümantasyonu):
 * istek gövdesi `encrypted_aes_key` (RSA-OAEP-SHA256 ile şifreli 128-bit AES anahtarı) +
 * `encrypted_flow_data` (AES-128-GCM, son 16 byte auth tag) + `initial_vector` içerir.
 * Yanıt aynı AES anahtarıyla ama **bitleri ters çevrilmiş IV** ile şifrelenir (Meta'nın isteği
 * ile aynı IV'yi response'ta tekrar kullanmamak için) ve düz base64 metin olarak dönülür.
 */
final class FlowCrypto
{
    /**
     * @return array{payload: array, aesKey: string, iv: string}
     */
    public static function decrypt(
        string $encryptedFlowDataB64,
        string $encryptedAesKeyB64,
        string $initialVectorB64,
        string $privateKeyPem
    ): array {
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new RuntimeException('WhatsApp Flow private key okunamadı.');
        }

        $encryptedAesKey = base64_decode($encryptedAesKeyB64, true);
        if ($encryptedAesKey === false) {
            throw new RuntimeException('encrypted_aes_key base64 çözülemedi.');
        }

        $aesKey = '';
        $ok = openssl_private_decrypt(
            $encryptedAesKey,
            $aesKey,
            $privateKey,
            OPENSSL_PKCS1_OAEP_PADDING
        );
        if (!$ok) {
            throw new RuntimeException('AES anahtarı RSA-OAEP ile çözülemedi.');
        }

        $iv = base64_decode($initialVectorB64, true);
        $flowDataRaw = base64_decode($encryptedFlowDataB64, true);
        if ($iv === false || $flowDataRaw === false) {
            throw new RuntimeException('initial_vector/encrypted_flow_data base64 çözülemedi.');
        }

        // Son 16 byte GCM auth tag'idir, geri kalanı şifreli gövde.
        $tagLength = 16;
        $ciphertext = substr($flowDataRaw, 0, -$tagLength);
        $tag = substr($flowDataRaw, -$tagLength);

        $plaintext = openssl_decrypt($ciphertext, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Flow verisi AES-128-GCM ile çözülemedi.');
        }

        $payload = json_decode($plaintext, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Çözülen Flow verisi geçerli JSON değil.');
        }

        return ['payload' => $payload, 'aesKey' => $aesKey, 'iv' => $iv];
    }

    public static function encrypt(array $responsePayload, string $aesKey, string $iv): string
    {
        $flippedIv = ~$iv;

        $json = json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tag = '';
        $ciphertext = openssl_encrypt($json, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $flippedIv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Flow yanıtı AES-128-GCM ile şifrelenemedi.');
        }

        return base64_encode($ciphertext . $tag);
    }
}
