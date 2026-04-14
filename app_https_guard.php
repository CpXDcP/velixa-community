<?php
// --- Velixa HTTPS Guard (app-level) ---
// Objectif: Forcer HTTPS, gérer proxys/reverse-proxys, et activer headers/cookies sûrs.
// Place ce fichier en tout premier dans l'exécution (voir Étape 2).

if (PHP_SAPI === 'cli') { return; } // Ne rien faire en CLI

// Liste des proxys "de confiance" qui mettent X-Forwarded-Proto / Forwarded
// Ajoute l'IP de ton reverse-proxy si tu en as un (ex.: "10.0.0.10")
$TRUSTED_PROXIES = ['127.0.0.1', '::1'];

// Détection HTTPS (multi-environnements)
$isHttps = false;
if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
    $isHttps = true;
} elseif (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
    $isHttps = true;
} elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    $isHttps = true;
} else {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $viaTrustedProxy = in_array($remote, $TRUSTED_PROXIES, true);
    $xfp = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $fwd = strtolower($_SERVER['HTTP_FORWARDED'] ?? '');
    if ($viaTrustedProxy && ($xfp === 'https' || preg_match('/proto=https/', $fwd))) {
        $isHttps = true;
    }
}

// Si pas en HTTPS: redirection 308 (préserve POST) vers la même URL en https
if (!$isHttps) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $target = 'https://' . $host . $uri;

    // Aide à corriger le "mixed content" dès la 1re visite
    header('Content-Security-Policy: upgrade-insecure-requests');

    // 308 = Permanent Redirect (conserve méthode et corps de la requête)
    header('Location: ' . $target, true, 308);
    exit;
}

// Ici on est en HTTPS: activer durcissements
header('Strict-Transport-Security: max-age=31536000; includeSubDomains'); // HSTS
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Cookies sécurisés (sessions & cookies maison)
@ini_set('session.cookie_secure','1');
@ini_set('session.cookie_httponly','1');
@ini_set('session.use_only_cookies','1');
@ini_set('session.cookie_samesite','Lax');

// Helper URL https (optionnel): génère des URLs absolues en https
if (!function_exists('velixa_url')) {
    function velixa_url(string $path = '/'): string {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return 'https://' . $host . '/' . ltrim($path, '/');
    }
}
