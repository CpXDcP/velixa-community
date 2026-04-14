<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
// FIX: session_start() redondant supprimé ; vx_require_admin() à la place de die()
vx_require_admin(true);

$wordlist_dir = __DIR__ . '/wordlists';
if (!is_dir($wordlist_dir)) @mkdir($wordlist_dir, 0775, true);
$wordlist_dir = realpath($wordlist_dir);
$available = array_filter(glob($wordlist_dir . '/wordlist_*.txt') ?: [], 'is_file');
$message = ''; $sel = ''; $content = '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["filename"])) {
    // FIX: basename + realpath + vérification que le chemin est dans wordlist_dir
    $safe = basename((string)$_POST["filename"]);
    $real = realpath($wordlist_dir . '/' . $safe);
    if ($real === false || strpos($real, $wordlist_dir . DIRECTORY_SEPARATOR) !== 0) {
        $message = '⚠️ Fichier non autorisé.';
    } elseif (isset($_POST["content"])) {
        file_put_contents($real, $_POST["content"], LOCK_EX);
        $message = '✅ Wordlist mise à jour !';
        $sel = $safe; $content = $_POST["content"];
    } else {
        $sel = $safe;
        // FIX: lecture depuis chemin validé, pas depuis $_POST directement
        $content = file_get_contents($real);
    }
}
?>
<h2>Modify a wordlist</h2>
<form method="post"><?=vx_csrf_field()?>
<label>Choisir : <select name="filename" onchange="this.form.submit()">
<option value="">-- Choose --</option>
<?php foreach($available as $f): $bn=basename($f); ?>
<option value="<?=htmlspecialchars($bn)?>" <?=$sel===$bn?'selected':''?>><?=htmlspecialchars($bn)?></option>
<?php endforeach; ?></select></label></form>
<?php if($message): ?><p style="color:green"><?=htmlspecialchars($message)?></p><?php endif; ?>
<?php if($sel): ?><form method="post"><?=vx_csrf_field()?>
<input type="hidden" name="filename" value="<?=htmlspecialchars($sel)?>">
<textarea name="content" rows="20" cols="100"><?=htmlspecialchars($content)?></textarea><br>
<input type="submit" value="💾 Sauvegarder"></form><?php endif; ?>
<p><a href="dashboard.php">⬅️ Dashboard</a></p>
