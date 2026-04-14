<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/config_ollama.php';

echo "<pre style='background:#111;color:#0f0;padding:20px;font-family:monospace;font-size:13px'>";
echo "=== OLLAMA DEBUG ===\n\n";
echo "OLLAMA_URL  : " . OLLAMA_URL . "\n";
echo "OLLAMA_MODEL: " . OLLAMA_MODEL . "\n\n";

// Test 1 — connexion simple
echo "--- Test 1 : connexion ---\n";
$ch = curl_init('http://127.0.0.1:11434/api/tags');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5]);
$r = curl_exec($ch);
echo "HTTP : " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
echo "Err  : " . (curl_error($ch) ?: 'aucune') . "\n";
echo "Body : " . substr((string)$r, 0, 200) . "\n\n";
curl_close($ch);

// Test 2 — génération courte
echo "--- Test 2 : génération phi3 ---\n";
$payload = json_encode([
    'model'  => OLLAMA_MODEL,
    'prompt' => 'Dis juste OK',
    'stream' => false,
    'options'=> ['num_predict'=>5,'temperature'=>0],
]);
$ch = curl_init(OLLAMA_URL);
curl_setopt_array($ch, [
    CURLOPT_POST=>true,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_TIMEOUT=>30,
]);
$r   = curl_exec($ch);
$err = curl_error($ch);
$cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP : $cod\n";
echo "Err  : " . ($err ?: 'aucune') . "\n";
echo "Raw  : " . substr((string)$r, 0, 500) . "\n";

// Parsing
if ($r) {
    $j = json_decode($r, true);
    echo "JSON response: " . ($j['response'] ?? 'VIDE') . "\n";
}
echo "</pre>";
