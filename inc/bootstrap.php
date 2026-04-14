<?php
declare(strict_types=1);

// Supprimer les notices PHP (session_start doublons, etc.)
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

if (!defined('VELIXA_BOOTSTRAP')) {
    define('VELIXA_BOOTSTRAP', true);

    function vx_session_boot(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => True,
            'samesite' => 'Lax',
        ]);
        session_start();
        if (!isset($_SESSION['_vx_init'])) {
            $_SESSION['_vx_init'] = time();
            $_SESSION['_vx_last_seen'] = time();
            $_SESSION['_vx_ua'] = substr(hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 32);
        }
        $now = time();
        $idle = 1800;
        if (!empty($_SESSION['_vx_last_seen']) && ($now - (int)$_SESSION['_vx_last_seen']) > $idle) {
            session_unset();
            session_destroy();
            session_start();
        }
        $_SESSION['_vx_last_seen'] = $now;
        $currentUa = substr(hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 32);
        if (!empty($_SESSION['_vx_ua']) && !hash_equals((string)$_SESSION['_vx_ua'], $currentUa)) {
            session_unset();
            session_destroy();
            session_start();
        }
    }

    function vx_regenerate_session(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            vx_session_boot();
        }
        session_regenerate_id(true);
        $_SESSION['_vx_last_seen'] = time();
    }

    function vx_csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            vx_session_boot();
        }
        if (empty($_SESSION['_vx_csrf'])) {
            $_SESSION['_vx_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_vx_csrf'];
    }

    function vx_csrf_field(): string {
        return '<input type="hidden" name="_vx_csrf" value="'.htmlspecialchars(vx_csrf_token(), ENT_QUOTES, 'UTF-8').'">';
    }

    function vx_verify_csrf_or_die(): void {
        if (PHP_SAPI === 'cli') return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
        $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($ctype, 'application/json')) return;
        $token = (string)($_POST['_vx_csrf'] ?? '');
        $expected = (string)($_SESSION['_vx_csrf'] ?? '');
        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'CSRF validation failed';
            exit;
        }
    }

    function vx_h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function vx_anonymize_value(string $value, string $type='generic'): string {
        $value = trim($value);
        if ($value === '') return '';
        if ($type === 'email' || filter_var($value, FILTER_VALIDATE_EMAIL)) {
            [$l,$d] = array_pad(explode('@',$value,2),2,'');
            $lmask = substr($l,0,1) . '***';
            return $d ? $lmask.'@'.$d : $lmask;
        }
        return substr(hash('sha256', $value), 0, 12);
    }

    function vx_recursive_anonymize($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k=>$v) {
                $lk = strtolower((string)$k);
                if (in_array($lk, ['username','user','uid','email','ip','prompt','response','body','content','raw_response','raw_prompt','filename','file'], true)) {
                    if (is_scalar($v)) $out[$k] = vx_anonymize_value((string)$v, in_array($lk,['email'],true)?'email':'generic');
                    else $out[$k] = '[redacted]';
                } else {
                    $out[$k] = vx_recursive_anonymize($v);
                }
            }
            return $out;
        }
        if (is_string($value) && strlen($value) > 180) {
            return substr($value, 0, 40).'…[redacted]';
        }
        return $value;
    }

    vx_session_boot();
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($script, ['egress_fetch.php'], true)) {
        vx_verify_csrf_or_die();
    }
}
