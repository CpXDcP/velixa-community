<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';

// Si déjà connecté et consentement déjà donné → redirect
if (isset($_SESSION['role']) && !empty($_SESSION['ai_consent_given'])) {
    header('Location: ' . ($_GET['redirect'] ?? 'interface_user.php'));
    exit;
}

// Si pas connecté → redirect login
if (!isset($_SESSION['role'])) {
    header('Location: index.php');
    exit;
}

$username = $_SESSION['username'] ?? '';
$usersFile = __DIR__ . '/users.json';
$users = json_decode(file_get_contents($usersFile), true) ?: [];

// Vérifier si l'utilisateur a déjà consenti
$user = $users[$username] ?? [];
$consentVersion = '1.0'; // Incrémenter à chaque mise à jour de la charte
$alreadyConsented = ($user['ai_consent_version'] ?? '') === $consentVersion;

if ($alreadyConsented) {
    $_SESSION['ai_consent_given'] = true;
    header('Location: ' . ($_GET['redirect'] ?? 'interface_user.php'));
    exit;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['accept'])) {
    // Enregistrer le consentement
    $users[$username]['ai_consent_version'] = $consentVersion;
    $users[$username]['ai_consent_date']    = date('c');
    $users[$username]['ai_consent_ip']      = $_SERVER['REMOTE_ADDR'] ?? '';
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $_SESSION['ai_consent_given'] = true;
    header('Location: ' . ($_GET['redirect'] ?? 'interface_user.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Charte d'usage IA — Velixa</title>
<link rel="stylesheet" href="style.css">
<style>
*{box-sizing:border-box}
body{background:#0B0C0E;color:#ECECEC;font-family:Inter,Arial,sans-serif;display:flex;justify-content:center;align-items:flex-start;min-height:100vh;padding:40px 16px}
.box{background:#121416;border:1px solid #1f242b;border-radius:16px;padding:32px;max-width:640px;width:100%}
h1{font-size:20px;margin:0 0 6px}
.version{font-size:11px;color:#9CA3AF;margin-bottom:24px}
.article{margin-bottom:16px;padding:14px;background:#0d1318;border-radius:10px;border-left:3px solid #1f3a5f}
.article h3{font-size:13px;margin:0 0 6px;color:#93c5fd}
.article p{font-size:12px;color:#9CA3AF;line-height:1.7;margin:0}
.actions{display:flex;gap:12px;margin-top:24px;align-items:center}
.btn-accept{padding:12px 24px;background:#10B981;color:#0b1114;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:14px}
.btn-refuse{padding:12px 24px;background:none;color:#9CA3AF;border:1px solid #1f242b;border-radius:10px;cursor:pointer;font-size:13px}
.chk-line{display:flex;align-items:flex-start;gap:10px;margin-top:16px;font-size:13px;color:#9CA3AF}
.chk-line input{margin-top:2px;flex-shrink:0}
</style>
</head>
<body>
<div class="box">
  <h1>&#128203; Charte d'usage de l'Intelligence Artificielle</h1>
  <div class="version">Version <?= $consentVersion ?> · <?= date('d/m/Y') ?> · Utilisateur : <?= vx_h($username) ?></div>

  <div class="article">
    <h3>Art. 1 — Usage professionnel</h3>
    <p>L'outil IA mis à disposition est réservé à un usage strictement professionnel dans le cadre de vos missions. Tout usage personnel ou détourné est interdit.</p>
  </div>
  <div class="article">
    <h3>Art. 2 — Données sensibles</h3>
    <p>Il est interdit de soumettre à l'IA des données personnelles, médicales, financières, ou confidentielles sans autorisation explicite. Velixa contrôle et bloque automatiquement ces flux.</p>
  </div>
  <div class="article">
    <h3>Art. 3 — Vérification des réponses</h3>
    <p>Les réponses générées par l'IA peuvent contenir des erreurs. Vous êtes responsable de la vérification des informations avant tout usage professionnel.</p>
  </div>
  <div class="article">
    <h3>Art. 4 — Traçabilité</h3>
    <p>Vos interactions avec l'IA sont loggées à des fins de conformité RGPD et EU AI Act. Ces logs sont conservés selon la politique de rétention en vigueur et accessibles sur demande auprès du DPO.</p>
  </div>
  <div class="article">
    <h3>Art. 5 — Propriété intellectuelle</h3>
    <p>Ne soumettez pas de code source, formules, brevets ou documents confidentiels de l'entreprise à des LLM externes sans autorisation de la DSI.</p>
  </div>

  <form method="post">
    <?= vx_csrf_field() ?>
    <div class="chk-line">
      <input type="checkbox" id="read" required>
      <label for="read">J'ai lu et compris la charte d'usage de l'IA. J'accepte de respecter ces règles dans le cadre de mon travail.</label>
    </div>
    <div class="actions">
      <button type="submit" name="accept" value="1" class="btn-accept">J'accepte et je continue</button>
      <a href="logout.php"><button type="button" class="btn-refuse">Refuser et se déconnecter</button></a>
    </div>
  </form>
</div>
</body>
</html>
