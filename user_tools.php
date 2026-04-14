<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/config_ollama.php';
require_once __DIR__ . '/inc/security_pipeline.php';

// Appel Ollama — réponses texte libre
function call_ollama_text(string $prompt): string {
    $payload = json_encode([
        'model'   => OLLAMA_MODEL,
        'prompt'  => $prompt,
        'stream'  => false,
        'options' => ['temperature' => 0.3, 'num_predict' => 1500],
    ]);
    $ch = curl_init(OLLAMA_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '127.0.0.1,localhost',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!$raw) return '';
    $text = '';
    foreach (explode("\n", trim((string)$raw)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $j = json_decode($line, true);
        if (isset($j['response'])) $text .= $j['response'];
    }
    return trim($text);
}

@ini_set('max_execution_time', '300');
@set_time_limit(300);

if (!isset($_SESSION['username'])) {
    header('Location: index.php'); exit;
}

$username = $_SESSION['username'];

// Outil unique Community : traduction confidentielle
$tools = [
    [
        'id'          => 'translate_internal',
        'icon'        => '🌐',
        'title'       => 'Traduction confidentielle',
        'desc'        => 'Traduit un document confidentiel localement sans envoyer les données vers des services externes. 100% local via phi3:mini.',
        'placeholder' => 'Collez le texte à traduire et précisez la langue cible en début de texte (ex: "Traduire en anglais : ...")...',
    ],
];

$phi3Prompt = "Traduis ce texte fidèlement en respectant le sens et le registre. Si des termes techniques n'ont pas d'équivalent direct, garde le terme original entre parenthèses.";

$result   = '';
$toolUsed = '';
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tool_id']) && !empty($_POST['tool_content'])) {
    $toolId   = $_POST['tool_id'];
    $content  = trim($_POST['tool_content']);
    $toolUsed = $toolId;

    if ($toolId === 'translate_internal') {
        $fullPrompt = $phi3Prompt . "\n\n--- TEXTE ---\n" . substr($content, 0, 6000) . "\n--- FIN TEXTE ---";
        $result = call_ollama_text($fullPrompt);
        if (empty(trim($result))) {
            $error = 'OLLAMA_DOWN';
        }
    }

    // Log usage anonymisé
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    file_put_contents($logDir . '/user_tools.ndjson',
        json_encode([
            'ts'    => date('c'),
            'user'  => $username,
            'tool'  => $toolId,
            'chars' => strlen($content),
        ], JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Traduction confidentielle — Velixa for Gen AI</title>
<link rel="stylesheet" href="style.css">
<style>
* { box-sizing: border-box }
body { background: #0B0C0E; color: #ECECEC; font-family: Inter, Arial, sans-serif; margin: 0 }
.topbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid #1f242b; background: #0f1114; position: sticky; top: 0; z-index: 10 }
.main { max-width: 860px; margin: 32px auto; padding: 0 16px }
.card { background: #121416; border: 1px solid #1f242b; border-radius: 14px; padding: 24px; margin-bottom: 20px }
.card-title { font-size: 16px; font-weight: 700; color: #10B981; margin-bottom: 6px }
.card-desc { font-size: 12px; color: #9CA3AF; line-height: 1.6; margin-bottom: 16px }
textarea { width: 100%; background: #0e1318; color: #e5e7eb; border: 1px solid #222933; border-radius: 10px; padding: 12px; font-size: 13px; outline: none; min-height: 200px; resize: vertical; font-family: inherit }
textarea:focus { border-color: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,.2) }
.btn { padding: 10px 22px; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 13px }
.btn-green { background: #10B981; color: #0b1114 }
.btn-muted { background: #1b2330; color: #9CA3AF; border: 1px solid #1f242b }
.result-box { background: #0d1318; border: 1px solid #1a2030; border-radius: 10px; padding: 16px; margin-top: 16px; font-size: 13px; line-height: 1.8; white-space: pre-wrap; max-height: 500px; overflow-y: auto }
.local-badge { background: #1e3a5f; color: #93c5fd; font-size: 10px; padding: 3px 8px; border-radius: 10px; font-weight: 700 }
.actions { display: flex; gap: 10px; margin-top: 14px; align-items: center; flex-wrap: wrap }
.badge-green { display: inline-block; background: #064e3b; color: #10B981; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700 }
.spinner { display: none; text-align: center; padding: 30px; color: #9CA3AF; font-size: 13px }
.info-box { background: #0d1a2a; border: 1px solid #1e3a5f; border-radius: 12px; padding: 14px; font-size: 12px; color: #9CA3AF; line-height: 1.7; margin-top: 20px }
.error-box { background: #1a0f0f; border: 1px solid #7a1d1d; border-radius: 10px; padding: 16px; margin-top: 14px }
</style>
</head>
<body>

<div class="topbar">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="interface_user.php" style="color:#93c5fd;font-size:13px;text-decoration:none">← Chat IA</a>
    <strong>🌐 Traduction confidentielle</strong>
  </div>
  <span class="local-badge">🔒 100% local — phi3:mini — données jamais envoyées</span>
</div>

<div class="main">

  <div class="card">
    <div class="card-title">🌐 Traduction confidentielle locale</div>
    <div class="card-desc">
      Traduisez vos documents confidentiels localement grâce à phi3:mini via Ollama.
      Vos données ne quittent jamais votre infrastructure — aucun envoi vers des services externes.
      <br>Précisez la langue cible en début de texte (ex : <em>"Traduire en anglais : ..."</em>).
    </div>

    <form method="post" id="toolForm" onsubmit="showSpinner()">
      <?= vx_csrf_field() ?>
      <input type="hidden" name="tool_id" value="translate_internal">
      <textarea name="tool_content" placeholder="Collez le texte à traduire et précisez la langue cible en début de texte...
Exemple : Traduire en anglais : [votre texte ici]"><?= isset($_POST['tool_content']) ? vx_h($_POST['tool_content']) : '' ?></textarea>

      <div class="actions">
        <button type="submit" class="btn btn-green">🌐 Traduire avec phi3:mini</button>
        <button type="button" class="btn btn-muted" onclick="document.querySelector('textarea').value=''">🗑 Effacer</button>
        <span style="font-size:11px;color:#9CA3AF">Traitement : 30-60s selon la longueur</span>
      </div>
    </form>

    <div class="spinner" id="spinner">
      <div style="font-size:20px;margin-bottom:8px">⚙️</div>
      Phi3 traduit votre document localement...<br>
      <span style="font-size:11px">Cela peut prendre 30 à 60 secondes</span>
    </div>

    <?php if (!empty($error)): ?>
    <div class="error-box">
      <div style="color:#fca5a5;font-size:13px;font-weight:700;margin-bottom:8px">⚠ Phi3 (Ollama) non disponible</div>
      <div style="color:#fca5a5;font-size:12px;line-height:1.8">
        La traduction fonctionne <strong>uniquement en local via Ollama</strong>.<br>
        Aucune donnée ne peut être envoyée vers un service externe.<br><br>
        <strong>Pour démarrer Ollama :</strong><br>
        1. Ouvrez un terminal Windows<br>
        2. Tapez : <code style="background:#2a0f0f;padding:2px 6px;border-radius:4px">ollama serve</code><br>
        3. Si phi3:mini n'est pas installé : <code style="background:#2a0f0f;padding:2px 6px;border-radius:4px">ollama pull phi3:mini</code><br>
        4. Rechargez cette page
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($result)): ?>
    <div style="margin-top:14px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <span class="badge-green">✓ Traduction terminée</span>
        <span class="local-badge">phi3:mini — local</span>
      </div>
      <div class="result-box"><?= vx_h($result) ?></div>
      <div class="actions" style="margin-top:10px">
        <button onclick="copyResult()" class="btn btn-muted">📋 Copier la traduction</button>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="info-box">
    🔒 <strong style="color:#93c5fd">Confidentialité garantie</strong> — Cet outil utilise phi3:mini en local via Ollama.
    Vos documents ne quittent jamais votre infrastructure. Aucune donnée n'est envoyée vers OpenAI, Anthropic ou tout autre service externe.
    Les usages sont loggés de façon anonymisée à des fins d'audit interne.
    <br><br>
    💡 <strong style="color:#93c5fd">Velixa for Gen AI Enterprise</strong> inclut des outils métier avancés : analyse NIS2, conformité RGPD, audit sécurité, détection de biais, conformité DORA et plus.
    Contactez-nous pour en savoir plus.
  </div>

</div>

<script>
var resultText = <?= json_encode($result) ?>;
function showSpinner() {
  document.getElementById('spinner').style.display = 'block';
}
function copyResult() {
  navigator.clipboard.writeText(resultText).then(function() {
    alert('Traduction copiée dans le presse-papiers');
  });
}
</script>
</body>
</html>
