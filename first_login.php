<?php
require_once __DIR__ . '/inc/bootstrap.php';
// first_login.php — Force password change on first login (Velixa theme)
require __DIR__ . '/users_lib.php';

// 1) Protected access: must know the user in session
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }

$uid   = $_SESSION['uid'];
$users = load_users();
if (!isset($users[$uid])) { session_destroy(); header('Location: index.php'); exit; }
$user = $users[$uid];

// 2) If the flag is not active, route to MFA flow (enable if missing, otherwise verify)
if (empty($user['must_change_password'])) {
    $mfaEnabled = $user['mfa']['enabled'] ?? false;
    if (!$mfaEnabled) { header('Location: enable_mfa.php'); exit; }
    header('Location: verify_mfa.php'); exit;
}

// 3) Handle form submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['p1'] ?? '';
    $p2 = $_POST['p2'] ?? '';

    // Minimum policy: 12+ chars, 1 uppercase, 1 lowercase, 1 number
    if ($p1 !== $p2) {
        $error = "Passwords do not match.";
    } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{12,}$/', $p1)) {
        $error = "Policy: 12+ characters, at least 1 uppercase, 1 lowercase, and 1 number.";
    } else {
        // Hash using ARGON2ID (works even if old hashes were bcrypt)
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $newHash = password_hash($p1, $algo);

        // Update via users_lib (it will rewrite your stored password field)
        $ok = update_user($uid, [
            'password_hash'        => $newHash,
            'must_change_password' => false,
            'last_login'           => date('c'),
        ]);

        if ($ok) {
            // Flash success and go straight to MFA enrollment (QR)
            $_SESSION['flash_success'] = 'Your password has been updated successfully. Please enable two-factor authentication to continue.';
            header('Location: enable_mfa.php'); 
            exit;
        } else {
            $error = "An error occurred while saving your new password. Please try again.";
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Velixa · Change Your Password</title>
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
    }
    .vx-logo{
      display:block;
      margin:0 auto 10px auto;
      height:120px;
      width:auto;
    }
    h1{margin:10px 0 6px 0; font-size:22px; font-weight:800; text-align:center}
    p.lead{margin:0 0 18px 0; text-align:center; color:#cbd5e1}
    label{
      display:block;
      margin:10px 0 6px;
      font-weight:600;
      font-size:14px;
      color:#e5e7eb;
    }
    input[type="password"]{
      width:100%;
      padding:12px 14px;
      border-radius:10px;
      border:1px solid var(--vx-border);
      background:#0f1317;
      color:#fff;
      margin-bottom:10px;
      font-size:15px;
      transition:border .15s, box-shadow .15s, background .15s;
    }
    input[type="password"]:focus{
      border-color:var(--vx-green-strong);
      box-shadow:0 0 0 3px var(--vx-ring);
      outline:none;
    }
    button{
      background: var(--vx-green);
      color:#0b1114;
      font-weight:800;
      border:none;
      border-radius:10px;
      padding:14px;
      width:100%;
      cursor:pointer;
      font-size:15px;
      box-shadow:0 8px 24px rgba(16,185,129,.25);
      transition:filter .2s, transform .15s, box-shadow .2s;
      margin-top:6px;
    }
    button:hover, button:focus-visible{
      filter:brightness(1.08);
      transform:translateY(-2px);
      box-shadow:
        0 0 0 3px var(--vx-ring),
        0 16px 40px rgba(16,185,129,.4),
        0 0 22px rgba(52,211,153,.35) inset;
      outline:none;
    }
    .error{
      background:#3b0f0f;
      border:1px solid #7a1d1d;
      color:#f8d7da;
      padding:10px;
      border-radius:10px;
      margin-bottom:15px;
      font-size:14px;
    }
    .rules{margin-top:10px;font-size:13px;color:#9ca3af}
  </style>
</head>
<body>
  <div class="card">
    <?php if (file_exists('assets/velixa-logo.png')): ?>
      <img src="assets/velixa-logo.png" alt="Velixa" class="vx-logo">
    <?php endif; ?>

    <h1>Change your password</h1>
    <p class="lead">To continue, please set a new password.</p>

    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
        <?= vx_csrf_field() ?>
      <label>New Password</label>
      <input type="password" name="p1" required autocomplete="new-password" />
      <label>Confirm Password</label>
      <input type="password" name="p2" required autocomplete="new-password" />
      <button type="submit">Update password</button>
    </form>

    <p class="rules">
      Rules: 12+ characters, at least 1 uppercase, 1 lowercase, and 1 number.
    </p>
  </div>
</body>
</html>
