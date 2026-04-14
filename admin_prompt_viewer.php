<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/secure_store.php';
require_once __DIR__ . '/inc/security_pipeline.php';
vx_require_admin(true);

/* =========================================================
   VELIXA — Secure Prompt Viewer
   Déchiffrement uniquement via clé temporaire fournie par Velixa.
   La clé maître ne réside JAMAIS sur ce serveur.
   ========================================================= */

$promptText = '';
$error      = '';
$success    = '';
$tokenInfo  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $promptId    = trim($_POST['prompt_id']    ?? '');
    $velixaToken = trim($_POST['velixa_token'] ?? '');

    if ($promptId === '') {
        $error = "L'ID du prompt est obligatoire.";
    } else {
        // Chercher le prompt dans prompts_encrypted.json
        $encryptedData = file_exists(__DIR__ . '/prompts_encrypted.json')
            ? json_decode(file_get_contents(__DIR__ . '/prompts_encrypted.json'), true)
            : [];

        $found = false;
        if (is_array($encryptedData)) {
            foreach ($encryptedData as $entry) {
                if (($entry['id'] ?? '') !== $promptId) continue;
                $found = true;
                $blob    = (string)($entry['encrypted_prompt'] ?? '');
                $storage = (string)($entry['storage'] ?? 'secure_store');
                // Toujours afficher le blob — déchiffrement dans velixa_keygen.html
                $promptText = '__RSA_BLOB__:' . $blob;
                break;
            }
        }
        if (!$found) $error = "❌ Aucun prompt trouvé avec cet identifiant.";

        // Log
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        $logEntry = json_encode([
            'ts'     => date('c'),
            'phase'  => 'prompt_access',
            'user'   => vx_anonymize_value($_SESSION['username'] ?? ''),
            'result' => $promptText !== '' ? 'blob_shown' : 'failure',
            'prompt' => vx_anonymize_value($promptId),
            'error'  => $error ?: null,
        ], JSON_UNESCAPED_UNICODE);
        file_put_contents($logDir . '/security_events.ndjson', $logEntry . "
", FILE_APPEND | LOCK_EX);
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>VELIXA — Prompt Viewer</title>
<link rel="stylesheet" href="style.css">
<style>
:root{--vx-bg:#0B0C0E;--vx-surface:#121416;--vx-border:#1f242b;--vx-text:#fff;--vx-muted:#9CA3AF;--vx-green:#10B981;--vx-red:#EF4444;--vx-radius:14px}
*{box-sizing:border-box}body{margin:0;background:var(--vx-bg);color:var(--vx-text);font-family:Inter,Arial,sans-serif;padding:0}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--vx-border);background:#0f1114;position:sticky;top:0;z-index:10}
.main{max-width:820px;margin:28px auto;padding:0 16px}
.card{background:var(--vx-surface);border:1px solid var(--vx-border);border-radius:var(--vx-radius);padding:22px;margin-bottom:16px}
.card h3{margin:0 0 14px;font-size:17px}
label{display:block;margin:10px 0 5px;font-size:13px;color:var(--vx-muted);font-weight:600}
input,textarea{width:100%;background:#0e1318;color:#e5e7eb;border:1px solid #222933;border-radius:10px;padding:10px 12px;font-size:13px;outline:none;transition:border .15s}
input:focus,textarea:focus{border-color:var(--vx-green)}
.btn{background:var(--vx-green);color:#0b1114;border:none;border-radius:10px;padding:11px 16px;font-weight:800;cursor:pointer;font-size:14px;margin-top:14px}
.btn:hover{filter:brightness(1.08)}
.btn-sec{background:#1b2330;border:1px solid var(--vx-border);color:#e5e7eb}
.msg{padding:11px 14px;border-radius:10px;margin-bottom:14px;font-size:13px}
.msg.err{background:#3b0f0f;border:1px solid #7a1d1d;color:#f8d7da}
.msg.ok{background:#102312;border:1px solid #194a1d;color:#d9fbd0}
.prompt-box{background:#0a0f0a;border:1px solid #1a3a1a;border-radius:10px;padding:14px;font-family:monospace;font-size:13px;white-space:pre-wrap;word-break:break-word;color:#d1fae5;max-height:400px;overflow-y:auto}
.info-box{background:#0d1a2a;border:1px solid #1e3a5f;border-radius:10px;padding:10px 14px;font-size:12px;color:#93c5fd;margin-bottom:12px}
.lock-icon{font-size:40px;text-align:center;margin-bottom:12px}
.note{font-size:12px;color:var(--vx-muted);margin-top:8px;line-height:1.6}
</style>
</head>
<body>

<div class="topbar">
  <a href="dashboard.php" style="color:#93c5fd;font-size:13px;text-decoration:none">⬅ Dashboard</a>
  <strong style="font-size:14px">🔐 Secure Prompt Viewer</strong>
  <a href="logout.php" style="color:#9CA3AF;font-size:13px;text-decoration:none">Déconnexion</a>
</div>

<div class="main">

  <?php if ($error): ?>
  <div class="msg err"><?= vx_h($error) ?></div>
  <?php endif; ?>

  <?php
    $isRsaBlob = str_starts_with($promptText ?? '', '__RSA_BLOB__:');
    $rsaBlob = $isRsaBlob ? substr($promptText, strlen('__RSA_BLOB__:')) : '';
    if($isRsaBlob) { $promptText = ''; $success = ''; }
  ?>
  <?php
    // Détecter le vrai format du blob
    $blobDecoded = base64_decode($rsaBlob, true);
    $blobJson    = $blobDecoded ? json_decode($blobDecoded, true) : null;
    $algoDetecte = is_array($blobJson) ? ($blobJson['algo'] ?? 'inconnu') : 'ancien format (non-RSA)';
    $isRealRsa   = ($algoDetecte === 'RSA-OAEP-AES256GCM');
  ?>
  <?php if ($isRsaBlob): ?>
  <div class="msg" style="background:#1a1a03;border:1px solid #F59E0B;color:#fef3c7;padding:12px">
    <?php if ($isRealRsa): ?>
      ✅ Format RSA Velixa détecté — copiez le blob dans <strong>velixa_keygen.html</strong> onglet "Déchiffrer".
    <?php else: ?>
      ⚠️ Ancien format de chiffrement (<code><?= vx_h($algoDetecte) ?></code>) — ce prompt a été soumis avant le déploiement RSA.<br>
      <strong>Soumettez un nouveau prompt</strong> dans l'interface utilisateur pour obtenir un blob RSA déchiffrable.
    <?php endif; ?>
  </div>
  <div class="card">
    <h3>📦 Blob — <?= vx_h($algoDetecte) ?></h3>
    <div class="prompt-box" id="blobBox" style="color:<?= $isRealRsa ? '#fbbf24' : '#9CA3AF' ?>"><?= vx_h($rsaBlob) ?></div>
    <button class="btn btn-sec" style="margin-top:10px" onclick="
      navigator.clipboard.writeText(document.getElementById('blobBox').innerText)
      .then(()=>{this.textContent='✅ Copié';setTimeout(()=>this.textContent='📋 Copier le blob',1800)})
    ">📋 Copier le blob</button>
  </div>
  <?php endif; ?>
  <?php if ($success && $promptText !== ''): ?>
  <div class="msg ok"><?= vx_h($success) ?></div>
  <?php if(!empty($tokenInfo)): ?>
  <div class="info-box">
    🔑 Clé Velixa valide · Expire à <?= vx_h($tokenInfo['expires']) ?> · Prompt : <?= vx_h($tokenInfo['prompt']) ?>
  </div>
  <?php endif; ?>
  <div class="card">
    <h3>🔓 Contenu du prompt déchiffré</h3>
    <div class="prompt-box" id="promptBox"><?= vx_h($promptText) ?></div>
    <div style="margin-top:10px;display:flex;gap:8px">
      <button class="btn btn-sec" onclick="
        navigator.clipboard.writeText(document.getElementById('promptBox').innerText)
        .then(()=>{this.textContent='✅ Copié';setTimeout(()=>this.textContent='📋 Copier',1800)})
      ">📋 Copier</button>
      <button class="btn btn-sec" onclick="window.print()">🖨 Imprimer</button>
    </div>
    <p class="note">⚠️ Ce contenu est confidentiel. Ne le partagez pas. Il sera loggé que vous l'ayez lu. Fermez cet onglet après usage.</p>
  </div>

  <?php else: ?>

  <div class="card">
    <div class="lock-icon">🔐</div>
    <h3 style="text-align:center">Consultation de prompt sécurisée</h3>
    <p class="note" style="text-align:center;margin-bottom:16px">
      Pour consulter un prompt chiffré, vous devez fournir l'ID du prompt et la clé temporaire fournie par <strong>Velixa</strong>.
      Cette clé est à usage limité dans le temps et ne peut pas être générée localement.
    </p>

    <form method="post" autocomplete="off">
      <?= vx_csrf_field() ?>

      <label>ID du prompt *</label>
      <input type="text" name="prompt_id" required
             placeholder="ex: prompt_69c9879d857ec9.54973921"
             value="<?= vx_h($_POST['prompt_id'] ?? '') ?>"
             style="font-family:monospace">

      <label>Clé Velixa (token reçu) — optionnel pour voir le blob</label>
      <input type="text" name="velixa_token"
             placeholder="Token fourni par Velixa…"
             style="font-family:monospace;font-size:11px">

      <button type="submit" class="btn">🔍 Déchiffrer</button>
    </form>

    <hr style="border:none;border-top:1px solid var(--vx-border);margin:20px 0">

    <div class="card" style="background:#0d1a2a;border-color:#1e3a5f">
      <h3 style="font-size:14px;color:#93c5fd">ℹ️ Comment obtenir une clé ?</h3>
      <ol style="margin:8px 0 0 18px;font-size:13px;line-height:1.8;color:var(--vx-muted)">
        <li>Identifiez l'ID du prompt dans les logs d'audit</li>
        <li>Contactez <strong style="color:var(--vx-text)">Velixa</strong> en indiquant l'ID et le motif de votre demande</li>
        <li>Velixa génère une clé temporaire et vous la transmet par canal sécurisé</li>
        <li>Collez la clé ci-dessus — elle expire selon les conditions définies par Velixa</li>
      </ol>
    </div>
  </div>

  <?php endif; ?>

</div>
</body>
</html>
