<?php
require_once __DIR__ . '/bootstrap.php';

function vx_load_json_array_file(string $path): array {
    if (!file_exists($path)) return [];
    $raw = json_decode((string)file_get_contents($path), true);
    return is_array($raw) ? array_values($raw) : [];
}

function vx_load_ndjson_file(string $path): array {
    $rows = [];
    if (!file_exists($path)) return $rows;
    foreach ((file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        $j = json_decode($line, true);
        if (is_array($j)) $rows[] = $j;
    }
    return $rows;
}

function vx_sp_excerpt_for_log(string $text, int $limit = 140): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) return mb_substr($text, 0, $limit, 'UTF-8') . '…';
    if (strlen($text) > $limit) return substr($text, 0, $limit) . '…';
    return $text;
}

function vx_log_source_files(): array {
    return [
        'audit' => __DIR__ . '/../audit_logs.json',
        'security' => __DIR__ . '/../logs/security_events.ndjson',
        'egress' => __DIR__ . '/../logs/egress.ndjson',
    ];
}

function vx_log_source_health(): array {
    $out = [];
    foreach (vx_log_source_files() as $source => $path) {
        $exists = file_exists($path);
        $readable = $exists && is_readable($path);
        $rows = 0;
        $invalid = 0;
        if ($exists && $readable) {
            if (str_ends_with($path, '.json')) {
                $raw = json_decode((string)file_get_contents($path), true);
                if (is_array($raw)) {
                    $rows = count($raw);
                } else {
                    $invalid = 1;
                }
            } else {
                foreach ((file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
                    $j = json_decode($line, true);
                    if (is_array($j)) $rows++; else $invalid++;
                }
            }
        }
        $out[$source] = [
            'path' => $path,
            'exists' => $exists,
            'readable' => $readable,
            'rows' => $rows,
            'invalid_rows' => $invalid,
            'status' => (!$exists || !$readable) ? 'warn' : ($invalid > 0 ? 'warn' : 'ok'),
        ];
    }
    return $out;
}

function vx_log_records(string $type = 'all'): array {
    $records = [];
    if ($type === 'all' || $type === 'audit') {
        foreach (vx_load_json_array_file(__DIR__ . '/../audit_logs.json') as $row) {
            $records[] = [
                'source' => 'audit',
                'id' => (string)($row['id'] ?? ''),
                'timestamp' => (string)($row['timestamp'] ?? ''),
                'user' => vx_anonymize_value((string)($row['username'] ?? '')),
                'metier' => (string)($row['metier'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'decision' => strtoupper((string)($row['status'] ?? '')) === 'OK' ? 'allow' : 'block',
                'risk_score' => (int)($row['risk_score'] ?? 0),
                'reason' => (string)($row['reason'] ?? ''),
                'details' => vx_sp_excerpt_for_log((string)($row['prompt'] ?? '')),
            ];
        }
    }
    if ($type === 'all' || $type === 'security') {
        foreach (vx_load_ndjson_file(__DIR__ . '/../logs/security_events.ndjson') as $row) {
            $records[] = [
                'source' => 'security',
                'timestamp' => (string)($row['ts'] ?? ''),
                'user' => (string)($row['user'] ?? ''),
                'metier' => (string)($row['metier'] ?? ''),
                'status' => (string)($row['phase'] ?? 'security'),
                'decision' => (string)($row['decision'] ?? ''),
                'risk_score' => (int)($row['risk_score'] ?? 0),
                'reason' => (string)($row['reason'] ?? ''),
                'details' => vx_sp_excerpt_for_log((string)($row['prompt_excerpt'] ?? ($row['response_excerpt'] ?? ($row['summary_excerpt'] ?? '')))),
            ];
        }
    }
    if ($type === 'all' || $type === 'egress') {
        foreach (vx_load_ndjson_file(__DIR__ . '/../logs/egress.ndjson') as $row) {
            $host = (string)($row['host'] ?? '');
            if ($host !== '') {
                $parts = explode('.', $host);
                if (count($parts) > 2) $host = '*.' . implode('.', array_slice($parts, -2));
            }
            $records[] = [
                'source' => 'egress',
                'timestamp' => (string)($row['ts'] ?? ''),
                'user' => vx_anonymize_value((string)($row['actor']['id'] ?? '')),
                'metier' => (string)($row['actor']['metier'] ?? ''),
                'status' => (string)($row['method'] ?? ''),
                'decision' => (string)($row['policy']['decision'] ?? ''),
                'risk_score' => (int)($row['policy']['risk_score'] ?? 0),
                'reason' => $host,
                'details' => vx_sp_excerpt_for_log((string)($row['url'] ?? '')),
            ];
        }
    }
    usort($records, static fn(array $a, array $b): int => strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? '')));
    return $records;
}

function vx_log_filter_params_from_request(array $src): array {
    return [
        'type' => (string)($src['type'] ?? 'all'),
        'decision' => trim((string)($src['decision'] ?? '')),
        'metier' => trim((string)($src['metier'] ?? '')),
        'q' => trim((string)($src['q'] ?? '')),
        'date_from' => trim((string)($src['date_from'] ?? '')),
        'date_to' => trim((string)($src['date_to'] ?? '')),
    ];
}

function vx_log_filter_records(array $rows, array $filters): array {
    return array_values(array_filter($rows, static function(array $r) use ($filters): bool {
        if (($filters['decision'] ?? '') !== '' && strcasecmp((string)($r['decision'] ?? ''), (string)$filters['decision']) !== 0) return false;
        if (($filters['metier'] ?? '') !== '' && strcasecmp((string)($r['metier'] ?? ''), (string)$filters['metier']) !== 0) return false;
        if (($filters['q'] ?? '') !== '') {
            $hay = strtolower((string)json_encode($r, JSON_UNESCAPED_UNICODE));
            if (!str_contains($hay, strtolower((string)$filters['q']))) return false;
        }
        $ts = (string)($r['timestamp'] ?? '');
        if (($filters['date_from'] ?? '') !== '' && substr($ts, 0, 10) < $filters['date_from']) return false;
        if (($filters['date_to'] ?? '') !== '' && substr($ts, 0, 10) > $filters['date_to']) return false;
        return true;
    }));
}

function vx_log_stats(array $rows): array {
    $stats = ['total' => count($rows), 'blocked' => 0, 'filtered' => 0, 'escalations' => 0, 'avg_risk' => 0, 'sources' => [], 'metiers' => [], 'decisions' => []];
    $riskTotal = 0;
    foreach ($rows as $r) {
        $decision = (string)($r['decision'] ?? '');
        if (in_array($decision, ['block', 'block_and_escalate'], true)) $stats['blocked']++;
        if (in_array($decision, ['allow_with_notice', 'allow_with_mask'], true)) $stats['filtered']++;
        if ($decision === 'block_and_escalate') $stats['escalations']++;
        $riskTotal += (int)($r['risk_score'] ?? 0);
        $src = (string)($r['source'] ?? '');
        if ($src !== '') $stats['sources'][$src] = ($stats['sources'][$src] ?? 0) + 1;
        $met = (string)($r['metier'] ?? '');
        if ($met !== '') $stats['metiers'][$met] = ($stats['metiers'][$met] ?? 0) + 1;
        if ($decision !== '') $stats['decisions'][$decision] = ($stats['decisions'][$decision] ?? 0) + 1;
    }
    $stats['avg_risk'] = $stats['total'] ? (int)round($riskTotal / $stats['total']) : 0;
    arsort($stats['sources']); arsort($stats['metiers']); arsort($stats['decisions']);
    return $stats;
}
