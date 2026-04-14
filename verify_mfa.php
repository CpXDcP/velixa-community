<?php
require_once __DIR__ . '/inc/bootstrap.php';
// verify_mfa.php — Verify TOTP at login (Velixa dark theme, all roles)
require __DIR__ . '/users_lib.php';
require __DIR__ . '/mfa_lib.php';

// 1) Protected access: user must be mid-login
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }

$uid   = $_SESSION['uid'];
$users = load_users();
if (!isset($users[$uid])) { session_destroy(); header('Location: index.php'); exit; }
$user  = $users[$uid];

// 2) Must change password first?
if (!empty($user['must_change_password'])) { header('Location: first_login.php'); exit; }

// 3) MFA must be enabled to verify; otherwise enroll
if (empty($user['mfa']['enabled']) || empty($user['mfa']['secret'])) {
  header('Location: enable_mfa.php'); exit;
}

$secret = $user['mfa']['secret'];

// 4) Submission: TOTP (6 digits) OR recovery code
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code  = trim($_POST['code'] ?? '');
  $plain = strtoupper(preg_replace('/\s+/', '', $code));

  $ok = false;

  // a) TOTP 6 digits
  if (ctype_digit($plain) && strlen($plain) === 6) {
    $ok = mfa_verify_totp($secret, $plain, 1);
  }

  // b) Recovery code (if TOTP failed)
  if (!$ok && !empty($user['mfa']['recovery_codes']) && is_array($user['mfa']['recovery_codes'])) {
    foreach ($user['mfa']['recovery_codes'] as $i => $rc) {
      if (vx_recovery_code_matches($plain, (string)$rc)) {
        $ok = true;
        // Invalidate used recovery code
        unset($user['mfa']['recovery_codes'][$i]);
        $users[$uid]['mfa']['recovery_codes'] = array_values($user['mfa']['recovery_codes']);
        save_users($users);
        break;
      }
    }
  }

  if ($ok) {
    vx_regenerate_session();
    $_SESSION['mfa_verified'] = true;
    // Route by role
    $role = $user['role'] ?? 'user';
    if ($role === 'admin') { header('Location: dashboard.php'); exit; }
    header('Location: interface_user.php'); exit;
  } else {
    $error = 'Invalid code. Please try again, or use a recovery code.';
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Velixa · Verify Two-Factor Code</title>
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
      margin:0;
      min-height:100vh;
      display:flex; align-items:center; justify-content:center;
      background: radial-gradient(1200px 600px at 50% -10%, #14181d 0%, #0B0C0E 55%);
      font-family: Inter, Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color:var(--vx-text);
    }
    .card{
      background: var(--vx-surface);
      padding: 42px 50px;
      border-radius: var(--vx-radius);
      border:1px solid var(--vx-border);
      box-shadow:0 20px 60px rgba(0,0,0,.45);
      width:100%;
      max-width:520px;
      text-align:left;
    }
    .vx-logo{
      display:block; margin:0 auto 10px auto; height:120px; width:auto;
    }
    h1{margin:10px 0 6px 0; font-size:22px; font-weight:800; text-align:center}
    p.lead{margin:0 0 18px 0; text-align:center; color:var(--vx-muted)}
    label{
      display:block; margin:10px 0 6px; font-weight:600; font-size:14px; color:#e5e7eb;
    }
    input[type="text"]{
      width:100%;
      padding:12px 14px;
      border-radius:10px;
      border:1px solid var(--vx-border);
      background:#0f1317;
      color:#fff;
      margin-bottom:10px;
      font-size:15px;
      transition:border .15s, box-shadow .15s, background .15s;
      outline:none;
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
    .err{
      background:#3b0f0f; border:1px solid #7a1d1d; color:#f8d7da;
      padding:10px 12px; border-radius:10px; margin:0 0 12px 0; font-size:14px;
    }
    .hint{margin-top:10px; font-size:13px; color:#9ca3af}
  </style>
</head>
<body>
  <div class="card">
    <?php if (file_exists('assets/velixa-logo.png')): ?>
      <img src="assets/velixa-logo.png" alt="Velixa" class="vx-logo">
    <?php endif; ?>

    <h1>Two-Factor Verification</h1>
    <p class="lead">Enter the 6-digit code from your authenticator app, or a recovery code.</p>

    <?php if (!empty($error)): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
        <?= vx_csrf_field() ?>
      <label>Authentication code (or recovery code)</label>
      <input type="text" name="code" inputmode="numeric" placeholder="123456 or A1B2C3D4" required />
      <div class="actions">
        <button type="submit" class="btn">Verify</button>
        <a class="btn btn-ghost" href="logout.php">Cancel</a>
      </div>
    </form>

    <p class="hint">
      Trouble signing in? Use one of your recovery codes. Each recovery code can be used only once.
    </p>
  </div>
</body>
</html>
