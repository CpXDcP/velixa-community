<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
// FIX: $_SESSION['admin'] n'existe pas → remplacé par vx_require_admin()
vx_require_admin(true);

$usersFile = __DIR__ . '/users.json';
$users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];
if (!is_array($users)) $users = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['user','admin'], true) ? $_POST['role'] : 'user';
        $metier   = trim(strip_tags((string)($_POST['metier'] ?? 'autre')));
        if (!preg_match('/^[a-zA-Z0-9._\-]{3,64}$/', $username)) {
            $message = '⚠️ Nom invalide (3-64 chars alphanumériques).';
        } elseif (strlen($password) < 12) {
            $message = '⚠️ Mot de passe minimum 12 caractères.';
        } elseif (isset($users[$username])) {
            $message = '⚠️ Utilisateur déjà existant.';
        } else {
            $users[$username] = [
                'password' => password_hash($password, PASSWORD_ARGON2ID),
                'role' => $role, 'metier' => $metier,
                'must_change_password' => true, 'status' => 'active',
                'mfa' => ['enabled'=>false,'type'=>null,'secret'=>null,'recovery_codes'=>[]],
            ];
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);
            $message = '✅ Utilisateur ajouté.';
        }
    }
    if (!empty($_POST['delete'])) {
        $del = (string)$_POST['delete'];
        if ($del === ($_SESSION['username'] ?? '')) {
            $message = '⚠️ Impossible de supprimer votre propre compte.';
        } elseif (isset($users[$del])) {
            unset($users[$del]);
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);
            $message = '🗑️ Utilisateur supprimé.';
        }
    }
}
?><!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Manage users — Velixa</title><link rel="stylesheet" href="style.css">
<style>body{background:#0B0C0E;color:#fff;font-family:Inter,Arial,sans-serif;padding:24px}
table{border-collapse:collapse;width:100%;background:#121416}th,td{border:1px solid #1f242b;padding:10px;text-align:center}
th{background:#0f1317}.frm{background:#121416;padding:20px;border-radius:12px;border:1px solid #1f242b;margin:16px 0}
label{display:block;margin:8px 0 4px;font-size:13px}input,select{width:100%;padding:8px;background:#0e1318;color:#fff;border:1px solid #222933;border-radius:8px;margin-bottom:8px}
.btn{padding:10px 18px;background:#10B981;color:#0b1114;font-weight:700;border:none;border-radius:8px;cursor:pointer}
.del{background:#7f1d1d;color:#fff;border:none;padding:5px 10px;border-radius:6px;cursor:pointer}
.msg{padding:10px;border-radius:8px;margin:10px 0;background:#102312;border:1px solid #194a1d}</style></head>
<body><h2>👤 Manage users</h2><a href="dashboard.php" style="color:#93c5fd">⬅ Dashboard</a>
<?php if($message): ?><div class="msg"><?=htmlspecialchars($message)?></div><?php endif; ?>
<div class="frm"><h3>Add user</h3><form method="post"><?=vx_csrf_field()?>
<label>Username (3-64 chars)</label><input type="text" name="username" pattern="[a-zA-Z0-9._\-]{3,64}" required>
<label>Password (min 12 chars)</label><input type="password" name="password" minlength="12" required>
<label>Rôle</label><select name="role"><option value="user">User</option><option value="admin">Admin</option></select>
<label>Métier</label><select name="metier">
<?php foreach(['IT','RH','Finance','Direction','Marketing','Support IT','Développeur','Data Analyst','Commercial','autre'] as $m): ?>
<option value="<?=htmlspecialchars($m)?>"><?=htmlspecialchars($m)?></option><?php endforeach; ?>
</select>
<input type="hidden" name="action" value="add"><button type="submit" class="btn">Ajouter</button></form></div>
<h3>📋 Users</h3>
<table><thead><tr><th>Name</th><th>Rôle</th><th>Job</th><th>MFA</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach($users as $name=>$data): if(str_starts_with($name,'_')) continue; ?>
<tr><td><?=htmlspecialchars($name)?></td><td><?=htmlspecialchars($data['role']??'')?></td>
<td><?=htmlspecialchars($data['metier']??'')?></td>
<td><?=empty($data['mfa']['enabled'])?'❌':'✅'?></td>
<td><?=htmlspecialchars($data['status']??'active')?></td>
<td><?php if($name!==($_SESSION['username']??'')): ?>
<form method="post" style="margin:0" onsubmit="return confirm('Supprimer <?=htmlspecialchars($name)?> ?')">
<?=vx_csrf_field()?><input type="hidden" name="delete" value="<?=htmlspecialchars($name)?>">
<button type="submit" class="del">🗑 Supprimer</button></form>
<?php else: ?><em style="color:#9CA3AF">Vous</em><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></body></html>
