<?php
declare(strict_types=1);

function vx_secure_store_master_key_path(): string {
    $dir = dirname(__DIR__) . '/data/secure';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/master.key';
}
function vx_secure_store_master_key(): string {
    $env = getenv('VELIXA_KMS_KEY');
    if (is_string($env) && strlen($env) >= 32) return substr(hash('sha256', $env, true), 0, 32);
    $path = vx_secure_store_master_key_path();
    if (file_exists($path)) {
        $k = file_get_contents($path);
        if ($k !== false && strlen($k) === 32) return $k;
    }
    $k = random_bytes(32);
    file_put_contents($path, $k, LOCK_EX);
    @chmod($path, 0600);
    return $k;
}
function vx_secure_store_encrypt(string $plain): string {
    $iv = random_bytes(12);
    $key = vx_secure_store_master_key();
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode(json_encode(['iv'=>base64_encode($iv),'ct'=>base64_encode($ct),'tg'=>base64_encode($tag)], JSON_UNESCAPED_SLASHES));
}
function vx_secure_store_decrypt(string $blob): ?string {
    $data = json_decode(base64_decode($blob) ?: '', true);
    if (!is_array($data)) return null;
    $iv = base64_decode((string)($data['iv'] ?? ''), true);
    $ct = base64_decode((string)($data['ct'] ?? ''), true);
    $tg = base64_decode((string)($data['tg'] ?? ''), true);
    if ($iv === false || $ct === false || $tg === false) return null;
    $plain = openssl_decrypt($ct, 'aes-256-gcm', vx_secure_store_master_key(), OPENSSL_RAW_DATA, $iv, $tg);
    return $plain === false ? null : $plain;
}
