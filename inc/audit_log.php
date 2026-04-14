<?php
function vx_append_audit_log(array $entry, string $file = 'audit_logs.json'): bool {
    $list = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        $json = json_decode($raw, true);
        if (is_array($json)) $list = (isset($json['logs']) && is_array($json['logs'])) ? $json['logs'] : $json;
    }
    $entry['timestamp'] = $entry['timestamp'] ?? date('Y-m-d H:i:s');
    $list[] = $entry;

    $payload = json_encode($list, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    $fp = @fopen($file, 'c+'); if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0); fwrite($fp, $payload); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    return true;
}

function vx_extract_rule_and_reason(string $msg): array {
    $rule = ''; $reason = '';
    $parts = array_map('trim', preg_split('/\s*:\s*/u', $msg));
    foreach ($parts as $i => $p) {
        if (preg_match('/[a-z0-9][\w.\-\/]*\.[\w.\-]+/i', $p)) {
            $rule   = $p;
            $reason = $parts[$i+1] ?? '';
            break;
        }
    }
    if ($rule === '') $rule = 'Règle non catégorisée';
    $reason = preg_replace('/([A-Z0-9._%+\-])([A-Z0-9._%+\-]*)@/i', '$1***@', $reason);
    return [$rule, $reason];
}
