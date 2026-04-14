<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/users_lib.php';
require_once __DIR__ . '/inc/ldap_helpers.php';

$ldap_cfg = file_exists(__DIR__.'/ldap_config.php') ? (require __DIR__.'/ldap_config.php') : ['hosts'=>[]];
$HAS_LDAP_STACK = !empty($ldap_cfg['hosts']);

function redirectToRolePage($role) {
    if ($role === 'admin') {
        header('Location: dashboard.php');
    } else {
        header('Location: interface_user.php');
    }
    exit();
}

if (isset($_SESSION['username']) && isset($_SESSION['role']) && !empty($_SESSION['mfa_verified'])) {
    redirectToRolePage($_SESSION['role']);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $didAuth = false;

    $_SESSION['_vx_login_attempts'] = (int)($_SESSION['_vx_login_attempts'] ?? 0);
    if ($_SESSION['_vx_login_attempts'] >= 8) {
        $error = 'Too many failed attempts. Please wait a moment and retry.';
    } else {
        $users = load_users();
        if ($username !== '' && isset($users[$username])) {
            $storedPassword = (string)($users[$username]['password_hash'] ?? $users[$username]['password'] ?? '');
            if ($storedPassword !== '' && password_verify($password, $storedPassword)) {
                vx_regenerate_session();
                $_SESSION['uid'] = $username;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $users[$username]['role'] ?? 'user';
                $_SESSION['metier'] = $users[$username]['metier'] ?? 'general';
                $_SESSION['mfa_verified'] = false;
                $_SESSION['_vx_login_attempts'] = 0;
                if (!empty($users[$username]['must_change_password'])) { header('Location: first_login.php'); exit(); }
                $mfaEnabled = !empty($users[$username]['mfa']['enabled']);
                header('Location: ' . ($mfaEnabled ? 'verify_mfa.php' : 'enable_mfa.php')); exit();
            }
        }

        if (!$didAuth && $HAS_LDAP_STACK && $username !== '' && $password !== '') {
            $hostUri = (string)$ldap_cfg['hosts'][0];
            $parts = parse_url($hostUri);
            $cfg = [
                'scheme' => $parts['scheme'] ?? 'ldap',
                'host' => $parts['host'] ?? 'localhost',
                'port' => (int)($parts['port'] ?? (($parts['scheme'] ?? 'ldap') === 'ldaps' ? 636 : 389)),
                'use_starttls' => !empty($ldap_cfg['use_starttls'] ?? $ldap_cfg['use_tls'] ?? false),
                'bind_dn' => (string)($ldap_cfg['bind_dn'] ?? ''),
                'bind_password' => (string)($ldap_cfg['bind_pass'] ?? ''),
                'base_dn' => (string)($ldap_cfg['base_dn'] ?? ''),
                'user_filter' => (string)($ldap_cfg['user_filter'] ?? '(&(objectClass=user)(sAMAccountName=%s))'),
                'group_map' => $ldap_cfg['group_map'] ?? ['admins'=>[], 'users'=>[]],
            ];
            $res = vx_ldap_bind($cfg);
            if (!empty($res['ok'])) {
                $entry = vx_ldap_find_user_entry($res['link'], $cfg, $username);
                if ($entry && !empty($entry['dn']) && vx_ldap_user_bind_ok($cfg, $entry['dn'], $password)) {
                    $uid = $username;
                    $role = vx_ldap_role_from_groups($cfg, $entry['groups'] ?? []);
                    $metier = vx_ldap_metier_from_entry($entry);
                    if (!isset($users[$uid])) {
                        create_user($uid, [
                            'username' => $username,
                            'role' => $role,
                            'metier' => $metier,
                            'must_change_password' => false,
                            'mfa' => ['enabled'=>false,'type'=>null,'secret'=>null,'recovery_codes'=>[]],
                            'status' => 'active'
                        ]);
                        $users = load_users();
                    } else {
                        update_user($uid, ['role'=>$role, 'metier'=>$metier, 'status'=>'active', 'last_login'=>date('c')]);
                        $users = load_users();
                    }
                    @ldap_unbind($res['link']);
                    vx_regenerate_session();
                    $_SESSION['uid'] = $uid;
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = $users[$uid]['role'] ?? $role;
                    $_SESSION['metier'] = $users[$uid]['metier'] ?? $metier;
                    $_SESSION['mfa_verified'] = false;
                    $_SESSION['_vx_login_attempts'] = 0;
                    if (!empty($users[$uid]['must_change_password'])) { header('Location: first_login.php'); exit(); }
                    $mfaEnabled = !empty($users[$uid]['mfa']['enabled']);
                    header('Location: ' . ($mfaEnabled ? 'verify_mfa.php' : 'enable_mfa.php')); exit();
                }
                @ldap_unbind($res['link']);
            }
        }

        $_SESSION['_vx_login_attempts']++;
        $error = 'Incorrect username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login – Velixa</title>
  <link rel="stylesheet" href="style.css">
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
    .login-box{
      background: var(--vx-surface);
      padding: 42px 50px;
      border-radius: var(--vx-radius);
      border:1px solid var(--vx-border);
      box-shadow:0 20px 60px rgba(0,0,0,.45);
      width:100%;
      max-width:400px;
      text-align:center;
    }
    .vx-logo{
      display:block;
      margin:0 auto 22px auto;
      height:220px;
      width:auto;
    }
    h2{
      margin:0 0 18px 0;
      font-size:22px;
      font-weight:700;
    }
    label{
      display:block;
      text-align:left;
      margin:10px 0 4px;
      font-weight:600;
      font-size:14px;
      color:#e5e7eb;
    }
    input[type="text"], input[type="password"]{
      width:100%;
      padding:12px 14px;
      border-radius:10px;
      border:1px solid var(--vx-border);
      background:#0f1317;
      color:#fff;
      margin-bottom:14px;
      font-size:15px;
      transition:border .15s, box-shadow .15s, background .15s;
    }
    input[type="text"]:focus, input[type="password"]{
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
    .error-message{
      background:#3b0f0f;
      border:1px solid #7a1d1d;
      color:#f8d7da;
      padding:10px;
      border-radius:10px;
      margin-bottom:15px;
      font-size:14px;
    }
  </style>
</head>
<body>
  <div class="login-box">
    <?php if (file_exists('assets/velixa-logo.png')): ?>
      <img src="assets/velixa-logo.png" alt="Velixa" class="vx-logo">
    <?php else: ?>
      <h2>VELIXA</h2>
    <?php endif; ?>

    <h2>🔐 Secure Login</h2>

    <?php if ($error): ?>
      <div class="error-message">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <?= vx_csrf_field() ?>
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required placeholder="Enter your username">

      <label for="password">Password</label>
      <input type="password" id="password" name="password" required placeholder="Enter your password">

      <button type="submit">Connect</button>
    </form>
  </div>
</body>
</html>
