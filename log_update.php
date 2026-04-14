<?php
require_once __DIR__ . '/inc/bootstrap.php';
if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Non authentifié.']);
    exit();
}
$data   = json_decode(file_get_contents("php://input"), true) ?? [];
// FIX: ne jamais logger le prompt complet en clair
$status = $data['status'] ?? 'accepté';
$motif  = is_array($data['motif'] ?? null) ? $data['motif'] : [];
$username = vx_anonymize_value((string)$_SESSION['username']);
$now    = date('Y-m-d H:i:s');
$log    = [$now, $username, '[prompt non journalisé]', $status, implode(' | ', $motif)];
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$fp = @fopen($logDir . '/activity.csv', 'a');
if ($fp !== false) {
    fputcsv($fp, $log, ';');
    fclose($fp);
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur écriture journal.']);
}
