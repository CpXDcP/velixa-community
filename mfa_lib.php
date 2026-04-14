<?php
/* === mfa_lib.php minimal stable === */

function mfa_base32_decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    $bits = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $ch = $b32[$i];
        if ($ch === '=') continue;
        $v = strpos($alphabet, $ch);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function mfa_generate_recovery_codes($n = 8) {
  $codes = array();
  for ($i = 0; $i < $n; $i++) {
    $codes[] = strtoupper(bin2hex(random_bytes(4)));
  }
  return $codes;
}


function mfa_random_base32($len = 32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function mfa_totp_at($secret_b32, $timestamp, $digits = 6, $period = 30) {
    $key = mfa_base32_decode($secret_b32);
    $counter = intdiv($timestamp, $period);
    $bin_counter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $bin_counter, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $truncated = ((ord($hash[$offset]) & 0x7f) << 24)
               | ((ord($hash[$offset+1]) & 0xff) << 16)
               | ((ord($hash[$offset+2]) & 0xff) << 8)
               |  (ord($hash[$offset+3]) & 0xff);
    $otp = $truncated % (10 ** $digits);
    return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
}

function mfa_verify_totp($secret_b32, $code, $window = 1, $digits = 6, $period = 30) {
    $code = preg_replace('/\\s+/', '', $code);
    if ($code === '' || !ctype_digit($code)) return false;
    $now = time();
    for ($w = -$window; $w <= $window; $w++) {
        $t = $now + ($w * $period);
        if (hash_equals(mfa_totp_at($secret_b32, $t, $digits, $period), $code)) return true;
    }
    return false;
}

function mfa_build_otpauth_uri($issuer, $account_label, $secret_b32) {
    $issuer_enc = rawurlencode($issuer);
    $label_enc  = rawurlencode($account_label);
    $params = http_build_query(array(
        'secret' => $secret_b32,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30
    ));
    return "otpauth://totp/{$issuer_enc}:{$label_enc}?{$params}";
}

/* Remplace l'ancien Google Charts par qrserver.com (simple et gratuit) */
function mfa_qr_google_url($otpauth_uri, $size = 200) {
    $size = max(120, min(640, (int)$size));
    return 'https://api.qrserver.com/v1/create-qr-code/?size='.$size.'x'.$size.'&data='.urlencode($otpauth_uri);
}


