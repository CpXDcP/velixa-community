<?php
require_once "config_ollama.php";

/* =========================================================
   ENFORCER : oblige le modèle à répondre en JSON strict
   ========================================================= */
const JSON_ENFORCER = <<<TXT
Tu es un validateur. Réponds UNIQUEMENT en JSON strict (aucun texte autour, aucune explication, pas de commentaires).
Format attendu :
{
  "rule": "string",
  "violation": true|false,
  "reason": "string non vide",
  "snippets": ["optionnel", "..."]
}
Ne mets AUCUN commentaire // ni trailing comma.
TXT;

/* =========================================================
   SANITIZER JSON (tolère les sorties non strictes)
   ========================================================= */
function velixa_sanitize_json_like(string $s): string {
    // Enlève éventuellement le BOM + normalise les fins de ligne
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    $s = str_replace("\r\n", "\n", $s);

    // Retire commentaires style JS/C
    $s = preg_replace('~//.*?$~m', '', $s);
    $s = preg_replace('~/\*.*?\*/~s', '', $s);

    // Supprime virgules traînantes avant } ou ]
    $s = preg_replace('/,\s*([}\]])/', '$1', $s);

    // Backticks -> guillemets
    $s = str_replace('`', '"', $s);

    return trim($s);
}

/** Essaie de parser la réponse modèle en JSON, sinon fallback sur le dernier bloc {…}. */
function velixa_parse_model_json(string $raw) {
    $clean = velixa_sanitize_json_like($raw);
    $data  = json_decode($clean, true);
    if (is_array($data)) return $data;

    if (preg_match('/\{.*\}$/s', $clean, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) return $data;
    }
    return null;
}

/* =========================================================
   Appel Ollama (/api/generate) — recompose le flux NDJSON
   ========================================================= */
function call_ollama($model, $prompt) {
    // On force la sortie JSON côté Ollama et on fige la température
    $payload = json_encode([
        "model"   => $model,
        "prompt"  => $prompt,
        "format"  => "json",              // <<< important : sortie JSON stricte
        "options" => [
            "temperature" => 0.0,         // <<< réduit les fantaisies
            "seed"        => OLLAMA_SEED ?? 42,
            // "num_ctx"  => 4096,        // décommente si besoin
        ]
    ]);

    $ch = curl_init(OLLAMA_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '127.0.0.1,localhost',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $text = "";
    foreach (explode("\n", trim((string)$raw)) as $line) {
        if ($line === "") continue;
        $obj = json_decode($line, true);
        if (isset($obj["response"])) $text .= $obj["response"];
    }
    return trim($text);
}

/* =========================================================
   Chargement des policies (toutes familles) et indexation
   ========================================================= */
function load_all_policies(): array {
    $base = __DIR__ . "/policies/";
    $files = [
        "rgpd"    => "rgpd_policies.json",
        "iso"     => "iso_policies.json",
        "nis2"    => "nis2_policies.json",
        "hipaa"   => "hipaa_policies.json",
        "hr"      => "hr_policies.json",
        "finance" => "finance_policies.json",
        "legal"   => "legal_policies.json",
        "health"  => "health_policies.json",
    ];

    $families = [];
    foreach ($files as $family => $fname) {
        $path = $base . $fname;
        if (!file_exists($path)) continue;
        $json = json_decode(file_get_contents($path), true);
        if (!$json || !is_array($json)) continue;

        $rootKey = null;
        foreach ($json as $k => $v) {
            if (is_array($v) && str_ends_with($k, "_policies")) { $rootKey = $k; break; }
        }
        if (!$rootKey || !isset($json[$rootKey])) continue;

        $families[$family] = [
            "list"  => $json[$rootKey],
            "index" => []
        ];
        foreach ($json[$rootKey] as $p) {
            if (isset($p["id"])) $families[$family]["index"][$p["id"]] = $p;
        }
    }
    return $families;
}

/* =========================================================
   Sélecteurs “pertinents” par famille (pré-filtrage)
   ========================================================= */
function select_relevant_rgpd_policies(string $text, array $requested): array {
    $sel = [];
    $hasEmail = (bool)preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text);
    $hasIP    = (bool)preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $text);
    $hasIBAN  = (bool)preg_match('/\b[A-Z]{2}[0-9]{2}[ ]?(?:[0-9]{4}[ ]?){3,5}[0-9]{0,2}\b/', $text);
    $hasHealth= (bool)preg_match('/\\b(cancer|vih|alzheimer|diab[èe]te|hépatite|d[eé]pression|anxi[eé]t[eé]|burnout|psychiatr|psycholog|sant[eé] mentale|sant[eé]|traitement|maladie|ordonnance|handicap|th[eé]rapie)\\b/i', $text);
    $hasChild = (bool)preg_match('/\b(enfant|mineur|coll[eè]ge|lyc[ée]e|classe de)\b/i', $text);
    $hasImage = (bool)preg_match('/\b(photo|image|selfie|vid[eé]o|visage|face)\b/i', $text);

    if ($hasEmail) {
        foreach (['rgpd.personal_identifiers','rgpd.contact_details'] as $id) {
            if (in_array($id, $requested, true)) $sel[] = $id;
        }
    }
    if ($hasIP   && in_array('rgpd.online_identifiers', $requested, true))       $sel[] = 'rgpd.online_identifiers';
    if ($hasIBAN && in_array('rgpd.financial_identifiers', $requested, true))    $sel[] = 'rgpd.financial_identifiers';
    if ($hasHealth && in_array('rgpd.special_categories', $requested, true))     $sel[] = 'rgpd.special_categories';
    if ($hasChild  && in_array('rgpd.children_data', $requested, true))          $sel[] = 'rgpd.children_data';
    if ($hasImage  && in_array('rgpd.images_faces', $requested, true))           $sel[] = 'rgpd.images_faces';

    if ($hasEmail || $hasIP || $hasIBAN || $hasHealth || $hasChild || $hasImage) {
        foreach (['rgpd.pseudonymization_anonymization','rgpd.data_minimization'] as $id) {
            if (in_array($id, $requested, true)) $sel[] = $id;
        }
    }
    return array_values(array_unique($sel));
}

function select_relevant_iso_policies(string $text, array $requested): array {
    $sel = [];
    $hasIP = (bool)preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $text);
    $hasSecret = (bool)preg_match(
        '/(password\s*[:=]|passwd\s*[:=]|token|api[_\- ]?key|ssh-rsa|-----BEGIN PRIVATE KEY-----|AKIA[0-9A-Z]{16})/i',
        $text
    );
    if ($hasIP    && in_array('iso.ip_exposure', $requested, true))      $sel[] = 'iso.ip_exposure';
    if ($hasSecret&& in_array('iso.secrets_in_prompt', $requested, true))$sel[] = 'iso.secrets_in_prompt';
    return $sel;
}

function select_relevant_nis2_policies(string $text, array $requested): array {
    $sel = [];
    $hasCyber  = (bool)preg_match('/\b(admin|root|mfa|fail(le)?|intrusion|cve|exploit|vpn|firewall|siem|edr)\b/i', $text);
    $hasConfig = (bool)preg_match('/\b(config|configuration|endpoint|version|chemin|path|internal|interne)\b/i', $text);
    if ($hasCyber  && in_array('nis2.cyber_keywords', $requested, true))  $sel[] = 'nis2.cyber_keywords';
    if ($hasConfig && in_array('nis2.config_disclosure', $requested, true)) $sel[] = 'nis2.config_disclosure';
    return $sel;
}

function select_relevant_hipaa_policies(string $text, array $requested): array {
    $sel = [];
    $hasSSN = (bool)preg_match('/\b\d{3}-\d{2}-\d{4}\b/', $text);
    $hasPHI = (bool)preg_match('/\\b(cancer|hiv|vih|diagnostic|traitement|hospitalisation|m[eé]dical|d[eé]pression|anxi[eé]t[eé]|burnout|psychiatr|psycholog|sant[eé]|maladie|ordonnance|handicap|th[eé]rapie)\\b/i', $text);
    if ($hasSSN && in_array('hipaa.ssn', $requested, true))             $sel[] = 'hipaa.ssn';
    if ($hasPHI && in_array('hipaa.phi_sensitive', $requested, true))   $sel[] = 'hipaa.phi_sensitive';
    return $sel;
}

function select_relevant_hr_policies(string $text, array $requested): array {
    $sel = [];
    $hasEmployee = (bool)preg_match('/\\b(employ[eé]|collaborateur|matricule|rh|hr|badge|licenci|pr[eé]avis|entretien|recrutement|salaire|[eé]valuation|ressources humaines|difficult[eé]|situation personnelle)\\b/i', $text);
    if ($hasEmployee && in_array('hr.identifiable_employee', $requested, true)) $sel[] = 'hr.identifiable_employee';
    return $sel;
}

function select_relevant_finance_policies(string $text, array $requested): array {
    $sel = [];
    $hasIBAN = (bool)preg_match('/\b[A-Z]{2}[0-9]{2}[ ]?(?:[0-9]{4}[ ]?){3,5}[0-9]{0,2}\b/', $text);
    $hasCard = (bool)preg_match('/\b(?:\d[ -]?){13,19}\b/', $text); // PAN très grossier
    if (($hasIBAN || $hasCard) && in_array('finance.iban_or_pan', $requested, true)) $sel[] = 'finance.iban_or_pan';
    return $sel;
}

function select_relevant_legal_policies(string $text, array $requested): array {
    $sel = [];
    $hasConf = (bool)preg_match('/\\b(contrat|clause|nda|confidentiel|confidentialit[eé]|confi[eé]|secret professionnel|entretien priv[eé])\\b/i', $text);
    if ($hasConf && in_array('legal.confidential_contract', $requested, true)) $sel[] = 'legal.confidential_contract';
    return $sel;
}

function select_relevant_health_policies(string $text, array $requested): array {
    $sel = [];
    $hasCrit = (bool)preg_match('/\\b(cancer|vih|alzheimer|diab[èe]te|h[eé]patite|d[eé]pression|anxi[eé]t[eé]|burnout|psychiatr|psycholog|sant[eé]|maladie|traitement|ordonnance)\\b/i', $text);
    if ($hasCrit && in_array('health.critical_diseases', $requested, true)) $sel[] = 'health.critical_diseases';
    return $sel;
}
/* =========================================================
   Raison canonique par règle (on évite les hallucinations)
   ========================================================= */
function velixa_reason_catalog(string $policyId): string {
    $map = [
        // RGPD
        'rgpd.personal_identifiers'        => "Identifiants personnels non masqués (email, nom…)",
        'rgpd.contact_details'             => "Coordonnées exposées sans base légale/contexte",
        'rgpd.online_identifiers'          => "Identifiants en ligne (IP, cookies, etc.) non masqués",
        'rgpd.financial_identifiers'       => "Identifiants financiers non masqués (IBAN/PAN)",
        'rgpd.special_categories'          => "Données sensibles (santé, opinions…) présentes",
        'rgpd.children_data'               => "Données concernant des mineurs",
        'rgpd.images_faces'                => "Visages/images identifiants",
        'rgpd.data_minimization'           => "Données excessives au regard de la finalité",
        'rgpd.pseudonymization_anonymization' => "Absence de pseudonymisation/anonymisation",
        'rgpd.transparency_fairness'       => "Manque d’information claire (transparence/équité)",

        // ISO / NIS2
        'iso.ip_exposure'                  => "Adresse IP complète divulguée",
        'iso.secrets_in_prompt'            => "Secret/credential divulgué (token, clé, mot de passe)",
        'nis2.cyber_keywords'              => "Détail cybersécurité potentiellement sensible",
        'nis2.config_disclosure'           => "Configuration interne divulguée",

        // HIPAA / Santé
        'hipaa.ssn'                        => "SSN détecté (identifiant santé US)",
        'hipaa.phi_sensitive'              => "PHI / données de santé sensibles",
        'health.critical_diseases'         => "Mention de maladie critique liée à une personne",

        // RH / Finance / Legal
        'hr.identifiable_employee'         => "Salarié directement identifiable",
        'finance.iban_or_pan'              => "IBAN ou numéro de carte non masqué",
        'legal.confidential_contract'      => "Mention contractuelle confidentielle",
    ];
    return $map[$policyId] ?? "Violation détectée selon la règle.";
}


/* =========================================================
   Runner principal
   $activePolicies = liste d'IDs demandés (toutes familles).
   ========================================================= */
function run_policies($userPrompt, $activePolicies) {
    $violations = [];
    $families = load_all_policies();

    // Regroupement par famille
    $byFamily = [];
    foreach ($activePolicies as $id) {
        if (strpos($id, '.') === false) continue;
        $fam = substr($id, 0, strpos($id, '.')); // ex: rgpd, iso, ...
        $byFamily[$fam][] = $id;
    }

    foreach ($byFamily as $fam => $requestedIds) {
        if (!isset($families[$fam])) continue;

        // Intersection avec les IDs existants
        $availableIds = array_keys($families[$fam]["index"]);
        $requested = array_values(array_intersect($requestedIds, $availableIds));
        if (empty($requested)) continue;

        // Sélection pertinente
        switch ($fam) {
            case 'rgpd':    $selected = select_relevant_rgpd_policies($userPrompt, $requested); break;
            case 'iso':     $selected = select_relevant_iso_policies($userPrompt, $requested); break;
            case 'nis2':    $selected = select_relevant_nis2_policies($userPrompt, $requested); break;
            case 'hipaa':   $selected = select_relevant_hipaa_policies($userPrompt, $requested); break;
            case 'hr':      $selected = select_relevant_hr_policies($userPrompt, $requested); break;
            case 'finance': $selected = select_relevant_finance_policies($userPrompt, $requested); break;
            case 'legal':   $selected = select_relevant_legal_policies($userPrompt, $requested); break;
            case 'health':  $selected = select_relevant_health_policies($userPrompt, $requested); break;
            default:        $selected = $requested;
        }
        if (empty($selected)) continue;

        // Exécuter chaque policy
        foreach ($selected as $policyId) {
            $policy = $families[$fam]["index"][$policyId];

            // >>> ENFORCER ajouté au prompt <<<
            $prompt = JSON_ENFORCER . "\n\n" .
                      str_replace("{USER_TEXT}", $userPrompt, $policy["prompt_template"]);

            $reply  = call_ollama(OLLAMA_MODEL, $prompt);
            $result = velixa_parse_model_json((string)$reply);

            if (!$result || !array_key_exists("violation", $result)) {
                if (!is_dir(__DIR__ . "/logs")) { @mkdir(__DIR__ . "/logs", 0775, true); }
                file_put_contents(
                    __DIR__ . "/logs/llm_raw.log",
                    "[" . date("c") . "] policy={$policyId} raw=" . str_replace(["\r","\n"], ["\\r","\\n"], (string)$reply) . "\n",
                    FILE_APPEND
                );
                // Posture défensive
                $violations[$policyId] = [
                    "rule"     => $policyId,
                    "reason"   => "Réponse IA non JSON / invalide (policy renforcée)",
                    "snippets" => []
                ];
                continue;
            }

            if (!empty($result["violation"])) {
    // On force une raison canonique stable
    $violations[$policyId] = [
        "rule"     => $policyId,
        "reason"   => velixa_reason_catalog($policyId),
        "snippets" => []  // on ignore les extraits LLM
    ];
}

        }
    }

    $violations = array_values($violations);
    return [
        "status"     => count($violations) > 0 ? "REFUSÉ" : "OK",
        "violations" => $violations
    ];
}

/* =========================================================
   CLI test : php rules_runner.php "votre texte"
   ========================================================= */
if (php_sapi_name() === "cli") {
    $prompt = $argv[1] ?? "Voici mon email: jean.dupont@gmail.com";

    // Jeu large : l’UI envoie l’équivalent selon le métier
    $requested = [
        // RGPD
        'rgpd.personal_identifiers','rgpd.contact_details','rgpd.online_identifiers',
        'rgpd.financial_identifiers','rgpd.special_categories','rgpd.children_data',
        'rgpd.images_faces','rgpd.data_minimization','rgpd.pseudonymization_anonymization',
        // ISO
        'iso.ip_exposure','iso.secrets_in_prompt',
        // NIS2
        'nis2.cyber_keywords','nis2.config_disclosure',
        // HIPAA
        'hipaa.ssn','hipaa.phi_sensitive',
        // RH
        'hr.identifiable_employee',
        // Finance
        'finance.iban_or_pan',
        // Légal
        'legal.confidential_contract',
        // Santé
        'health.critical_diseases'
    ];

    $res = run_policies($prompt, $requested);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
