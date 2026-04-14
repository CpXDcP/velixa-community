<?php
require_once __DIR__ . '/inc/bootstrap.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$usersFile = "users.json";
$rulesFile = "rules_by_metier.json";
$availableRules = [
    "rgpd"               => "RGPD",
    "nis2"               => "NIS 2",
    "iso27001"           => "ISO 27001",
    "hipaa"              => "HIPAA",
    "confidentialite_rh" => "RH",
    "finance"            => "Finance",
    "confidentialite_legale" => "Legal",
    "donnees_sante"      => "Health"
];

$roles = ['admin', 'user'];
$message = '';
$rulesByMetier = file_exists($rulesFile) ? json_decode(file_get_contents($rulesFile), true) : [];
$users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];

function vx_slug($s) {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    return trim($s, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role     = $_POST['role'];
        $metier   = $_POST['metier'];
        if (!isset($users[$username])) {
            $users[$username] = ['password' => $password, 'role' => $role, 'metier' => $metier];
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            $message = "✅ Utilisateur $username créé.";
        } else {
            $message = "⚠️ Cet utilisateur existe déjà.";
        }
    }

    if (isset($_POST['delete_user'])) {
        $username = $_POST['delete_user'];
        if (isset($users[$username])) {
            unset($users[$username]);
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            $message = "🗑️ Utilisateur $username supprimé.";
        }
    }

    if (isset($_POST['save_rules'])) {
        $newRules = [];
        foreach ($_POST['metier'] as $metier => $rules) {
            $newRules[$metier] = array_keys($rules);
        }
        file_put_contents($rulesFile, json_encode($newRules, JSON_PRETTY_PRINT));
        $message = "✅ Règles mises à jour.";
    }

    if (isset($_POST['modify_user'])) {
        $username = trim($_POST['username_existing'] ?? '');
        if ($username !== '' && isset($users[$username])) {
            if (isset($_POST['new_role']) && in_array($_POST['new_role'], $roles, true)) {
                $users[$username]['role'] = $_POST['new_role'];
            }
            if (isset($_POST['new_metier']) && $_POST['new_metier'] !== '' && array_key_exists($_POST['new_metier'], $rulesByMetier)) {
                $users[$username]['metier'] = $_POST['new_metier'];
            }
            if (!empty($_POST['new_password'])) {
                $users[$username]['password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            }
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            $message = "✏️ Utilisateur $username mis à jour.";
        } else {
            $message = "⚠️ Utilisateur introuvable.";
        }
    }
}

/* Providers config */
$providersCfgFile = __DIR__ . "/config/providers.json";
$providersCfg = ['mode' => 'global', 'global' => ['active_provider' => 'groq', 'providers' => []], 'per_metier' => []];
if (file_exists($providersCfgFile)) {
    $pc = json_decode(@file_get_contents($providersCfgFile), true);
    if (is_array($pc)) $providersCfg = $pc;
}
$PROV_LIST = ['groq','openai','anthropic','gemini'];

function vx_pricing_defaults() {
    return ['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0.0,'output_price'=>0.0,'calculation_mode'=>'proportional'];
}
function vx_norm_provider_block($block) {
    $d = vx_pricing_defaults();
    $p = $block['pricing'] ?? [];
    return [
        'enabled'  => (bool)($block['enabled'] ?? false),
        'model'    => $block['model'] ?? '',
        'pricing'  => [
            'currency'         => $p['currency'] ?? $d['currency'],
            'unit_tokens'      => (int)($p['unit_tokens'] ?? $d['unit_tokens']),
            'input_price'      => (float)($p['input_price'] ?? $d['input_price']),
            'output_price'     => (float)($p['output_price'] ?? $d['output_price']),
            'calculation_mode' => in_array(($p['calculation_mode'] ?? $d['calculation_mode']),['proportional','ceil'],true)
                                  ? ($p['calculation_mode'] ?? $d['calculation_mode']) : $d['calculation_mode']
        ]
    ];
}

/* Audit stats */
$auditFile = __DIR__ . "/audit_logs.json";
$entries = [];
if (file_exists($auditFile)) {
    $raw  = @file_get_contents($auditFile);
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $entries = isset($json['logs']) && is_array($json['logs']) ? $json['logs'] : $json;
    }
}
$norm = static fn($v): string => str_replace(['.', ',', ';', ':'], '', strtolower(trim((string)$v)));
$vx_isAccepted = static fn($s) use ($norm) => in_array($norm($s), ['ok','accepted','accept','allow','allowed','pass','passed','accepte','accepté','valide','validé'], true);
$vx_isRefused  = static fn($s) use ($norm) => in_array($norm($s), ['refuse','refusé','rejeté','rejete','rejected','deny','denied','blocked','block','ko','non conforme','not allowed','not_allowed','policy_violation','policy_block','blocked_by_policy','blocked_by_rule','blocked_by_guardrail'], true);

$totalPrompts = 0; $okPrompts = 0; $koPrompts = 0;
foreach ($entries as $e) {
    if (!is_array($e)) continue;
    $totalPrompts++;
    $st = $e['status'] ?? $e['result'] ?? $e['decision'] ?? $e['outcome'] ?? '';
    if ($vx_isAccepted($st))                            { $okPrompts++; continue; }
    if ($vx_isRefused($st))                             { $koPrompts++; continue; }
    if (isset($e['allowed']) && $e['allowed'] === true)  { $okPrompts++; continue; }
    if (isset($e['allowed']) && $e['allowed'] === false) { $koPrompts++; continue; }
}
$unknown = $totalPrompts - $okPrompts - $koPrompts;
if ($unknown > 0) $koPrompts += $unknown;

/* Providers map */
$activeMode = $providersCfg['mode'] ?? 'global';
$enabledProviders = [];
$activeProvider = 'groq';

$normGlobalProviders = [];
if (isset($providersCfg['global']['providers']) && is_array($providersCfg['global']['providers'])) {
    foreach ($PROV_LIST as $pname) {
        $normGlobalProviders[$pname] = vx_norm_provider_block($providersCfg['global']['providers'][$pname] ?? []);
    }
}
if ($activeMode === 'global') {
    $activeProvider = $providersCfg['global']['active_provider'] ?? 'groq';
    foreach ($normGlobalProviders as $pname => $pb) {
        if (!empty($pb['enabled'])) $enabledProviders[$pname] = true;
    }
    if (empty($enabledProviders)) $enabledProviders['groq'] = true;
}

function vx_cost_calc($tokensIn, $tokensOut, $pricing) {
    $unit = max(1, (int)($pricing['unit_tokens'] ?? 1000000));
    $pin  = (float)($pricing['input_price']  ?? 0.0);
    $pout = (float)($pricing['output_price'] ?? 0.0);
    $mode = $pricing['calculation_mode'] ?? 'proportional';
    if ($mode === 'ceil') {
        $cIn  = ($tokensIn  > 0) ? (ceil($tokensIn  / $unit) * $pin)  : 0.0;
        $cOut = ($tokensOut > 0) ? (ceil($tokensOut / $unit) * $pout) : 0.0;
    } else {
        $cIn  = ($tokensIn  / $unit) * $pin;
        $cOut = ($tokensOut / $unit) * $pout;
    }
    return [$cIn, $cOut, $cIn + $cOut];
}

$VX_TOKENS_PER_PROMPT_IN  = 350;
$VX_TOKENS_PER_PROMPT_OUT = 650;
$inTokensTotal  = $okPrompts * $VX_TOKENS_PER_PROMPT_IN;
$outTokensTotal = $okPrompts * $VX_TOKENS_PER_PROMPT_OUT;
$totalTokens    = $inTokensTotal + $outTokensTotal;

$rows = [];
$grandTotalCost = 0.0;
foreach ($PROV_LIST as $pname) {
    $gBlock = $normGlobalProviders[$pname] ?? vx_norm_provider_block([]);
    $rows[$pname] = [
        'provider'=>strtoupper($pname),'enabled'=>!empty($enabledProviders[$pname]),
        'model'=>$gBlock['model']??'','currency'=>$gBlock['pricing']['currency'],
        'unit'=>(int)$gBlock['pricing']['unit_tokens'],'price_in'=>(float)$gBlock['pricing']['input_price'],
        'price_out'=>(float)$gBlock['pricing']['output_price'],'calc_mode'=>$gBlock['pricing']['calculation_mode'],
        'prompts_ok'=>0,'tokens_in'=>0,'tokens_out'=>0,'tokens_total'=>0,'cost_in'=>0.0,'cost_out'=>0.0,'cost_total'=>0.0
    ];
}

if ($activeMode === 'global') {
    $prov   = $activeProvider;
    $gBlock = $normGlobalProviders[$prov] ?? vx_norm_provider_block([]);
    $pricing = $gBlock['pricing'];
    [$cIn,$cOut,$cTot] = vx_cost_calc($inTokensTotal, $outTokensTotal, $pricing);
    $rows[$prov]['prompts_ok']   = $okPrompts;
    $rows[$prov]['tokens_in']    = $inTokensTotal;
    $rows[$prov]['tokens_out']   = $outTokensTotal;
    $rows[$prov]['tokens_total'] = $totalTokens;
    $rows[$prov]['cost_in']      = $cIn;
    $rows[$prov]['cost_out']     = $cOut;
    $rows[$prov]['cost_total']   = $cTot;
    $rows[$prov]['model']        = $gBlock['model'];
    $rows[$prov]['currency']     = $pricing['currency'];
    $grandTotalCost = $cTot;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Dashboard Admin — Velixa for Gen AI</title>
  <link rel="stylesheet" href="style.css">
  <style>
    :root{--vx-bg:#0B0C0E;--vx-surface:#121416;--vx-surface-2:#0f1317;--vx-border:#1f242b;--vx-text:#FFFFFF;--vx-muted:#9CA3AF;--vx-green:#10B981;--vx-green-2:#0EA371;--vx-purple:#8B5CF6;--vx-radius:14px}
    *{box-sizing:border-box}
    a{color:inherit;text-decoration:none}
    body{margin:0;background:var(--vx-bg);color:var(--vx-text);font-family:Inter,Roboto,system-ui,-apple-system,Arial,sans-serif}
    .app{min-height:100vh;display:flex;flex-direction:column}
    .vx-topbar{position:sticky;top:0;z-index:10;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--vx-border);background:linear-gradient(180deg,#0f1114 0%,#0b0c0e 100%)}
    .vx-topbrand{justify-self:center;display:flex;align-items:center;gap:12px}
    .vx-topbrand img{height:100px;width:200px;display:block}
    .vx-logout{background:#18231e;color:#e5e7eb;border:1px solid var(--vx-green);padding:8px 12px;border-radius:10px;cursor:pointer;transition:transform .12s,box-shadow .12s,filter .12s}
    .vx-logout:hover{filter:brightness(1.08);box-shadow:0 0 0 3px rgba(16,185,129,.35),0 8px 24px rgba(0,0,0,.45)}
    .vx-topbar>a{justify-self:end}
    .vx-main{padding:22px}
    .vx-panels{display:grid;gap:18px}
    @media(min-width:1100px){.vx-panels{grid-template-columns:1fr 1fr}}
    .vx-full{grid-column:1/-1}
    .vx-panel{background:var(--vx-surface);border:1px solid var(--vx-border);border-radius:var(--vx-radius);padding:18px}
    .vx-panel h3{margin:0 0 12px;font-size:18px}
    .vx-message{margin:0 0 18px;padding:12px 14px;border-radius:12px;background:#102312;border:1px solid #194a1d;color:#d9fbd0}
    .vx-compact{max-height:420px;overflow:auto}
    table{width:100%;border-collapse:collapse;background:var(--vx-surface-2);border-radius:12px;overflow:hidden}
    th,td{padding:10px 12px;border-bottom:1px solid #1b2128}
    th{background:#12181f;text-align:left;color:#e5e7eb;font-weight:700}
    tr:last-child td{border-bottom:none}
    tr:hover td{background:#131a21}
    label{display:inline-block;margin:6px 0}
    input[type="text"],input[type="password"],select{background:var(--vx-surface-2);color:#e5e7eb;border:1px solid #222933;border-radius:10px;padding:10px 12px;margin:6px 0;width:260px;outline:none;transition:box-shadow .15s,border-color .15s}
    input[type="text"]:focus,input[type="password"]:focus,select:focus{border-color:var(--vx-green);box-shadow:0 0 0 3px rgba(16,185,129,.30)}
    .vx-select{background:var(--vx-surface-2);color:#e5e7eb;border:1px solid #222933;border-radius:10px;padding:10px 12px;width:280px}
    input[type="submit"],button,.vx-btn{background:linear-gradient(180deg,var(--vx-green),var(--vx-green-2));color:#0a1612;border:1px solid var(--vx-green);border-radius:10px;padding:10px 14px;cursor:pointer;font-weight:800;box-shadow:0 6px 16px rgba(16,185,129,.28);transition:transform .12s,box-shadow .12s,filter .12s}
    input[type="submit"]:hover,button:hover,.vx-btn:hover{filter:brightness(1.08);box-shadow:0 0 0 4px rgba(16,185,129,.45),0 10px 28px rgba(0,0,0,.50);transform:translateY(-1px)}
    .vx-btn-secondary{background:#0f1b17;color:#d9fff3;border:1px solid rgba(16,185,129,.5);box-shadow:none}
    .vx-btn-enterprise{background:#1a1a2e;color:#9CA3AF;border:1px solid #2a2a4a;box-shadow:none;cursor:default;opacity:.7}
    input[type="submit"][name="save_rules"],input[type="submit"][name="modify_user"]{background:linear-gradient(180deg,var(--vx-purple),#6D28D9);color:#fff;border:1px solid var(--vx-purple);box-shadow:0 6px 16px rgba(139,92,246,.35)}
    fieldset{border:1px solid var(--vx-border);border-radius:12px;padding:12px;margin:8px 0;background:var(--vx-surface-2)}
    legend{padding:0 8px;font-weight:700}
    .vx-actions-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .vx-kpis{display:grid;gap:12px;grid-template-columns:repeat(2,1fr);margin-bottom:18px}
    .vx-kpi{background:#0f1317;border:1px solid var(--vx-border);border-radius:12px;padding:14px}
    .vx-kpi-title{color:#cfd6dd;font-size:13px;margin-bottom:6px}
    .vx-kpi-value{font-weight:800;font-size:22px}
    .vx-tiles{display:grid;gap:14px;margin:14px 0 18px;grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}
    .vx-tile{color:#e5e7eb;background:#0f1317;border:1px solid var(--vx-border);border-radius:14px;padding:16px;display:block;transition:transform .12s,filter .12s,box-shadow .12s}
    .vx-tile:hover{transform:translateY(-2px);filter:brightness(1.05);box-shadow:0 10px 26px rgba(0,0,0,.35)}
    .vx-tile-enterprise{border-color:#2a2a4a;opacity:.6;cursor:default}
    .vx-tile-enterprise:hover{transform:none;filter:none;box-shadow:none}
    .vx-tile-emoji{font-size:22px;margin-bottom:8px}
    .vx-tile-title{font-weight:800;margin-bottom:4px}
    .vx-tile-desc{color:#9CA3AF;font-size:13px}
    .vx-enterprise-badge{display:inline-block;background:#2a1a4a;color:#a78bfa;font-size:10px;font-weight:700;padding:2px 7px;border-radius:8px;margin-left:6px;vertical-align:middle}
    .vx-rules-select-wrap{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
    .vx-rules-fieldset{display:none}
    .vx-rules-fieldset.active{display:block}
    .vx-note{color:#9CA3AF;font-size:13px;margin-top:6px}
    .vx-footer{margin:22px;display:flex;justify-content:center}
    #vx-back-top{position:fixed;right:18px;bottom:18px;z-index:9999;background:#0f1b17;border:1px solid rgba(16,185,129,.5);color:#d9fff3;border-radius:999px;padding:10px 14px;cursor:pointer;display:none;box-shadow:0 8px 24px rgba(0,0,0,.35);transition:box-shadow .12s,filter .12s,transform .12s}
    #vx-back-top:hover{filter:brightness(1.08);box-shadow:0 0 0 4px rgba(16,185,129,.35),0 10px 26px rgba(0,0,0,.5);transform:translateY(-1px)}
  </style>
</head>
<body>
<div class="app">

  <header class="vx-topbar">
    <div></div>
    <div class="vx-topbrand">
      <?php if (file_exists('assets/velixa-logo.png')): ?>
        <img src="assets/velixa-logo.png" alt="Velixa for Gen AI">
      <?php else: ?>
        <span style="font-weight:800;letter-spacing:.14em">VELIXA FOR GEN AI</span>
      <?php endif; ?>
    </div>
    <a href="logout.php"><button class="vx-logout">🚪 Déconnexion</button></a>
  </header>

  <main class="vx-main">

    <?php if (!empty($message)): ?>
      <div class="vx-message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Tiles Community -->
    <div style="font-size:12px;color:#9CA3AF;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">
      Fonctionnalités Community
    </div>
    <div class="vx-tiles">
      <a href="#users" class="vx-tile">
        <div class="vx-tile-emoji">👤</div>
        <div class="vx-tile-title">Utilisateurs</div>
        <div class="vx-tile-desc">Créer, modifier, supprimer des comptes</div>
      </a>
      <a href="#rules" class="vx-tile">
        <div class="vx-tile-emoji">🧩</div>
        <div class="vx-tile-title">Règles par métier</div>
        <div class="vx-tile-desc">Configurer les règles de conformité actives</div>
      </a>
      <a href="#providers" class="vx-tile">
        <div class="vx-tile-emoji">🤖</div>
        <div class="vx-tile-title">Providers IA</div>
        <div class="vx-tile-desc">Clés API et configuration LLM</div>
      </a>
      <a href="admin_providers.php" class="vx-tile">
        <div class="vx-tile-emoji">⚙️</div>
        <div class="vx-tile-title">Configuration providers</div>
        <div class="vx-tile-desc">Groq, OpenAI, Anthropic, Gemini</div>
      </a>
      <a href="consent.php" class="vx-tile">
        <div class="vx-tile-emoji">📋</div>
        <div class="vx-tile-title">Charte d'usage IA</div>
        <div class="vx-tile-desc">Consentements tracés — EU AI Act Art.52</div>
      </a>
      <a href="admin_prompt_viewer.php" class="vx-tile">
        <div class="vx-tile-emoji">🔍</div>
        <div class="vx-tile-title">Déchiffrer un prompt</div>
        <div class="vx-tile-desc">Accès HITL — EU AI Act Art.14 (pay-per-use)</div>
      </a>
      <a href="view_logs.php" class="vx-tile">
        <div class="vx-tile-emoji">📋</div>
        <div class="vx-tile-title">Logs de conformité</div>
        <div class="vx-tile-desc">Audit, sécurité, historique</div>
      </a>
      <a href="#logs" class="vx-tile">
        <div class="vx-tile-emoji">📊</div>
        <div class="vx-tile-title">Export logs</div>
        <div class="vx-tile-desc">CSV et PDF anonymisés</div>
      </a>
      <a href="admin_health_check.php" class="vx-tile">
        <div class="vx-tile-emoji">❤️</div>
        <div class="vx-tile-title">Health check</div>
        <div class="vx-tile-desc">État des services et dépendances</div>
      </a>
      <a href="#costs" class="vx-tile">
        <div class="vx-tile-emoji">💰</div>
        <div class="vx-tile-title">Coûts IA estimés</div>
        <div class="vx-tile-desc">Estimation par provider</div>
      </a>
    </div>

    <!-- Tiles Enterprise -->
    <div style="font-size:12px;color:#9CA3AF;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;margin-top:8px">
      Fonctionnalités Enterprise <span class="vx-enterprise-badge">Enterprise</span>
    </div>
    <div class="vx-tiles">
      <div class="vx-tile vx-tile-enterprise">
        <div class="vx-tile-emoji">🧠</div>
        <div class="vx-tile-title">Agents IA <span class="vx-enterprise-badge">Enterprise</span></div>
        <div class="vx-tile-desc">Contrôle flux agents — quota, budget, kill switch</div>
      </div>
      <div class="vx-tile vx-tile-enterprise">
        <div class="vx-tile-emoji">🏛</div>
        <div class="vx-tile-title">Rapport EU AI Act <span class="vx-enterprise-badge">Enterprise</span></div>
        <div class="vx-tile-desc">Conformité réglementaire — export PDF automatique</div>
      </div>
      <div class="vx-tile vx-tile-enterprise">
        <div class="vx-tile-emoji">💰</div>
        <div class="vx-tile-title">ROI Exécutif <span class="vx-enterprise-badge">Enterprise</span></div>
        <div class="vx-tile-desc">Économies estimées, blocages — vue COMEX</div>
      </div>
      <div class="vx-tile vx-tile-enterprise">
        <div class="vx-tile-emoji">🔒</div>
        <div class="vx-tile-title">Legal Hold <span class="vx-enterprise-badge">Enterprise</span></div>
        <div class="vx-tile-desc">Snapshots signés SHA256 pour usage légal</div>
      </div>
      <div class="vx-tile vx-tile-enterprise">
        <div class="vx-tile-emoji">🔔</div>
        <div class="vx-tile-title">Webhooks <span class="vx-enterprise-badge">Enterprise</span></div>
        <div class="vx-tile-desc">Notifications Slack, Teams, webhook générique</div>
      </div>
      <div class="vx-tile vx-tile-enterprise">
        <div class="vx-tile-emoji">🏢</div>
        <div class="vx-tile-title">Active Directory / LDAP <span class="vx-enterprise-badge">Enterprise</span></div>
        <div class="vx-tile-desc">Connecteurs AD — synchronisation OU → métiers</div>
      </div>
      <div class="vx-tile vx-tile-enterprise">
        <div class="vx-tile-emoji">📈</div>
        <div class="vx-tile-title">Historique conformité <span class="vx-enterprise-badge">Enterprise</span></div>
        <div class="vx-tile-desc">Score de conformité dans le temps — 90 jours</div>
      </div>
      <div class="vx-tile vx-tile-enterprise">
        <div class="vx-tile-emoji">🔗</div>
        <div class="vx-tile-title">Traçabilité chaînes <span class="vx-enterprise-badge">Enterprise</span></div>
        <div class="vx-tile-desc">Flux agent-à-agent — visualisation complète</div>
      </div>
    </div>

    <!-- KPIs -->
    <div class="vx-kpis" style="margin-top:18px">
      <div class="vx-kpi">
        <div class="vx-kpi-title">Utilisateurs</div>
        <div class="vx-kpi-value"><?= count($users) ?></div>
      </div>
      <div class="vx-kpi">
        <div class="vx-kpi-title">Métiers configurés</div>
        <div class="vx-kpi-value"><?= count($rulesByMetier) ?></div>
      </div>
    </div>

    <div class="vx-panels">

      <!-- Utilisateurs -->
      <div id="users" class="vx-panel vx-full">
        <h3>👥 Gestion des utilisateurs</h3>
        <label for="userAction">Action :</label><br>
        <select id="userAction" class="vx-select">
          <option value="create">Créer</option>
          <option value="delete">Supprimer</option>
          <option value="modify">Modifier</option>
          <option value="list">Liste</option>
        </select>

        <div id="panel-create" class="vx-subpanel active" style="margin-top:14px">
          <h4 style="margin:0 0 8px">Créer un utilisateur</h4>
          <form method="post">
            <?= vx_csrf_field() ?>
            <label>Nom d'utilisateur :<br><input type="text" name="username" required></label><br>
            <label>Mot de passe :<br><input type="password" name="password" required></label><br>
            <label>Rôle :<br>
              <select name="role">
                <?php foreach ($roles as $role): ?>
                  <option value="<?= $role ?>"><?= $role ?></option>
                <?php endforeach; ?>
              </select>
            </label><br>
            <label>Métier :<br>
              <select name="metier" required>
                <?php foreach (array_keys($rulesByMetier) as $m): ?>
                  <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
              </select>
            </label><br><br>
            <input type="submit" name="create_user" value="Créer">
          </form>
        </div>

        <div id="panel-delete" class="vx-subpanel" style="margin-top:14px">
          <h4 style="margin:0 0 8px">Supprimer un utilisateur</h4>
          <form method="post" class="vx-actions-row">
            <?= vx_csrf_field() ?>
            <select name="delete_user" required>
              <option value="">— Sélectionner —</option>
              <?php foreach ($users as $u => $d): ?>
                <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?> (<?= htmlspecialchars($d['role']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <input type="submit" value="Supprimer" class="vx-btn vx-btn-secondary">
          </form>
        </div>

        <div id="panel-modify" class="vx-subpanel" style="margin-top:14px">
          <h4 style="margin:0 0 8px">Modifier un utilisateur</h4>
          <form method="post">
            <?= vx_csrf_field() ?>
            <label>Utilisateur :<br>
              <select name="username_existing" required>
                <option value="">— Sélectionner —</option>
                <?php foreach ($users as $u => $d): ?>
                  <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
                <?php endforeach; ?>
              </select>
            </label><br>
            <label>Nouveau rôle :<br>
              <select name="new_role">
                <option value="">— Ne pas changer —</option>
                <?php foreach ($roles as $role): ?>
                  <option value="<?= $role ?>"><?= $role ?></option>
                <?php endforeach; ?>
              </select>
            </label><br>
            <label>Nouveau métier :<br>
              <select name="new_metier">
                <option value="">— Ne pas changer —</option>
                <?php foreach (array_keys($rulesByMetier) as $m): ?>
                  <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
              </select>
            </label><br>
            <label>Nouveau mot de passe (optionnel) :<br>
              <input type="password" name="new_password" placeholder="Laisser vide pour conserver">
            </label><br><br>
            <input type="submit" name="modify_user" value="Enregistrer">
          </form>
        </div>

        <div id="panel-list" class="vx-subpanel" style="margin-top:14px">
          <details>
            <summary style="cursor:pointer;font-weight:800">Utilisateurs existants (<?= count($users) ?>)</summary>
            <div style="margin-top:10px">
              <table>
                <tr><th>Nom</th><th>Rôle</th><th>Métier</th><th>Action</th></tr>
                <?php foreach ($users as $u => $d): ?>
                  <tr>
                    <td><?= htmlspecialchars($u) ?></td>
                    <td><?= htmlspecialchars($d['role']) ?></td>
                    <td><?= htmlspecialchars($d['metier'] ?? '-') ?></td>
                    <td>
                      <form method="post" style="display:inline">
                        <?= vx_csrf_field() ?>
                        <input type="hidden" name="delete_user" value="<?= $u ?>">
                        <input type="submit" value="Supprimer" class="vx-btn vx-btn-secondary">
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </div>
          </details>
        </div>
      </div>

      <!-- Règles par métier -->
      <div id="rules" class="vx-panel vx-compact">
        <h3>🧩 Règles par métier</h3>
        <div class="vx-rules-select-wrap">
          <label for="rules-metier-select">Sélectionner un métier :</label>
          <select id="rules-metier-select" class="vx-select">
            <?php foreach ($rulesByMetier as $metier => $_): ?>
              <option value="<?= htmlspecialchars(vx_slug($metier)) ?>"><?= htmlspecialchars($metier) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <form method="post">
          <?= vx_csrf_field() ?>
          <?php foreach ($rulesByMetier as $metier => $selectedRules): ?>
            <?php $slug = vx_slug($metier); ?>
            <fieldset id="metier-<?= $slug ?>" class="vx-rules-fieldset">
              <legend><?= htmlspecialchars($metier) ?></legend>
              <?php foreach ($availableRules as $ruleKey => $ruleLabel): ?>
                <label>
                  <input type="checkbox" name="metier[<?= $metier ?>][<?= $ruleKey ?>]"
                    <?= in_array($ruleKey, $selectedRules) ? 'checked' : '' ?>>
                  <?= $ruleLabel ?>
                </label><br>
              <?php endforeach; ?>
            </fieldset><br>
          <?php endforeach; ?>
          <input type="submit" name="save_rules" value="Enregistrer les règles">
        </form>
      </div>

      <!-- Providers IA -->
      <?php
      $__groqConfigured = false;
      if (file_exists(__DIR__ . '/inc/secure_store.php')) {
          require_once __DIR__ . '/inc/secure_store.php';
          if (function_exists('vx_provider_keys_get')) {
              $___k = vx_provider_keys_get();
              $__groqConfigured = !empty($___k['groq']['api_key']);
          }
      }
      ?>
      <div id="providers" class="vx-panel vx-compact">
        <h3>⚙️ Providers IA</h3>
        <p class="vx-note">Mode actif : <strong><?= htmlspecialchars($activeMode) ?></strong></p>
        <div class="vx-actions-row" style="margin-top:12px">
          <a href="admin_providers.php"><span class="vx-btn">Configurer les providers</span></a>
          <span class="vx-btn vx-btn-secondary">
            Groq : <?= $__groqConfigured ? '✅ configuré' : '❌ non configuré' ?>
          </span>
        </div>
        <div style="margin-top:12px;overflow:auto">
          <table>
            <tr><th>Provider</th><th>Actif</th><th>Modèle</th><th>Prix IN / unité</th><th>Prix OUT / unité</th></tr>
            <?php foreach ($PROV_LIST as $pv):
              $pb  = $normGlobalProviders[$pv] ?? vx_norm_provider_block([]);
              $ena = !empty($enabledProviders[$pv]);
            ?>
              <tr>
                <td><?= strtoupper($pv) ?></td>
                <td><?= $ena ? '✅' : '⛔' ?></td>
                <td><?= htmlspecialchars($pb['model'] ?: '-') ?></td>
                <td><?= htmlspecialchars($pb['pricing']['input_price']) ?></td>
                <td><?= htmlspecialchars($pb['pricing']['output_price']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
        <div class="vx-note" style="margin-top:10px">Les clés API sont chiffrées AES-256-GCM côté serveur.</div>
      </div>

      <!-- Coûts IA -->
      <div id="costs" class="vx-panel vx-full">
        <h3>💰 Coûts IA estimés</h3>
        <div class="vx-kpis">
          <div class="vx-kpi"><div class="vx-kpi-title">Total prompts</div><div class="vx-kpi-value"><?= (int)$totalPrompts ?></div></div>
          <div class="vx-kpi"><div class="vx-kpi-title">Acceptés</div><div class="vx-kpi-value"><?= (int)$okPrompts ?></div></div>
          <div class="vx-kpi"><div class="vx-kpi-title">Refusés</div><div class="vx-kpi-value"><?= (int)$koPrompts ?></div></div>
          <div class="vx-kpi"><div class="vx-kpi-title">Coût total estimé</div><div class="vx-kpi-value">€ <?= number_format($grandTotalCost, 2, '.', ' ') ?></div></div>
        </div>
      </div>

      <!-- Logs & Exports -->
      <div id="logs" class="vx-panel vx-full">
        <h3>📊 Logs & Exports</h3>
        <div class="vx-actions-row">
          <a href="audit_admin.php"><span class="vx-btn">🕵️ Audit prompts</span></a>
          <a href="view_logs.php"><span class="vx-btn vx-btn-secondary">📋 Logs complets</span></a>
          <a href="export_csv.php"><span class="vx-btn vx-btn-secondary">📥 Export CSV</span></a>
          <a href="export_pdf.php"><span class="vx-btn vx-btn-secondary">📄 Export PDF</span></a>
          <a href="admin_health_check.php"><span class="vx-btn vx-btn-secondary">❤️ Health check</span></a>
          <a href="tests/run_tests.php?token=velixatest" target="_blank"><span class="vx-btn vx-btn-secondary">🧪 Tests</span></a>
        </div>
      </div>

    </div>
  </main>

  <div class="vx-footer">
    <a href="logout.php"><button class="vx-logout">🚪 Déconnexion</button></a>
  </div>
</div>

<button id="vx-back-top" title="Haut de page">⬆️ Haut</button>

<script>
const select = document.getElementById('userAction');
const panels = {
  create: document.getElementById('panel-create'),
  delete: document.getElementById('panel-delete'),
  modify: document.getElementById('panel-modify'),
  list:   document.getElementById('panel-list'),
};
function showPanel(key) {
  Object.keys(panels).forEach(k => { if (panels[k]) panels[k].style.display = k===key ? 'block' : 'none'; });
}
if (select) {
  select.addEventListener('change', () => showPanel(select.value));
  showPanel(select.value || 'create');
}

const rulesSelect = document.getElementById('rules-metier-select');
const ruleSets = document.querySelectorAll('.vx-rules-fieldset');
function showRules(slug) {
  ruleSets.forEach(fs => fs.classList.toggle('active', fs.id === 'metier-' + slug));
}
if (rulesSelect) {
  rulesSelect.addEventListener('change', () => showRules(rulesSelect.value));
  showRules(rulesSelect.value);
}

const backTop = document.getElementById('vx-back-top');
window.addEventListener('scroll', () => { backTop.style.display = window.scrollY > 200 ? 'block' : 'none'; }, {passive:true});
backTop.addEventListener('click', () => window.scrollTo({top:0,behavior:'smooth'}));

document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const el = document.getElementById(a.getAttribute('href').slice(1));
    if (el) { e.preventDefault(); el.scrollIntoView({behavior:'smooth',block:'start'}); }
  });
});
</script>
</body>
</html>
