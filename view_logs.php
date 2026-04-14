<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/log_audit.php';
vx_require_admin(true);
$filters = vx_log_filter_params_from_request($_GET);
$allRows = vx_log_records($filters['type'] ?: 'all');
$knownMetiers = [];
foreach ($allRows as $r) { $m = (string)($r['metier'] ?? ''); if ($m !== '') $knownMetiers[$m] = true; }
ksort($knownMetiers);
$rows = vx_log_filter_records($allRows, $filters);
$stats = vx_log_stats($rows);
$health = vx_log_source_health();
$query = http_build_query(array_filter($filters, static fn($v) => $v !== '' && $v !== 'all'));
?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Logs AI</title><link rel="stylesheet" href="style.css"><style>body{background:#0B0C0E;color:#fff;font-family:Inter,Arial,sans-serif;padding:24px}table{border-collapse:collapse;width:100%;background:#121416}th,td{padding:8px;border:1px solid #24303d;text-align:left;font-size:13px;vertical-align:top}th{background:#111827}.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.toolbar input,.toolbar select{padding:8px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#fff}.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;border:none}.muted{color:#9CA3AF}.tag{padding:2px 8px;border-radius:999px;background:#1f2937}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:14px 0 18px}.card{background:#121416;border:1px solid #24303d;border-radius:12px;padding:12px}.sub{font-size:12px;color:#9CA3AF}</style></head><body>
<h1>Audit des logs</h1>
<form method="get" class="toolbar">
<select name="type"><option value="all" <?= $filters['type']==='all'?'selected':'' ?>>Tous</option><option value="audit" <?= $filters['type']==='audit'?'selected':'' ?>>Audit prompts</option><option value="security" <?= $filters['type']==='security'?'selected':'' ?>>Sécurité</option><option value="egress" <?= $filters['type']==='egress'?'selected':'' ?>>Bots/Egress</option></select>
<select name="decision"><option value="">Toutes décisions</option><?php foreach(['allow','allow_with_notice','allow_with_mask','block','block_and_escalate'] as $d): ?><option value="<?= vx_h($d) ?>" <?= $filters['decision']===$d?'selected':'' ?>><?= vx_h($d) ?></option><?php endforeach; ?></select>
<select name="metier"><option value="">Tous métiers</option><?php foreach(array_keys($knownMetiers) as $m): ?><option value="<?= vx_h($m) ?>" <?= $filters['metier']===$m?'selected':'' ?>><?= vx_h($m) ?></option><?php endforeach; ?></select>
<input type="date" name="date_from" value="<?= vx_h($filters['date_from']) ?>">
<input type="date" name="date_to" value="<?= vx_h($filters['date_to']) ?>">
<input type="text" name="q" value="<?= vx_h($filters['q']) ?>" placeholder="Recherche libre">
<button class="btn" type="submit">Filtrer</button>
<a class="btn" href="view_logs.php">Réinitialiser</a>
<a class="btn" href="export_pdf.php<?= $query ? '?' . vx_h($query) : '' ?>">Export PDF</a>
<a class="btn" href="export_csv.php<?= $query ? '?' . vx_h($query) : '' ?>">Export CSV</a>
<a class="btn" href="export_bots_pdf.php<?= $query ? '?' . vx_h($query) : '' ?>">Export bots PDF</a>
</form>
<div class="cards"><div class="card"><div class="sub">Événements filtrés</div><div style="font-size:24px;font-weight:700"><?= (int)$stats['total'] ?></div></div><div class="card"><div class="sub">Blocages</div><div style="font-size:24px;font-weight:700"><?= (int)$stats['blocked'] ?></div></div><div class="card"><div class="sub">Sorties filtrées / notices</div><div style="font-size:24px;font-weight:700"><?= (int)$stats['filtered'] ?></div></div><div class="card"><div class="sub">Escalades</div><div style="font-size:24px;font-weight:700"><?= (int)$stats['escalations'] ?></div></div><div class="card"><div class="sub">Risque moyen</div><div style="font-size:24px;font-weight:700"><?= (int)$stats['avg_risk'] ?>/100</div></div></div>
<p class="muted">Les identifiants et contenus affichés ici sont anonymisés ou tronqués. Les exports réutilisent les filtres courants.</p>
<h2>État des sources de logs</h2>
<table style="margin-bottom:18px"><thead><tr><th>Source</th><th>Statut</th><th>Lignes</th><th>Lignes invalides</th><th>Fichier</th></tr></thead><tbody><?php foreach($health as $src=>$h): ?><tr><td><?= vx_h($src) ?></td><td><?= vx_h(strtoupper((string)$h['status'])) ?></td><td><?= (int)$h['rows'] ?></td><td><?= (int)$h['invalid_rows'] ?></td><td><?= vx_h((string)$h['path']) ?></td></tr><?php endforeach; ?></tbody></table>
<table><thead><tr><th>Timestamp</th><th>Source</th><th>User</th><th>Métier</th><th>Statut</th><th>Décision</th><th>Risque</th><th>Motif</th><th>Détail</th><th>Accès</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="muted">Aucune donnée</td></tr><?php endif; ?>
<?php foreach($rows as $r):
  $src = (string)($r['source'] ?? '');
  $rid = (string)($r['id'] ?? '');
?>
<tr>
<td><?= vx_h((string)($r['timestamp'] ?? '')) ?></td>
<td><span class="tag"><?= vx_h($src) ?></span></td>
<td><?= vx_h((string)($r['user'] ?? '')) ?></td>
<td><?= vx_h((string)($r['metier'] ?? '')) ?></td>
<td><?= vx_h((string)($r['status'] ?? '')) ?></td>
<td><?= vx_h((string)($r['decision'] ?? '')) ?></td>
<td><?= (int)($r['risk_score'] ?? 0) ?></td>
<td><?= vx_h((string)($r['reason'] ?? '')) ?></td>
<td><?= vx_h((string)($r['details'] ?? '')) ?></td>
<td><?php if($src === 'audit' && $rid !== ''): ?>
  <a href="prompt_access_request.php?id=<?= urlencode($rid) ?>"
     style="display:inline-block;padding:3px 9px;background:#1e3a5f;border:1px solid #2563eb;color:#93c5fd;border-radius:7px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap"
     title="<?= vx_h($rid) ?>">🔑 Accès</a>
<?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<p style="margin-top:16px"><a class="btn" href="dashboard.php">Retour dashboard</a></p></body></html>
