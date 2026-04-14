<?php
require_once __DIR__ . '/inc/bootstrap.php';
// enable_mfa.php — Enable TOTP 2FA for all users (Velixa dark theme)
require __DIR__ . '/users_lib.php';
require __DIR__ . '/mfa_lib.php';

// Fallback if recovery generator is missing
if (!function_exists('mfa_generate_recovery_codes')) {
  function mfa_generate_recovery_codes($n = 8) {
    $codes = [];
    for ($i = 0; $i < $n; $i++) $codes[] = strtoupper(bin2hex(random_bytes(4)));
    return $codes;
  }
}

// 1) Protected access
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid   = $_SESSION['uid'];
$users = load_users();
if (!isset($users[$uid])) { session_destroy(); header('Location: index.php'); exit; }
$user  = $users[$uid];

// 2) Must change password first?
if (!empty($user['must_change_password'])) { header('Location: first_login.php'); exit; }

/* --- IMPORTANT: fix inconsistent state to avoid redirect loop ---
   If enabled=true but secret is missing (edited JSON, crash, etc),
   we force enabled=false so user can enroll properly here. */
if (!empty($user['mfa']['enabled']) && empty($user['mfa']['secret'])) {
  $users[$uid]['mfa']['enabled'] = false;
  save_users($users);
  $user = $users[$uid];
}

// 3) If already enabled AND secret present, go verify (we require TOTP at each login)
if (!empty($user['mfa']['enabled']) && !empty($user['mfa']['secret'])) {
  header('Location: verify_mfa.php'); exit;
}

// 4) Ensure secret exists (persisted in users.json)
if (empty($user['mfa']['secret'])) {
  $users[$uid]['mfa'] = [
    'enabled' => false,
    'type'    => 'totp',
    'secret'  => mfa_random_base32(32),
    'recovery_codes' => mfa_generate_recovery_codes(8),
  ];
  save_users($users);
  $user = $users[$uid]; // reload
}

$secret  = $user['mfa']['secret'];
$issuer  = 'Velixa';
$label   = $user['username'] ?? $uid;
$otpauth = mfa_build_otpauth_uri($issuer, $label, $secret);

// 5) Handle submission: verify first TOTP
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code = $_POST['code'] ?? '';
  if (!mfa_verify_totp($secret, $code, 1)) {
    $error = 'Invalid code. Please try again.';
  } else {
    // Enable 2FA
    $users[$uid]['mfa']['enabled'] = true;
    save_users($users);

    // Route by role
    $role = $user['role'] ?? 'user';
    if ($role === 'admin') { header('Location: dashboard.php'); exit; }
    header('Location: interface_user.php'); exit;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Velixa · Enable Two-Factor Authentication</title>
  <style>
    :root{
      --vx-bg:#0B0C0E;
      --vx-surface:#121416;
      --vx-border:#1f242b;
      --vx-text:#FFFFFF;
      --vx-green:#10B981;
      --vx-green-strong:#34D399;
      --vx-ring: rgba(16,185,129,.45);
      --vx-radius:14px;
      --vx-muted:#9CA3AF;
    }
    *{box-sizing:border-box}
    body{
      margin:0; min-height:100vh;
      display:flex; align-items:center; justify-content:center;
      background: radial-gradient(1200px 600px at 50% -10%, #14181d 0%, #0B0C0E 55%);
      font-family: Inter, Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color:var(--vx-text);
    }
    .wrap{
      width:100%; max-width:980px;
      display:grid; grid-template-columns:260px 1fr; gap:18px;
      padding: 24px;
    }
    .card{
      background: var(--vx-surface);
      padding: 22px 24px;
      border-radius: var(--vx-radius);
      border:1px solid var(--vx-border);
      box-shadow:0 20px 60px rgba(0,0,0,.45);
    }
    .vx-logo{
      display:block; margin:0 auto 10px auto; height:120px; width:auto;
    }
    h1{margin:0 0 8px 0; font-size:22px; font-weight:800;}
    h2{margin:8px 0 10px 0; font-size:16px; font-weight:700;}
    p.muted{color:var(--vx-muted); margin:0 0 14px 0;}
    .qr{
      display:flex; align-items:center; justify-content:center;
      width:210px; height:210px; border:1px solid var(--vx-border);
      border-radius:12px; background:#fff; overflow:hidden; margin:auto;
    }
    label{
      display:block; margin:10px 0 6px; font-weight:600; font-size:14px; color:#e5e7eb;
    }
    input[type="text"]{
      width:100%; padding:12px 14px; border-radius:10px;
      border:1px solid var(--vx-border); background:#0f1317; color:#fff; font-size:15px;
      transition:border .15s, box-shadow .15s, background .15s; outline:none;
    }
    input[type="text"]:focus{
      border-color:var(--vx-green-strong);
      box-shadow:0 0 0 3px var(--vx-ring);
    }
    .actions{display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;}
    .btn{
      background: var(--vx-green); color:#0b1114; font-weight:800; border:none;
      border-radius:10px; padding:12px 16px; cursor:pointer; font-size:15px;
      box-shadow:0 8px 24px rgba(16,185,129,.25);
      transition:filter .2s, transform .15s, box-shadow .2s;
      text-decoration:none; display:inline-block;
    }
    .btn:hover,.btn:focus-visible{
      filter:brightness(1.08); transform:translateY(-2px);
      box-shadow:0 0 0 3px var(--vx-ring), 0 16px 40px rgba(16,185,129,.4), 0 0 22px rgba(52,211,153,.35) inset;
      outline:none;
    }
    .btn-ghost{
      background:#1b2330; color:#e5e7eb; border:1px solid var(--vx-border);
      box-shadow:none;
    }
    .alert{
      background:#102312; border:1px solid #194a1d; color:#d9fbd0;
      padding:10px 12px; border-radius:10px; margin:0 0 12px 0; font-size:14px;
    }
    .err{
      background:#3b0f0f; border:1px solid #7a1d1d; color:#f8d7da;
      padding:10px 12px; border-radius:10px; margin:0 0 12px 0; font-size:14px;
    }
    code{background:#0f1317; border:1px solid var(--vx-border); border-radius:6px; padding:2px 6px;}
    details{margin-top:10px}
    pre{background:#0f1317; border:1px solid var(--vx-border); padding:10px; border-radius:10px; overflow:auto; color:#e5e7eb}
    .header{grid-column:1 / -1; text-align:center; margin-bottom:2px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <?php if (file_exists('assets/velixa-logo.png')): ?>
        <img src="assets/velixa-logo.png" alt="Velixa" class="vx-logo">
      <?php endif; ?>
      <h1>Enable Two-Factor Authentication (TOTP)</h1>
      <p class="muted">Scan the QR code with your authenticator app, then enter the 6-digit code to confirm.</p>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>
      <?php if (!empty($error)): ?>
        <div class="err"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
    </div>

    <div class="card" style="padding:16px">
      <div id="qrcode" class="qr" aria-label="QR Code for TOTP"></div>
    </div>

    <div class="card">
      <h2>Set up your authenticator</h2>
      <ol style="margin:0 0 12px 18px; color:#d1d5db">
        <li>Open your authenticator app (Google Authenticator, Microsoft Authenticator, etc.).</li>
        <li>Choose <em>“Scan a QR code”</em> and scan the code on the left.</li>
        <li>Or add it manually using this secret: <code><?= htmlspecialchars($secret) ?></code></li>
      </ol>
      <p class="muted" style="margin-bottom:8px">Account: <code><?= htmlspecialchars($label) ?></code> · Issuer: <code>Velixa</code></p>

      <form method="post" autocomplete="off">
        <?= vx_csrf_field() ?>
        <label>Enter the 6-digit code</label>
        <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" placeholder="123456" required />
        <div class="actions">
          <button type="submit" class="btn">Activate 2FA</button>
          <a class="btn btn-ghost" href="logout.php">Cancel</a>
        </div>
      </form>

      <details>
        <summary>Recovery codes (store them safely)</summary>
        <p class="muted">Use a recovery code if you lose access to your phone. Each code can be used once.</p>
        <pre><?php foreach(($user['mfa']['recovery_codes'] ?? []) as $c){ echo htmlspecialchars($c)."\n"; } ?></pre>
      </details>
    </div>
  </div>

  <!-- QR code via API publique — pas de dépendance JS locale -->
  <script>
    (function(){
      var uri = <?= json_encode($otpauth) ?>;
      var box = document.getElementById('qrcode');
      if (!box) return;
      // Utilise goqr.me (API publique, aucune install requise)
      var img = document.createElement('img');
      img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(uri);
      img.alt = 'QR Code TOTP';
      img.width = 200; img.height = 200;
      img.style.borderRadius = '8px';
      img.onerror = function() {
        // Fallback si pas de connexion internet : afficher l'URI manuellement
        box.style.background = '#fff';
        box.style.padding = '10px';
        box.innerHTML = '<p style="font-size:11px;color:#000;word-break:break-all;margin:0"><strong>Ajout manuel :</strong><br>' + uri + '</p>';
      };
      box.innerHTML = '';
      box.appendChild(img);
    })();
  </script>
</body>
</html>
