<?php
require_once __DIR__ . '/inc/bootstrap.php';
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    die("Accès refusé");
}

$users_file = "users.json";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $new_user = $_POST["username"];
    $password = password_hash($_POST["password"], PASSWORD_DEFAULT);
    $role = $_POST["role"];
    $job = $_POST["job"];

    $users = file_exists($users_file) ? json_decode(file_get_contents($users_file), true) : [];
    $users[$new_user] = ["password" => $password, "role" => $role, "job" => $job];
    file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));

    echo "<p style='color:green;'>Utilisateur ajouté avec succès !</p>";
}
?>

<h2>Add user</h2>
<form method="post">
        <?= vx_csrf_field() ?>
    <label>user name : <input type="text" name="username" required></label><br>
    <label>password : <input type="password" name="password" required></label><br>
    <label>job :
        <select name="job" required>
            <option value="it">IT</option>
            <option value="rh">RH</option>
            <option value="direction">Direction</option>
            <option value="santé">health</option>
        </select>
    </label><br>
    <label>Rôle :
        <select name="role" required>
            <option value="user">user</option>
            <option value="admin">Admin</option>
        </select>
    </label><br><br>
    <input type="submit" value="Ajouter l'utilisateur">
</form>
<p><a href="dashboard.php">⬅️ back to dashboard</a></p>
