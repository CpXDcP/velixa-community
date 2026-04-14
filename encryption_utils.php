<?php
/**
 * encryption_utils.php — NEUTRALISÉ (v7-fix)
 * L'ancienne version utilisait une clé AES hardcodée + IV statique (dangereux).
 * Utilisez vx_secure_store_encrypt() / vx_secure_store_decrypt() dans inc/secure_store.php.
 */
if (!function_exists('crypter_prompt')) {
    function crypter_prompt(string $prompt): string {
        throw new \RuntimeException('[VELIXA] crypter_prompt() est déprécié. Utilisez vx_secure_store_encrypt().');
    }
}
if (!function_exists('decrypter_prompt')) {
    function decrypter_prompt(string $enc): string {
        throw new \RuntimeException('[VELIXA] decrypter_prompt() est déprécié. Utilisez vx_secure_store_decrypt().');
    }
}
