<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/log_audit.php';
vx_require_admin(true);
$filters = vx_log_filter_params_from_request($_GET);
$rows = vx_log_filter_records(vx_log_records($filters['type'] ?: 'all'), $filters);
$filename = 'export_audit_logs_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'w');
fputcsv($output, ['Timestamp', 'Source', 'Utilisateur', 'Métier', 'Statut', 'Décision', 'Risque', 'Motif', 'Détail'], ';');
foreach ($rows as $log) {
    fputcsv($output, [
        $log['timestamp'] ?? '',
        $log['source'] ?? '',
        $log['user'] ?? '',
        $log['metier'] ?? '',
        $log['status'] ?? '',
        $log['decision'] ?? '',
        $log['risk_score'] ?? 0,
        $log['reason'] ?? '',
        $log['details'] ?? '',
    ], ';');
}
fclose($output);
exit;
