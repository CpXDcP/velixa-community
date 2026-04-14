<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function vx_is_authenticated(): bool {
    return !empty($_SESSION['uid']) && !empty($_SESSION['username']) && !empty($_SESSION['role']);
}
function vx_is_mfa_verified(): bool {
    return !empty($_SESSION['mfa_verified']);
}
function vx_require_auth(bool $requireMfa = true): void {
    if (!vx_is_authenticated()) {
        header('Location: login.php');
        exit;
    }
    if ($requireMfa && !vx_is_mfa_verified()) {
        header('Location: verify_mfa.php');
        exit;
    }
}
function vx_require_admin(bool $requireMfa = true): void {
    vx_require_auth($requireMfa);
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}
