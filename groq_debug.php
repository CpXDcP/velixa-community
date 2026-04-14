<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

echo "<pre style='background:#111;color:#0f0;padding:20px;font-family:monospace;font-size:13px'>";
echo "=== GROQ DEBUG v2 ===\n\n";

// 1. Lire providers.json brut
$provFile    = __DIR__ . '/config/providers.json';
$secretsFile = __DIR__ . '/inc/secrets_providers.json';
$masterFile  = __DIR__ . '/inc/.provider_master.key';

$cfg     = json_decode(file_get_contents($provFile), true) ?: [];
$secrets = json_decode(file_get_contents($secretsFile), true) ?: [];

echo "Mode providers : " . ($cfg['mode'] ?? '(absent)') . "\n";
echo "Clés dans secrets : " . implode(', ', array_keys($secrets)) . "\n\n";

// 2. Trouver la config Groq manuellement
$groqBloc = $cfg['global']['providers']['groq'] ?? $cfg['per_metier']['IT']['providers']['groq'] ?? null;
echo "Bloc Groq dans providers.json : " . ($groqBloc ? "OUI" : "NON") . "\n";
if ($groqBloc) {
    echo "  enabled  : " . ($groqBloc['enabled'] ? 'true' : 'false') . "\n";
    echo "  key_ref  : " . ($groqBloc['key_ref'] ?? '(absent)') . "\n";
    $keyRef = $groqBloc['key_ref'] ?? '';
    $rawKey = $secrets[$keyRef] ?? '';
    echo "  Clé brute trouvée : " . ($rawKey ? substr($rawKey,0,8).'...' : 'VIDE ❌') . "\n";
}

// 3. Déchiffrement si master key présente
echo "\nMaster key : " . (file_exists($masterFile) ? "OUI" : "NON") . "\n";
if (file_exists($masterFile) && !empty($rawKey)) {
    $master = file_get_contents($masterFile);
    $decoded = json_decode(base64_decode($rawKey), true);
    if (is_array($decoded) && isset($decoded['iv'], $decoded['data'])) {
        $decrypted = openssl_decrypt(
            base64_decode($decoded['data']),
            'AES-256-CBC',
            $master,
            OPENSSL_RAW_DATA,
            base64_decode($decoded['iv'])
        );
        echo "Clé déchiffrée : " . ($decrypted ? substr($decrypted,0,8).'... ✅' : 'ECHEC déchiffrement ❌') . "\n";
        $apiKey = $decrypted ?: '';
    } else {
        $apiKey = $rawKey; // Clé en clair
        echo "Clé en clair : " . ($apiKey ? substr($apiKey,0,8).'... ✅' : 'VIDE ❌') . "\n";
    }
} else {
    $apiKey = $rawKey ?? '';
}

// 4. Test appel Groq
echo "\n--- Test appel Groq ---\n";
if (empty($apiKey)) {
    echo "❌ Clé vide — vérifiez la configuration dans Admin > Providers\n";
} else {
    $payload = json_encode([
        'model'      => 'llama-3.1-8b-instant',
        'messages'   => [['role'=>'user','content'=>'Say just OK']],
        'max_tokens' => 5,
    ]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer '.$apiKey
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP  : $code\n";
    echo "Erreur: " . ($err ?: 'aucune') . "\n";
    echo "Body  : " . substr((string)$raw, 0, 400) . "\n";
}
echo "</pre>";
