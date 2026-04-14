<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/log_audit.php';
vx_require_admin(true);
$connectors = file_exists(__DIR__.'/config/directory_connectors.json') ? json_decode((string)file_get_contents(__DIR__.'/config/directory_connectors.json'), true) : [];
$pipelines = file_exists(__DIR__.'/config/directory_pipelines.json') ? json_decode((string)file_get_contents(__DIR__.'/config/directory_pipelines.json'), true) : [];
$providers = file_exists(__DIR__.'/config/providers.json') ? json_decode((string)file_get_contents(__DIR__.'/config/providers.json'), true) : [];
$enabledConnectors = 0; foreach (($connectors['connectors'] ?? []) as $c) if (!empty($c['enabled'])) $enabledConnectors++;
$enabledPipelines = 0; foreach (($pipelines['pipelines'] ?? []) as $p) if (!empty($p['enabled'])) $enabledPipelines++;
$providerEnabled = 0; foreach (($providers['global']['providers'] ?? []) as $id=>$cfg) if (!empty($cfg['enabled'])) $providerEnabled++;
$checks = [];
$checks[] = ['Session/CSRF', !empty($_SESSION['_vx_csrf']) ? 'ok' : 'warn', 'Jeton CSRF initialisé'];
$checks[] = ['Secure store key', file_exists(__DIR__.'/data/secure/master.key') || file_exists(__DIR__.'/keys/master.key') ? 'ok' : 'warn', 'Clé de chiffrement locale'];
$checks[] = ['Provider config', file_exists(__DIR__.'/config/providers.json') ? 'ok' : 'warn', 'Configuration des APIs'];
$checks[] = ['Enabled providers', $providerEnabled > 0 ? 'ok' : 'warn', 'Providers activés: '.$providerEnabled];
$checks[] = ['LDAP connectors', file_exists(__DIR__.'/config/directory_connectors.json') ? 'ok' : 'warn', 'Connecteurs annuaire présents'];
$checks[] = ['LDAP enabled connectors', $enabledConnectors > 0 ? 'ok' : 'warn', 'Connecteurs LDAP/AD activés: '.$enabledConnectors];
$checks[] = ['LDAP pipelines', $enabledPipelines > 0 ? 'ok' : 'warn', 'Pipelines LDAP/AD actifs: '.$enabledPipelines];
$checks[] = ['Uploads protected', file_exists(__DIR__.'/uploads/.htaccess') ? 'ok' : 'warn', 'Uploads non exécutables'];
$checks[] = ['Uploads writable', is_dir(__DIR__.'/uploads') && is_writable(__DIR__.'/uploads') ? 'ok' : 'warn', 'Répertoire uploads accessible en écriture'];
$checks[] = ['Logs protected', file_exists(__DIR__.'/logs/.htaccess') ? 'ok' : 'warn', 'Logs protégés par Apache'];
$checks[] = ['Logs writable', is_dir(__DIR__.'/logs') && is_writable(__DIR__.'/logs') ? 'ok' : 'warn', 'Répertoire logs accessible en écriture'];
$checks[] = ['Security pipeline', file_exists(__DIR__.'/inc/security_pipeline.php') ? 'ok' : 'warn', 'Pipeline d’analyse'];
$checks[] = ['Prompt analyzer', file_exists(__DIR__.'/analyse_prompt.py') ? 'ok' : 'warn', 'Analyse locale prompt'];
$checks[] = ['Document analyzer', file_exists(__DIR__.'/analyse_file.py') ? 'ok' : 'warn', 'Analyse locale documents'];
$checks[] = ['Directory sync job', file_exists(__DIR__.'/jobs/directory_sync.php') ? 'ok' : 'warn', 'Synchronisation LDAP/AD'];
$checks[] = ['Audit export PDF', file_exists(__DIR__.'/export_pdf.php') ? 'ok' : 'warn', 'Rapport d’audit PDF'];
$checks[] = ['Bots export PDF', file_exists(__DIR__.'/export_bots_pdf.php') ? 'ok' : 'warn', 'Rapport bots PDF'];
foreach (vx_log_source_health() as $src => $h) {
    $checks[] = ['Log source: '.$src, (string)$h['status'], 'rows='.(int)$h['rows'].', invalid='.(int)$h['invalid_rows']];
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Health Check</title><link rel="stylesheet" href="style.css"></head><body style="padding:24px;background:#0B0C0E;color:#fff;font-family:Inter,Arial,sans-serif"><h1>VELIXA — Health Check</h1><table style="width:100%;max-width:980px;border-collapse:collapse;background:#121416"><tr><th style="text-align:left;padding:10px;border-bottom:1px solid #1f242b">Check</th><th style="text-align:left;padding:10px;border-bottom:1px solid #1f242b">Status</th><th style="text-align:left;padding:10px;border-bottom:1px solid #1f242b">Détail</th></tr><?php foreach($checks as [$name,$status,$detail]): ?><tr><td style="padding:10px;border-bottom:1px solid #1f242b"><?= vx_h($name) ?></td><td style="padding:10px;border-bottom:1px solid #1f242b;color:<?= $status==='ok'?'#10B981':'#F59E0B' ?>"><?= vx_h(strtoupper($status)) ?></td><td style="padding:10px;border-bottom:1px solid #1f242b;color:#cbd5e1"><?= vx_h($detail) ?></td></tr><?php endforeach; ?></table><p style="margin-top:16px"><a href="dashboard.php" style="color:#93c5fd">Retour dashboard</a></p></body></html>
