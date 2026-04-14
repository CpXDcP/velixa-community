<?php
/**
 * prompt_handler.php — NEUTRALISÉ (v7-fix)
 * Ce fichier legacy simulait la validation avec 3 mots hardcodés.
 * L'analyse est faite dans interface_user.php via inc/security_pipeline.php.
 */
require_once __DIR__ . '/inc/bootstrap.php';
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['error' => 'Endpoint obsolète. Utilisez interface_user.php.']);
exit;
