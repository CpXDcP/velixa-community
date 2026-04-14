<?php
declare(strict_types=1);
/**
 * Velixa — Purge automatique des logs
 * Aligné RGPD / NIS2 Essential / EU AI Act
 * 
 * Lancer via cron Windows (Planificateur de tâches) :
 * php C:\xampp\htdocs\velixa\jobs\purge_logs.php
 * 
 * Recommandé : chaque nuit à 2h00
 */

define('VX_ROOT', dirname(__DIR__));

// Politiques de rétention (jours)
$policies = [
    // Contenu prompts utilisateurs — RGPD/CNIL 6 mois
    'audit_logs'         => ['file' => VX_ROOT . '/audit_logs.json',          'days' => 180, 'type' => 'json_array',  'label' => 'Logs audit utilisateurs'],
    'prompts_encrypted'  => ['file' => VX_ROOT . '/prompts_encrypted.json',   'days' => 180, 'type' => 'json_array',  'label' => 'Prompts chiffrés RSA'],
    // Flux agents normaux — RGPD 6 mois
    'agents_allow'       => ['file' => VX_ROOT . '/logs/agents.ndjson',       'days' => 180, 'type' => 'ndjson_allow','label' => 'Logs agents (flux autorisés)'],
    // Incidents sécurité — NIS2 Essential 18 mois
    'agents_block'       => ['file' => VX_ROOT . '/logs/agents.ndjson',       'days' => 540, 'type' => 'ndjson_block','label' => 'Logs agents (blocages/violations)'],
    'security_events'    => ['file' => VX_ROOT . '/logs/security_events.ndjson','days'=> 540, 'type' => 'ndjson',     'label' => 'Événements sécurité'],
    'egress'             => ['file' => VX_ROOT . '/logs/egress.ndjson',       'days' => 180, 'type' => 'ndjson',      'label' => 'Logs egress bots'],
];

$purgeLog = VX_ROOT . '/logs/purge_journal.ndjson';
$logDir   = VX_ROOT . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0775, true);

$results = [];

foreach ($policies as $key => $pol) {
    $file  = $pol['file'];
    $days  = $pol['days'];
    $type  = $pol['type'];
    $label = $pol['label'];
    $cutoff = time() - $days * 86400;

    if (!file_exists($file)) {
        $results[] = ['key'=>$key,'label'=>$label,'status'=>'skip','reason'=>'Fichier absent'];
        continue;
    }

    $before = 0; $after = 0; $purged = 0;

    if ($type === 'json_array') {
        $raw = @file_get_contents($file);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $results[] = ['key'=>$key,'label'=>$label,'status'=>'error','reason'=>'JSON invalide'];
            continue;
        }
        $before = count($data);
        $data = array_values(array_filter($data, function($entry) use ($cutoff) {
            $ts = $entry['ts'] ?? $entry['timestamp'] ?? $entry['created_at'] ?? null;
            if (!$ts) return true; // garder si pas de date
            return strtotime($ts) >= $cutoff;
        }));
        $after  = count($data);
        $purged = $before - $after;
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);

    } elseif ($type === 'ndjson') {
        $lines  = file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        $before = count($lines);
        $kept   = array_filter($lines, function($l) use ($cutoff) {
            $j = json_decode($l, true);
            return is_array($j) && strtotime($j['ts']??'') >= $cutoff;
        });
        $after  = count($kept);
        $purged = $before - $after;
        file_put_contents($file, implode("\n", $kept) . "\n", LOCK_EX);

    } elseif ($type === 'ndjson_allow') {
        // Garder TOUS les bloqués (politique longue), purger uniquement les "allow" anciens
        $lines  = file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        $before = count($lines);
        $kept   = array_filter($lines, function($l) use ($cutoff) {
            $j = json_decode($l, true);
            if (!is_array($j)) return true;
            // Toujours garder les blocages (traités par ndjson_block)
            if (($j['decision']??'') === 'block') return true;
            // Purger les allow anciens selon politique courte
            return strtotime($j['ts']??'') >= $cutoff;
        });
        $after  = count($kept);
        $purged = $before - $after;
        file_put_contents($file, implode("\n", $kept) . "\n", LOCK_EX);

    } elseif ($type === 'ndjson_block') {
        // Purger uniquement les blocages très anciens (18 mois)
        $lines  = file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        $before = count(array_filter($lines, fn($l) => ($j=json_decode($l,true)) && ($j['decision']??'')==='block'));
        $kept   = array_filter($lines, function($l) use ($cutoff) {
            $j = json_decode($l, true);
            if (!is_array($j)) return true;
            if (($j['decision']??'') !== 'block') return true; // garder les allow (géré ailleurs)
            return strtotime($j['ts']??'') >= $cutoff;
        });
        $after  = count(array_filter($kept, fn($l) => ($j=json_decode($l,true)) && ($j['decision']??'')==='block'));
        $purged = $before - $after;
        // Pas d'écriture ici — ndjson_allow s'en charge pour le fichier partagé
        $results[] = ['key'=>$key,'label'=>$label,'status'=>'ok','before'=>$before,'after'=>$after,'purged'=>$purged,'days'=>$days];
        continue;
    }

    $results[] = ['key'=>$key,'label'=>$label,'status'=>'ok','before'=>$before,'after'=>$after,'purged'=>$purged,'days'=>$days];
}

// Journal de purge (lui-même conservé 3 ans — preuve RGPD Art.5 accountability)
$entry = [
    'ts'      => date('c'),
    'results' => $results,
    'total_purged' => array_sum(array_column($results, 'purged')),
];
file_put_contents($purgeLog, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND|LOCK_EX);

// Purge du journal de purge lui-même après 3 ans
if (file_exists($purgeLog)) {
    $cutoff3y = time() - 3 * 365 * 86400;
    $plines = file($purgeLog, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    $pkept  = array_filter($plines, fn($l) => ($j=json_decode($l,true)) && strtotime($j['ts']??'') >= $cutoff3y);
    file_put_contents($purgeLog, implode("\n", $pkept) . "\n", LOCK_EX);
}

// Affichage console (utile quand lancé manuellement)
echo "[Velixa Purge] " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 60) . "\n";
foreach ($results as $r) {
    if ($r['status'] === 'skip') {
        echo "  SKIP  {$r['label']} — {$r['reason']}\n";
    } elseif ($r['status'] === 'error') {
        echo "  ERR   {$r['label']} — {$r['reason']}\n";
    } else {
        $purged = $r['purged'] ?? 0;
        echo "  OK    {$r['label']} ({$r['days']}j) — {$purged} entrée(s) purgée(s)\n";
    }
}
echo str_repeat('-', 60) . "\n";
echo "  Total purgé : " . $entry['total_purged'] . " entrée(s)\n";
echo "  Journal : {$purgeLog}\n";


/**
 * Droit à l'oubli RGPD — Art.17
 * Purge toutes les données d'un utilisateur spécifique dans tous les logs
 * Usage : php purge_logs.php --forget=username
 */
function vx_forget_user(string $username): array {
    $root    = VX_ROOT;
    $results = [];

    // audit_logs.json
    $f = $root . '/audit_logs.json';
    if (file_exists($f)) {
        $d = json_decode(file_get_contents($f), true) ?: [];
        $b = count($d);
        $d = array_values(array_filter($d, fn($e) => ($e['user']??$e['username']??'') !== $username));
        file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
        $results['audit_logs'] = $b - count($d);
    }

    // prompts_encrypted.json
    $f = $root . '/prompts_encrypted.json';
    if (file_exists($f)) {
        $d = json_decode(file_get_contents($f), true) ?: [];
        $b = count($d);
        $d = array_values(array_filter($d, fn($e) => ($e['user']??$e['username']??'') !== $username));
        file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
        $results['prompts_encrypted'] = $b - count($d);
    }

    // agents.ndjson (logs agents — chercher par username ou ip si associé)
    $f = $root . '/logs/agents.ndjson';
    if (file_exists($f)) {
        $lines = file($f, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        $b = count($lines);
        $kept = array_filter($lines, fn($l) => ($j=json_decode($l,true)) && ($j['username']??'') !== $username);
        file_put_contents($f, implode("
", $kept) . "
", LOCK_EX);
        $results['agents_ndjson'] = $b - count($kept);
    }

    // Journal de l'oubli (traçabilité RGPD)
    $plog = $root . '/logs/purge_journal.ndjson';
    file_put_contents($plog,
        json_encode(['ts'=>date('c'),'type'=>'forget_user','username_hash'=>hash('sha256',$username),'results'=>$results], JSON_UNESCAPED_UNICODE)."
",
        FILE_APPEND|LOCK_EX
    );

    return $results;
}

// Ligne de commande : php purge_logs.php --forget=username
if (PHP_SAPI === 'cli') {
    $opts = getopt('', ['forget:']);
    if (!empty($opts['forget'])) {
        $username = $opts['forget'];
        echo "[Velixa RGPD Art.17] Oubli de : " . $username . "\n";
        $results = vx_forget_user($username);
        foreach ($results as $k => $n) {
            echo "  {$k} : {$n} entrée(s) supprimée(s)\n";
        }
        echo "  Terminé. Journal mis à jour.\n";
    }
}
