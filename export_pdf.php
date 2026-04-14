<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/log_audit.php';
vx_require_admin(true);

$filters = vx_log_filter_params_from_request($_GET);
$rows    = vx_log_filter_records(vx_log_records($filters['type'] ?: 'all'), $filters);
$stats   = vx_log_stats($rows);
$date    = date('Y-m-d H:i:s');
$rows200 = array_slice($rows, 0, 200);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>VELIXA — Rapport audit <?= htmlspecialchars($date) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 11px; color: #1b1f23; background: #fff; padding: 20px; }
  h1 { font-size: 18px; margin-bottom: 4px; }
  .subtitle { color: #555; font-size: 12px; margin-bottom: 16px; }
  .summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
  .stat-box { border: 1px solid #d0d7de; border-radius: 6px; padding: 10px 16px; min-width: 120px; text-align: center; }
  .stat-box .val { font-size: 22px; font-weight: 700; color: #0969da; }
  .stat-box .lbl { font-size: 10px; color: #555; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th { background: #f6f8fa; border: 1px solid #d0d7de; padding: 6px 8px; text-align: left; font-size: 10px; text-transform: uppercase; }
  td { border: 1px solid #d0d7de; padding: 5px 8px; vertical-align: top; }
  tr:nth-child(even) td { background: #f6f8fa; }
  .badge { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 9px; font-weight: 700; }
  .allow { background: #dafbe1; color: #1a7f37; }
  .block { background: #ffebe9; color: #cf222e; }
  .notice { background: #fff8c5; color: #9a6700; }
  .noPrint { text-align: center; margin-bottom: 16px; }
  .noPrint button { padding: 10px 24px; background: #0969da; color: #fff; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; margin-right: 8px; }
  .noPrint a { padding: 10px 24px; background: #555; color: #fff; border-radius: 6px; font-size: 14px; text-decoration: none; }
  @media print {
    .noPrint { display: none; }
    body { padding: 0; }
  }
</style>
</head>
<body>
<div class="noPrint">
  <button onclick="window.print()">🖨️ Imprimer / Sauvegarder en PDF</button>
  <a href="view_logs.php">⬅ Retour</a>
</div>

<h1>VELIXA — Rapport d'audit anonymisé</h1>
<div class="subtitle">Généré le <?= htmlspecialchars($date) ?> · <?= count($rows200) ?> événements (max 200)</div>

<div class="summary">
  <div class="stat-box"><div class="val"><?= (int)$stats['total'] ?></div><div class="lbl">Total</div></div>
  <div class="stat-box"><div class="val"><?= (int)$stats['blocked'] ?></div><div class="lbl">Bloqués</div></div>
  <div class="stat-box"><div class="val"><?= (int)$stats['filtered'] ?></div><div class="lbl">Filtrés</div></div>
  <div class="stat-box"><div class="val"><?= (int)$stats['escalations'] ?></div><div class="lbl">Escalades</div></div>
  <div class="stat-box"><div class="val"><?= (int)$stats['avg_risk'] ?>/100</div><div class="lbl">Risque moyen</div></div>
</div>

<table>
  <thead>
    <tr>
      <th>Date</th><th>Source</th><th>Utilisateur</th><th>Métier</th>
      <th>Décision</th><th>Risque</th><th>Motif</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows200 as $r):
    $dec = (string)($r['decision'] ?? '');
    $cls = str_starts_with($dec,'allow') ? (str_contains($dec,'notice')||str_contains($dec,'mask') ? 'notice' : 'allow') : 'block';
  ?>
    <tr>
      <td><?= vx_h(substr((string)($r['timestamp']??''),0,16)) ?></td>
      <td><?= vx_h((string)($r['source']??'')) ?></td>
      <td><?= vx_h((string)($r['user']??'')) ?></td>
      <td><?= vx_h((string)($r['metier']??'')) ?></td>
      <td><span class="badge <?= $cls ?>"><?= vx_h($dec) ?></span></td>
      <td><?= (int)($r['risk_score']??0) ?></td>
      <td><?= vx_h(substr((string)($r['reason']??''),0,80)) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if(empty($rows200)): ?>
    <tr><td colspan="7" style="text-align:center;color:#888;padding:20px">Aucune donnée.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</body>
</html>
