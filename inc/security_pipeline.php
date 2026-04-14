<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function vx_sp_append_ndjson(string $path, array $entry): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "
", FILE_APPEND | LOCK_EX);
}

function vx_sp_policy_ids_from_rules(array $activeRules): array {
    $policyIds = [];
    if (in_array('rgpd', $activeRules, true)) {
        $policyIds = array_merge($policyIds, ['rgpd.personal_identifiers','rgpd.contact_details','rgpd.online_identifiers','rgpd.financial_identifiers','rgpd.special_categories','rgpd.children_data','rgpd.images_faces','rgpd.data_minimization','rgpd.pseudonymization_anonymization','rgpd.transparency_fairness']);
    }
    if (in_array('iso27001', $activeRules, true)) {
        $policyIds = array_merge($policyIds, ['iso.ip_exposure','iso.secrets_in_prompt']);
    }
    if (in_array('nis2', $activeRules, true)) {
        $policyIds = array_merge($policyIds, ['nis2.cyber_keywords']);
    }
    if (in_array('hipaa', $activeRules, true)) {
        $policyIds = array_merge($policyIds, ['hipaa.ssn','hipaa.phi_sensitive']);
    }
    if (in_array('confidentialite_rh', $activeRules, true)) {
        $policyIds = array_merge($policyIds, ['hr.salary_mentions','hr.performance_reviews']);
    }
    if (in_array('finance', $activeRules, true)) {
        $policyIds = array_merge($policyIds, ['finance.iban_card','finance.tax_ids']);
    }
    if (in_array('confidentialite_legale', $activeRules, true)) {
        $policyIds = array_merge($policyIds, ['legal.confidential_contract']);
    }
    if (in_array('donnees_sante', $activeRules, true)) {
        $policyIds = array_merge($policyIds, ['health.critical_diseases']);
    }
    return array_values(array_unique($policyIds));
}

function vx_sp_text_excerpt(string $text, int $max = 500): string {
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $max ? substr($text, 0, $max) . '…' : $text;
}

function vx_sp_severity_weight(string $severity): int {
    return match (strtolower($severity)) {
        'critical' => 100,
        'high' => 70,
        'medium' => 35,
        'low' => 10,
        default => 20,
    };
}

function vx_sp_rule_category(string $rule): string {
    $prefix = strtolower((string)explode('.', $rule, 2)[0]);
    return match ($prefix) {
        'rgpd' => 'privacy',
        'iso' => 'security',
        'nis2' => 'cyber',
        'hipaa', 'health' => 'health',
        'finance' => 'finance',
        'hr' => 'hr',
        'legal' => 'legal',
        default => 'general',
    };
}

function vx_sp_locate_python(): string {
    // 1) Variable d'environnement explicite
    $env = getenv('PYTHON_BIN');
    if (is_string($env) && $env !== '' && file_exists($env)) return $env;

    // 2) Chercher dans AppData (Python 3.14, 3.13, 3.12, 3.11)
    $username = getenv('USERNAME') ?: '';
    if ($username !== '') {
        foreach (['Python314','Python313','Python312','Python311'] as $v) {
            $p = "C:\Users\{$username}\AppData\Local\Programs\Python\{$v}\python.exe";
            if (file_exists($p)) return $p;
        }
    }
    // 3) Racine C:\
    foreach (['Python314','Python313','Python312','Python311'] as $v) {
        $p = "C:\{$v}\python.exe";
        if (file_exists($p)) return $p;
    }
    return 'python';
}

function vx_sp_run_prompt_python(string $text, array $activeRules): array {
    $script = dirname(__DIR__) . '/analyse_prompt.py';
    if ($text === '' || !file_exists($script)) return [];
    $python = vx_sp_locate_python();
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($text) . ' ' . escapeshellarg(json_encode(array_values($activeRules), JSON_UNESCAPED_UNICODE)) . ' 2>&1';
    $output = shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') return [];
    $data = json_decode(trim($output), true);
    if (!is_array($data)) return [];
    $viol = isset($data['violations']) && is_array($data['violations']) ? $data['violations'] : [];
    $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : [];
    return ['result' => $data, 'violations' => $viol, 'context' => $context];
}

function vx_sp_simple_scan(string $text, array $activeRules, string $phase = 'input'): array {
    $viol = [];
    $add = function(string $rule, string $reason, string $severity = 'medium', string $source = 'regex') use (&$viol): void {
        $viol[] = ['rule' => $rule, 'reason' => $reason, 'severity' => $severity, 'source' => $source, 'category' => vx_sp_rule_category($rule)];
    };
    if ($text === '') return $viol;

    if (in_array('rgpd', $activeRules, true)) {
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text)) $add('rgpd.contact_details', $phase === 'output' ? 'Adresse e-mail détectée dans la réponse' : 'Adresse e-mail détectée dans le prompt', 'high');
        if (preg_match('/\b(?:\+?\d{1,3}[\s.-]?)?(?:\(?\d{2,4}\)?[\s.-]?)?\d{2,4}[\s.-]?\d{2,4}[\s.-]?\d{2,4}\b/u', $text)) $add('rgpd.personal_identifiers', 'Donnée potentiellement personnelle détectée', 'medium');
    }
    if (in_array('iso27001', $activeRules, true)) {
        if (preg_match('/\\b(?:\\d{1,3}\\.){3}\\d{1,3}\\b/', $text)) $add('iso.ip_exposure', 'Adresse IP détectée', 'medium');
        if (preg_match('/\b(?:api[_-]?key|secret|token|password|mot de passe|passwd)\b/i', $text)) $add('iso.secrets_in_prompt', 'Secret ou identifiant sensible détecté', 'high');
    }
    if (in_array('hipaa', $activeRules, true) || in_array('donnees_sante', $activeRules, true)) {
        if (preg_match('/\\b\\d{3}-\\d{2}-\\d{4}\\b/', $text)) $add('hipaa.ssn', 'Identifiant sensible de type SSN détecté', 'high');
        if (preg_match('/\b(cancer|hiv|vih|diagnostic|traitement|hospitalisation|m[eé]dical|patient|ordonnance)\b/ui', $text)) $add('hipaa.phi_sensitive', 'Contexte santé sensible détecté', 'high');
    }
    if (in_array('finance', $activeRules, true)) {
        if (preg_match('/\\b[A-Z]{2}\\d{2}[A-Z0-9]{10,30}\\b/', strtoupper($text))) $add('finance.iban_card', 'IBAN potentiel détecté', 'high');
        if (preg_match('/(?:\\d[ -]*?){13,19}/', $text)) $add('finance.iban_card', 'Numéro de carte potentiel détecté', 'high');
    }
    if (in_array('confidentialite_legale', $activeRules, true) && preg_match('/\b(confidentiel|nda|contrat|clause|litige|avocat)\b/ui', $text)) {
        $add('legal.confidential_contract', 'Contexte juridique confidentiel détecté', 'medium');
    }
    if (in_array('confidentialite_rh', $activeRules, true) && preg_match('/\b(salaire|évaluation|entretien|performance|licenciement|recrutement)\b/ui', $text)) {
        $add('hr.salary_mentions', 'Contexte RH sensible détecté', 'medium');
    }
    return $viol;
}

function vx_sp_llm_scan(string $text, array $activeRules): array {
    if ($text === '' || !function_exists('run_policies')) return [];
    $policyIds = vx_sp_policy_ids_from_rules($activeRules);
    if (!$policyIds) return [];
    $res = run_policies($text, $policyIds);
    $viols = (isset($res['violations']) && is_array($res['violations'])) ? $res['violations'] : [];
    $out = [];
    foreach ($viols as $v) {
        if (is_string($v)) {
            $out[] = ['rule' => $v, 'reason' => 'Violation détectée par Phi-3 Mini', 'severity' => 'medium', 'source' => 'phi3-mini', 'category' => vx_sp_rule_category($v)];
            continue;
        }
        if (!is_array($v)) continue;
        $rule = (string)($v['rule'] ?? $v['id'] ?? 'rule');
        $out[] = [
            'rule' => $rule,
            'reason' => (string)($v['reason'] ?? 'Violation détectée par Phi-3 Mini'),
            'severity' => (string)($v['severity'] ?? 'medium'),
            'source' => (string)($v['source'] ?? 'phi3-mini'),
            'category' => (string)($v['category'] ?? vx_sp_rule_category($rule)),
        ];
    }
    return $out;
}

function vx_sp_merge_violations(array ...$sets): array {
    $out = [];
    $seen = [];
    foreach ($sets as $set) {
        foreach ($set as $v) {
            $rule = (string)($v['rule'] ?? ($v['id'] ?? 'rule'));
            $reason = (string)($v['reason'] ?? 'Violation');
            $severity = strtolower((string)($v['severity'] ?? 'medium'));
            $source = (string)($v['source'] ?? 'engine');
            $category = (string)($v['category'] ?? vx_sp_rule_category($rule));
            $key = $rule . '|' . $reason;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['rule' => $rule, 'reason' => $reason, 'severity' => $severity, 'source' => $source, 'category' => $category, 'score' => vx_sp_severity_weight($severity)];
        }
    }
    return $out;
}

function vx_sp_decide(array $violations, string $phase = 'input'): array {
    if (!$violations) {
        return ['decision' => 'allow', 'reason' => 'Aucune violation détectée', 'risk_score' => 0, 'should_escalate' => false];
    }
    $risk = 0;
    $hasCritical = false;
    $hasHigh = false;
    $categories = [];
    foreach ($violations as $v) {
        $sev = strtolower((string)($v['severity'] ?? 'medium'));
        $risk += vx_sp_severity_weight($sev);
        $hasCritical = $hasCritical || $sev === 'critical';
        $hasHigh = $hasHigh || $sev === 'high';
        $categories[(string)($v['category'] ?? 'general')] = true;
    }
    $multiCategory = count($categories) >= 2;
    $risk = min(100, $risk);

    if ($phase === 'output') {
        if ($hasCritical || $risk >= 90) {
            return ['decision' => 'allow_with_mask', 'reason' => 'Réponse fortement filtrée avant restitution', 'risk_score' => $risk, 'should_escalate' => true];
        }
        // FIX v7: seuil output abaissé
        if ($hasHigh || $risk >= 40) {
            return ['decision' => 'allow_with_mask', 'reason' => 'Réponse filtrée avant restitution', 'risk_score' => $risk, 'should_escalate' => $multiCategory];
        }
        return ['decision' => 'allow_with_notice', 'reason' => 'Réponse revue par le contrôle de sortie', 'risk_score' => $risk, 'should_escalate' => false];
    }

    if ($hasCritical || $risk >= 90) {
        return ['decision' => 'block_and_escalate', 'reason' => 'Contenu bloqué et signalé pour revue', 'risk_score' => $risk, 'should_escalate' => true];
    }
    // FIX v7: seuil abaissé 55→40 pour bloquer les violations contextuelles multi-catégories
    if ($hasHigh || $risk >= 40 || $multiCategory) {
        return ['decision' => 'block', 'reason' => 'Contenu bloqué par les politiques actives', 'risk_score' => $risk, 'should_escalate' => $multiCategory];
    }
    return ['decision' => 'allow_with_notice', 'reason' => 'Contenu autorisé avec avertissement', 'risk_score' => $risk, 'should_escalate' => false];
}

function vx_sp_mask_text(string $text, array $activeRules): string {
    $masked = $text;
    if (in_array('rgpd', $activeRules, true)) {
        $masked = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $masked) ?? $masked;
        $masked = preg_replace('/\b(?:\+?\d{1,3}[\s.-]?)?(?:\(?\d{2,4}\)?[\s.-]?)?\d{2,4}[\s.-]?\d{2,4}[\s.-]?\d{2,4}\b/u', '[redacted-phone]', $masked) ?? $masked;
    }
    if (in_array('iso27001', $activeRules, true)) {
        $masked = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[redacted-ip]', $masked) ?? $masked;
        $masked = preg_replace('/\b(api[_-]?key|secret|token|password|mot de passe|passwd)\s*[:=]?\s*[^\s,;]+/iu', '$1: [redacted-secret]', $masked) ?? $masked;
    }
    if (in_array('hipaa', $activeRules, true) || in_array('donnees_sante', $activeRules, true)) {
        $masked = preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[redacted-ssn]', $masked) ?? $masked;
        $masked = preg_replace('/\b(cancer|hiv|vih|diagnostic|traitement|hospitalisation|m[eé]dical|patient|ordonnance)\b/ui', '[redacted-health]', $masked) ?? $masked;
    }
    if (in_array('finance', $activeRules, true)) {
        $masked = preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', '[redacted-iban]', strtoupper($masked)) ?? $masked;
        $masked = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[redacted-card]', $masked) ?? $masked;
    }
    return $masked;
}

function vx_sp_build_summary(array $violations): array {
    $categories = [];
    $rules = [];
    foreach ($violations as $v) {
        $categories[(string)($v['category'] ?? 'general')] = true;
        $rules[] = (string)($v['rule'] ?? 'rule');
    }
    return [
        'count' => count($violations),
        'categories' => array_values(array_keys($categories)),
        'rules' => array_values(array_unique($rules)),
    ];
}

function vx_sp_analyze_input(string $text, array $activeRules): array {
    $simple = vx_sp_simple_scan($text, $activeRules, 'input');
    $python = vx_sp_run_prompt_python($text, $activeRules);
    $pyViol = is_array($python) ? ($python['violations'] ?? []) : [];
    $pyContext = is_array($python) ? ($python['context'] ?? []) : [];
    $llm = vx_sp_llm_scan($text, $activeRules);
    $viol = vx_sp_merge_violations($simple, $pyViol, $llm);
    $decision = vx_sp_decide($viol, 'input');
    $summary = vx_sp_build_summary($viol);
    if ($pyContext) {
        $summary['context'] = $pyContext;
    }
    return [
        'engine' => 'prompt-analyzer+phi3-mini+rules',
        'simple' => $simple,
        'python' => $python['result'] ?? [],
        'llm' => $llm,
        'violations' => $viol,
        'decision' => $decision['decision'],
        'reason' => $decision['reason'],
        'risk_score' => $decision['risk_score'],
        'should_escalate' => $decision['should_escalate'],
        'summary' => $summary,
    ];
}

function vx_sp_filter_output(string $text, array $activeRules): array {
    $simple = vx_sp_simple_scan($text, $activeRules, 'output');
    // FIX v7: ajout analyse Python sur les réponses (parité avec l'analyse input)
    $python = vx_sp_run_prompt_python($text, $activeRules);
    $pyViol = is_array($python) && isset($python['violations']) ? $python['violations'] : [];
    $viol = vx_sp_merge_violations($simple, $pyViol);
    $decision = vx_sp_decide($viol, 'output');
    $filtered = $text;
    $modified = false;
    if ($viol) {
        $filtered = vx_sp_mask_text($text, $activeRules);
        $modified = $filtered !== $text;
    }
    return [
        'engine' => 'output-guard',
        'violations' => $viol,
        'decision' => $decision['decision'],
        'reason' => $decision['reason'],
        'risk_score' => $decision['risk_score'],
        'should_escalate' => $decision['should_escalate'],
        'summary' => vx_sp_build_summary($viol),
        'filtered_text' => $filtered,
        'modified' => $modified,
    ];
}
