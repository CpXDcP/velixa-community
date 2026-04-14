<?php
require_once __DIR__ . '/inc/bootstrap.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$usersFile = "users.json";
$rulesFile = "rules_by_metier.json";
$logsFile  = "logs.csv";
$availableRules = [
    "rgpd" => "RGPD",
    "nis2" => "NIS 2",
    "iso27001" => "ISO 27001",
    "hipaa" => "HIPAA",
    "confidentialite_rh" => "RH",
    "finance" => "Finance",
    "confidentialite_legale" => "Legal",
    "donnees_sante" => "health"
];

$roles = ['admin', 'user'];
$message = '';
$rulesByMetier = file_exists($rulesFile) ? json_decode(file_get_contents($rulesFile), true) : [];
$users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];

/* Helper slug */
function vx_slug($s) {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    return trim($s, '-');
}

/* ==== KEEP EXACT SERVER LOGIC ==== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'];
        $metier = $_POST['metier'];

        if (!isset($users[$username])) {
            $users[$username] = ['password' => $password, 'role' => $role, 'metier' => $metier];
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            $message = "✅ User $username created.";
        } else {
            $message = "⚠️ User already exists.";
        }
    }

    if (isset($_POST['delete_user'])) {
        $username = $_POST['delete_user'];
        if (isset($users[$username])) {
            unset($users[$username]);
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            $message = "🗑️ User $username deleted.";
        }
    }

    if (isset($_POST['save_rules'])) {
        $newRules = [];
        foreach ($_POST['metier'] as $metier => $rules) {
            $newRules[$metier] = array_keys($rules);
        }
        file_put_contents($rulesFile, json_encode($newRules, JSON_PRETTY_PRINT));
        $message = "✅ Rules updated.";
    }

    /* Minimal patch: modify user */
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
            $message = "✏️ User $username updated.";
        } else {
            $message = "⚠️ User not found for update.";
        }
    }
}

/* ==== Light stats (unchanged) ==== */
$BOTS_REGISTRY = __DIR__ . '/inc/bots_registry.json';
$EGRESS_LOG    = __DIR__ . '/logs/egress.ndjson';
$BOTS_COSTS    = __DIR__ . '/logs/bots_costs.json';

$bots = file_exists($BOTS_REGISTRY) ? json_decode(@file_get_contents($BOTS_REGISTRY), true) : [];
$__botsCount = is_array($bots) ? count($bots) : 0;

$__lastBotEvents = [];
if (file_exists($EGRESS_LOG)) {
  $lines = @file($EGRESS_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (is_array($lines)) {
    $lines = array_slice($lines, -50);
    foreach ($lines as $ln) {
      $row = json_decode($ln, true);
      if (is_array($row) && (($row['actor']['type'] ?? '') === 'bot')) {
        $__lastBotEvents[] = $row;
      }
    }
  }
}

$__botsCosts       = file_exists($BOTS_COSTS) ? json_decode(@file_get_contents($BOTS_COSTS), true) : [];
$__totalBotsCost   = 0.0;
if (is_array($__botsCosts)) {
  foreach ($__botsCosts as $cid => $c) { $__totalBotsCost += (float)($c['total_usd'] ?? 0); }
}

/* AD/LDAP */
$DIR_CONNECTORS = __DIR__ . '/config/directory_connectors.json';
$DIR_PIPELINES  = __DIR__ . '/config/directory_pipelines.json';
$DIR_SYNC_LOG   = __DIR__ . '/logs/directory_sync.jsonl';

$__dirConnectors = file_exists($DIR_CONNECTORS) ? json_decode(@file_get_contents($DIR_CONNECTORS), true) : [];
$__dirPipes      = file_exists($DIR_PIPELINES)  ? json_decode(@file_get_contents($DIR_PIPELINES),  true) : [];
$__connectors    = is_array($__dirConnectors) ? (array)($__dirConnectors['connectors'] ?? []) : [];
$__pipelines     = is_array($__dirPipes)      ? (array)($__dirPipes['pipelines'] ?? []) : [];

$__connectorsCount = count($__connectors);
$__pipelinesCount  = count($__pipelines);

$__lastRun = '-';
if (!empty($__pipelines)) {
  $dates = array_filter(array_map(fn($p)=>$p['last_run'] ?? null, $__pipelines));
  if (!empty($dates)) {
    rsort($dates);
    $__lastRun = $dates[0];
  }
}

/* ===== Providers config (READ from admin_providers) ===== */
$providersCfgFile = __DIR__ . "/config/providers.json";
$providersCfg = [
  'mode' => 'global',
  'global' => [
    'active_provider' => 'groq',
    'providers' => []
  ],
  'per_metier' => []
];
if (file_exists($providersCfgFile)) {
  $pc = json_decode(@file_get_contents($providersCfgFile), true);
  if (is_array($pc)) $providersCfg = $pc;
}
$PROV_LIST = ['groq','openai','anthropic','gemini'];
function vx_pricing_defaults(){
  return ['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0.0,'output_price'=>0.0,'calculation_mode'=>'proportional'];
}
function vx_norm_provider_block($block){
  $d = vx_pricing_defaults();
  $p = $block['pricing'] ?? [];
  return [
    'enabled'  => (bool)($block['enabled'] ?? false),
    'model'    => $block['model'] ?? '',
    'pricing'  => [
      'currency' => $p['currency'] ?? $d['currency'],
      'unit_tokens' => (int)($p['unit_tokens'] ?? $d['unit_tokens']),
      'input_price' => (float)($p['input_price'] ?? $d['input_price']),
      'output_price'=> (float)($p['output_price'] ?? $d['output_price']),
      'calculation_mode' => in_array(($p['calculation_mode'] ?? $d['calculation_mode']), ['proportional','ceil'], true)
                            ? ($p['calculation_mode'] ?? $d['calculation_mode']) : $d['calculation_mode']
    ]
  ];
}

/* ===== Audit (accepted/refused) — élargi + fallback ===== */
$auditFile = __DIR__ . "/audit_logs.json";
$entries = [];
if (file_exists($auditFile)) {
    $raw = @file_get_contents($auditFile);
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $entries = isset($json['logs']) && is_array($json['logs']) ? $json['logs'] : $json;
    }
}
/* normalisation */
$norm = static function($v): string {
  $s = strtolower(trim((string)$v));
  $s = str_replace(['.', ',', ';', ':'], '', $s);
  return $s;
};
$vx_isAccepted = static function($s) use ($norm){
  $s = $norm($s);
  return in_array($s, [
    'ok','accepted','accept','allow','allowed','pass','passed',
    'accepte','accepté','valide','validé'
  ], true);
};
$vx_isRefused = static function($s) use ($norm){
  $s = $norm($s);
  return in_array($s, [
    'refuse','refusé','rejeté','rejete','rejected','deny','denied','blocked','block',
    'ko','non conforme','not allowed','not_allowed',
    'policy_violation','policy_block','blocked_by_policy','blocked_by_rule','blocked_by_guardrail'
  ], true);
};

$totalPrompts = 0; $okPrompts = 0; $koPrompts = 0;
foreach ($entries as $e) {
  if (!is_array($e)) continue;
  $totalPrompts++;
  $st = $e['status'] ?? $e['result'] ?? $e['decision'] ?? $e['outcome'] ?? '';
  if ($vx_isAccepted($st)) { $okPrompts++; continue; }
  if ($vx_isRefused($st))  { $koPrompts++; continue; }
  if (isset($e['allowed']) && $e['allowed'] === true)  { $okPrompts++; continue; }
  if (isset($e['allowed']) && $e['allowed'] === false) { $koPrompts++; continue; }
}
/* fallback : reste => refusés */
$unknown = $totalPrompts - $okPrompts - $koPrompts;
if ($unknown > 0) { $koPrompts += $unknown; }

/* ===== Cost assumptions ===== */
$VX_TOKENS_PER_PROMPT_IN  = 350;
$VX_TOKENS_PER_PROMPT_OUT = 650;

/* ===== Build normalized providers map + enabled flags ===== */
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
} else {
  if (isset($providersCfg['per_metier']) && is_array($providersCfg['per_metier'])) {
    foreach ($providersCfg['per_metier'] as $m => $block) {
      if (!isset($block['providers'])) continue;
      foreach ($PROV_LIST as $pname) {
        $pb = vx_norm_provider_block($block['providers'][$pname] ?? []);
        if (!empty($pb['enabled'])) $enabledProviders[$pname] = true;
      }
    }
  }
  if (empty($enabledProviders)) $enabledProviders['groq'] = true;
}

/* ===== Helper: compute cost ===== */
function vx_cost_calc($tokensIn, $tokensOut, $pricing){
  $unit = max(1, (int)($pricing['unit_tokens'] ?? 1000000));
  $pin  = (float)($pricing['input_price'] ?? 0.0);
  $pout = (float)($pricing['output_price'] ?? 0.0);
  $mode = $pricing['calculation_mode'] ?? 'proportional';

  if ($mode === 'ceil') {
    $costIn  = ($tokensIn  > 0) ? (ceil($tokensIn  / $unit) * $pin)  : 0.0;
    $costOut = ($tokensOut > 0) ? (ceil($tokensOut / $unit) * $pout) : 0.0;
  } else {
    $costIn  = ($tokensIn  / $unit) * $pin;
    $costOut = ($tokensOut / $unit) * $pout;
  }
  return [$costIn, $costOut, $costIn + $costOut];
}

/* ===== Compute rows for costs table ===== */
$inTokensTotal  = $okPrompts * $VX_TOKENS_PER_PROMPT_IN;
$outTokensTotal = $okPrompts * $VX_TOKENS_PER_PROMPT_OUT;
$totalTokens    = $inTokensTotal + $outTokensTotal;

$rows = []; // per provider summary
$grandTotalCost = 0.0;

// Initialize rows with config details
foreach ($PROV_LIST as $pname) {
  $gBlock = $normGlobalProviders[$pname] ?? vx_norm_provider_block([]);
  $rows[$pname] = [
    'provider'     => strtoupper($pname),
    'enabled'      => !empty($enabledProviders[$pname]),
    'model'        => $gBlock['model'] ?? '',
    'currency'     => $gBlock['pricing']['currency'],
    'unit'         => (int)$gBlock['pricing']['unit_tokens'],
    'price_in'     => (float)$gBlock['pricing']['input_price'],
    'price_out'    => (float)$gBlock['pricing']['output_price'],
    'calc_mode'    => $gBlock['pricing']['calculation_mode'],
    'prompts_ok'   => 0,
    'tokens_in'    => 0,
    'tokens_out'   => 0,
    'tokens_total' => 0,
    'cost_in'      => 0.0,
    'cost_out'     => 0.0,
    'cost_total'   => 0.0
  ];
}

if ($activeMode === 'global') {
  $prov = $activeProvider;
  if (!isset($rows[$prov])) $rows[$prov] = $rows['groq'];
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

  $rows[$prov]['model']     = $gBlock['model'];
  $rows[$prov]['currency']  = $pricing['currency'];
  $rows[$prov]['unit']      = (int)$pricing['unit_tokens'];
  $rows[$prov]['price_in']  = (float)$pricing['input_price'];
  $rows[$prov]['price_out'] = (float)$pricing['output_price'];
  $rows[$prov]['calc_mode'] = $pricing['calculation_mode'];

  $grandTotalCost = $cTot;

} else {
  // per_metier: distribute evenly
  $metiersList = array_keys($rulesByMetier);
  $mCount = max(1, count($metiersList));
  $promptsPerMetier = ($mCount > 0) ? floor($okPrompts / $mCount) : 0;
  $remainder = ($mCount > 0) ? ($okPrompts % $mCount) : 0;

  $i = 0;
  foreach ($metiersList as $metier) {
    $extra = ($i < $remainder) ? 1 : 0; $i++;
    $mPrompts = $promptsPerMetier + $extra;
    if ($mPrompts <= 0) continue;

    $mIn  = $mPrompts * $VX_TOKENS_PER_PROMPT_IN;
    $mOut = $mPrompts * $VX_TOKENS_PER_PROMPT_OUT;

    $block = $providersCfg['per_metier'][$metier] ?? null;
    $mActive = is_array($block) ? ($block['active_provider'] ?? 'groq') : 'groq';
    $mProvBlock = is_array($block) && isset($block['providers'][$mActive]) ? vx_norm_provider_block($block['providers'][$mActive]) : vx_norm_provider_block([]);

    [$cIn,$cOut,$cTot] = vx_cost_calc($mIn, $mOut, $mProvBlock['pricing']);

    if (!isset($rows[$mActive])) $rows[$mActive] = $rows['groq'];
    $rows[$mActive]['prompts_ok']   += $mPrompts;
    $rows[$mActive]['tokens_in']    += $mIn;
    $rows[$mActive]['tokens_out']   += $mOut;
    $rows[$mActive]['tokens_total'] += ($mIn + $mOut);
    $rows[$mActive]['cost_in']      += $cIn;
    $rows[$mActive]['cost_out']     += $cOut;
    $rows[$mActive]['cost_total']   += $cTot;

    $rows[$mActive]['model']     = $mProvBlock['model'];
    $rows[$mActive]['currency']  = $mProvBlock['pricing']['currency'];
    $rows[$mActive]['unit']      = (int)$mProvBlock['pricing']['unit_tokens'];
    $rows[$mActive]['price_in']  = (float)$mProvBlock['pricing']['input_price'];
    $rows[$mActive]['price_out'] = (float)$mProvBlock['pricing']['output_price'];
    $rows[$mActive]['calc_mode'] = $mProvBlock['pricing']['calculation_mode'];
  }

  $grandTotalCost = array_sum(array_map(fn($r)=>$r['cost_total'], $rows));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Dashboard</title>
  <link rel="stylesheet" href="style.css">
  <style>
    :root{
      --vx-bg:#0B0C0E; --vx-surface:#121416; --vx-surface-2:#0f1317; --vx-border:#1f242b;
      --vx-text:#FFFFFF; --vx-muted:#9CA3AF;
      --vx-green:#10B981; --vx-green-2:#0EA371;
      --vx-purple:#8B5CF6;
      --vx-radius:14px;
    }
    *{box-sizing:border-box}
    a{color:inherit;text-decoration:none}
    a:hover{text-decoration:none;filter:brightness(1.04)}
    body{margin:0;background:var(--vx-bg);color:var(--vx-text);
         font-family:Inter,Roboto,system-ui,-apple-system,Segoe UI,Arial,sans-serif;}
    .app{min-height:100vh;display:flex;flex-direction:column}

    /* Topbar */
    .vx-topbar{position:sticky;top:0;z-index:10;display:grid;grid-template-columns:1fr auto 1fr;
      align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--vx-border);
      background:linear-gradient(180deg,#0f1114 0%, #0b0c0e 100%);}
    .vx-topbrand{justify-self:center;display:flex;align-items:center;gap:12px}
    .vx-topbrand img{height:100px;width:200px;display:block}
    .vx-logout{background:#18231e;color:#e5e7eb;border:1px solid var(--vx-green);padding:8px 12px;border-radius:10px;cursor:pointer;
      transition:transform .12s ease, box-shadow .12s ease, filter .12s ease}
    .vx-logout:hover{filter:brightness(1.08);box-shadow:0 0 0 3px rgba(16,185,129,.35),0 8px 24px rgba(0,0,0,.45)}
    .vx-topbar > a{justify-self:end}

    /* Main */
    .vx-main{padding:22px}
    .vx-panels{display:grid;gap:18px}
    @media(min-width:1100px){ .vx-panels{grid-template-columns:1fr 1fr;} }
    .vx-full{grid-column:1 / -1;}
    .vx-panel{background:var(--vx-surface);border:1px solid var(--vx-border);border-radius:var(--vx-radius);padding:18px}
    .vx-panel h3{margin:0 0 12px 0;font-size:18px}
    .vx-message{margin:0 0 18px 0;padding:12px 14px;border-radius:12px;background:#102312;border:1px solid #194a1d;color:#d9fbd0}

    /* Compact panels (height aligned) */
    .vx-compact{max-height:420px; overflow:auto}

    table{width:100%;border-collapse:collapse;background:var(--vx-surface-2);border-radius:12px;overflow:hidden}
    th,td{padding:10px 12px;border-bottom:1px solid #1b2128}
    th{background:#12181f;text-align:left;color:#e5e7eb;font-weight:700}
    tr:last-child td{border-bottom:none}
    tr:hover td{background:#131a21}

    /* Inputs */
    label{display:inline-block;margin:6px 0}
    input[type="text"], input[type="password"], select{
      background:var(--vx-surface-2); color:#e5e7eb; border:1px solid #222933; border-radius:10px;
      padding:10px 12px; margin:6px 0; width:260px; outline:none;
      transition:box-shadow .15s,border-color .15s, filter .12s ease;
    }
    input[type="text"]:focus, input[type="password"]:focus, select:focus{
      border-color:var(--vx-green); box-shadow:0 0 0 3px rgba(16,185,129,.30);
    }
    .vx-select{background:var(--vx-surface-2);color:#e5e7eb;border:1px solid #222933;border-radius:10px;padding:10px 12px;width:280px}

    /* Buttons: green default */
    input[type="submit"], button, .vx-btn{
      background:linear-gradient(180deg, var(--vx-green), var(--vx-green-2));
      color:#0a1612; border:1px solid var(--vx-green); border-radius:10px;
      padding:10px 14px; cursor:pointer; font-weight:800;
      box-shadow:0 6px 16px rgba(16,185,129,.28);
      transition:transform .12s ease, box-shadow .12s ease, filter .12s ease;
    }
    input[type="submit"]:hover, button:hover, .vx-btn:hover{
      filter:brightness(1.08);
      box-shadow:0 0 0 4px rgba(16,185,129,.45), 0 10px 28px rgba(0,0,0,.50);
      transform:translateY(-1px);
    }
    input[type="submit"]:active, button:active, .vx-btn:active{transform:translateY(0)}
    .vx-btn-secondary{
      background:#0f1b17; color:#d9fff3; border:1px solid rgba(16,185,129,.5); box-shadow:none;
    }
    .vx-btn-secondary:hover{
      box-shadow:0 0 0 4px rgba(16,185,129,.35), 0 8px 22px rgba(0,0,0,.45);
      filter:brightness(1.06);
    }
    /* Save buttons: purple (by name) */
    input[type="submit"][name="save_rules"],
    input[type="submit"][name="modify_user"],
    .vx-btn.save{
      background:linear-gradient(180deg, var(--vx-purple), #6D28D9);
      color:#fff; border:1px solid var(--vx-purple);
      box-shadow:0 6px 16px rgba(139,92,246,.35);
    }
    input[type="submit"][name="save_rules"]:hover,
    input[type="submit"][name="modify_user"]:hover,
    .vx-btn.save:hover{
      box-shadow:0 0 0 4px rgba(139,92,246,.45), 0 10px 28px rgba(0,0,0,.55);
      filter:brightness(1.06); transform:translateY(-1px);
    }

    fieldset{border:1px solid var(--vx-border);border-radius:12px;padding:12px;margin:8px 0;background:var(--vx-surface-2)}
    legend{padding:0 8px;font-weight:700}
    .vx-actions-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}

    .vx-kpis{display:grid;gap:12px;grid-template-columns:repeat(2,1fr);margin-bottom:18px}
    .vx-kpi{background:#0f1317;border:1px solid var(--vx-border);border-radius:12px;padding:14px}
    .vx-kpi-title{color:#cfd6dd;font-size:13px;margin-bottom:6px}
    .vx-kpi-value{font-weight:800;font-size:22px}

    /* Tiles */
    .vx-tiles{display:grid;gap:14px;margin:14px 0 18px 0;grid-template-columns: repeat( auto-fill, minmax(240px, 1fr) )}
    .vx-tile{color:#e5e7eb;background:#0f1317;border:1px solid var(--vx-border);border-radius:14px;padding:16px;display:block;
      transition:transform .12s ease, filter .12s ease, box-shadow .12s ease}
    .vx-tile:hover{transform:translateY(-2px);filter:brightness(1.05);box-shadow:0 10px 26px rgba(0,0,0,.35)}
    .vx-tile-emoji{font-size:22px;margin-bottom:8px}
    .vx-tile-title{font-weight:800;margin-bottom:4px}
    .vx-tile-desc{color:#9CA3AF;font-size:13px}

    /* Rules — dropdown (one métier at a time) */
    .vx-rules-select-wrap{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
    .vx-rules-fieldset{display:none}
    .vx-rules-fieldset.active{display:block}
    .vx-note{color:#9CA3AF;font-size:13px;margin-top:6px}

    /* Footer */
    .vx-footer{margin:22px;display:flex;justify-content:center}
    .vx-footer .vx-logout{background:#18231e;border-color:var(--vx-green)}

    /* Back to top */
    #vx-back-top{
      position:fixed; right:18px; bottom:18px; z-index:9999; background:#0f1b17; border:1px solid rgba(16,185,129,.5); color:#d9fff3;
      border-radius:999px; padding:10px 14px; cursor:pointer; display:none; box-shadow:0 8px 24px rgba(0,0,0,.35);
      transition:box-shadow .12s ease, filter .12s ease, transform .12s ease;
    }
    #vx-back-top:hover{ filter:brightness(1.08); box-shadow:0 0 0 4px rgba(16,185,129,.35), 0 10px 26px rgba(0,0,0,.5); transform:translateY(-1px) }
  </style>
</head>
<body>

<div class="app">
  <!-- Header -->
  <header class="vx-topbar">
    <div></div>
    <div class="vx-topbrand">
      <?php if (file_exists('assets/velixa-logo.png')): ?>
        <img src="assets/velixa-logo.png" alt="Velixa">
      <?php else: ?>
        <span style="font-weight:800;letter-spacing:.14em;">VELIXA</span>
      <?php endif; ?>
    </div>
    <a href="logout.php"><button class="vx-logout">🚪 Log out</button></a>
  </header>

  <main class="vx-main">

    <?php if (!empty($message)): ?>
      <div class="vx-message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Tiles -->
    <div class="vx-tiles">
      <a href="#users" class="vx-tile">
        <div class="vx-tile-emoji">👤</div>
        <div class="vx-tile-title">Create / manage users</div>
        <div class="vx-tile-desc">Add, view, edit and delete</div>
      </a>

      <a href="#rules" class="vx-tile">
        <div class="vx-tile-emoji">🧩</div>
        <div class="vx-tile-title">Rules by job</div>
        <div class="vx-tile-desc">Configure active rules</div>
      </a>

      <a href="#providers" class="vx-tile">
        <div class="vx-tile-emoji">🤖</div>
        <div class="vx-tile-title">AI & Multimodal</div>
        <div class="vx-tile-desc">Manage API keys & options</div>
      </a>

      <a href="#bots" class="vx-tile">
        <div class="vx-tile-emoji">🤖</div>
        <div class="vx-tile-title">Bots & Agents</div>
        <div class="vx-tile-desc">Connection, rules & egress</div>
      </a>

      <a href="admin_agents.php" class="vx-tile">
        <div class="vx-tile-emoji">🧠</div>
        <div class="vx-tile-title">Agents IA</div>
        <div class="vx-tile-desc">Contrôle flux agents — spécialisés & généralistes</div>
      </a>

      <a href="agent_report_euaiact.php" class="vx-tile">
        <div class="vx-tile-emoji">🏛</div>
        <div class="vx-tile-title">Rapport EU AI Act</div>
        <div class="vx-tile-desc">Conformité réglementaire agents IA — export PDF</div>
      </a>

      <a href="agent_dashboard.php" class="vx-tile">
        <div class="vx-tile-emoji">⚡</div>
        <div class="vx-tile-title">Dashboard Temps Réel</div>
        <div class="vx-tile-desc">Flux agents en direct — actualisation 5s</div>
      </a>

      <a href="agent_traces.php" class="vx-tile">
        <div class="vx-tile-emoji">🔗</div>
        <div class="vx-tile-title">Traçabilité Chaînes</div>
        <div class="vx-tile-desc">Flux agent-à-agent — visualisation complète</div>
      </a>

      <a href="agent_settings.php" class="vx-tile">
        <div class="vx-tile-emoji">⚙️</div>
        <div class="vx-tile-title">Paramètres Agents</div>
        <div class="vx-tile-desc">Rétention, watermark, cache, tokenisation PII, HITL</div>
      </a>

      <a href="admin_roi.php" class="vx-tile">
        <div class="vx-tile-emoji">💰</div>
        <div class="vx-tile-title">ROI Exécutif</div>
        <div class="vx-tile-desc">Économies estimées, blocages, coûts IA — vue COMEX</div>
      </a>

      <a href="admin_compliance_history.php" class="vx-tile">
        <div class="vx-tile-emoji">📈</div>
        <div class="vx-tile-title">Historique Conformité</div>
        <div class="vx-tile-desc">Score de conformité dans le temps — 90 jours</div>
      </a>

      <a href="admin_webhooks.php" class="vx-tile">
        <div class="vx-tile-emoji">🔔</div>
        <div class="vx-tile-title">Webhooks</div>
        <div class="vx-tile-desc">Notifications Slack, Teams, webhook générique</div>
      </a>

      <a href="admin_hitl.php" class="vx-tile">
        <div class="vx-tile-emoji">👁️</div>
        <div class="vx-tile-title">Approbations Humaines</div>
        <div class="vx-tile-desc">HITL — EU AI Act Art.14 — file d'approbation</div>
      </a>

      <a href="admin_legal_hold.php" class="vx-tile">
        <div class="vx-tile-emoji">🔒</div>
        <div class="vx-tile-title">Legal Hold</div>
        <div class="vx-tile-desc">Snapshots signés SHA256 pour usage légal</div>
      </a>

      <a href="consent.php" class="vx-tile">
        <div class="vx-tile-emoji">📋</div>
        <div class="vx-tile-title">Charte d'usage IA</div>
        <div class="vx-tile-desc">Consentements tracés EU AI Act Art.52</div>
      </a>

      <a href="#directories" class="vx-tile">
        <div class="vx-tile-emoji">🏢</div>
        <div class="vx-tile-title">Directory AD/LDAP</div>
        <div class="vx-tile-desc">Connectors & pipelines (OU → jobs)</div>
      </a>

      <a href="#logs" class="vx-tile">
        <div class="vx-tile-emoji">📊</div>
        <div class="vx-tile-title">Export logs</div>
        <div class="vx-tile-desc">CSV / PDF anonymised</div>
      </a>

      <a href="audit_admin.php" class="vx-tile">
        <div class="vx-tile-emoji">🕵️</div>
        <div class="vx-tile-title">Audit prompts</div>
        <div class="vx-tile-desc">Consulter & demander accès aux prompts</div>
      </a>

      <a href="view_logs.php" class="vx-tile">
        <div class="vx-tile-emoji">📋</div>
        <div class="vx-tile-title">Logs complets</div>
        <div class="vx-tile-desc">Audit, sécurité, egress bots</div>
      </a>

      <a href="#costs" class="vx-tile">
        <div class="vx-tile-emoji">💰</div>
        <div class="vx-tile-title">AI costs</div>
        <div class="vx-tile-desc">Estimate per provider</div>
      </a>

      <a href="#secure" class="vx-tile">
        <div class="vx-tile-emoji">🔐</div>
        <div class="vx-tile-title">Secured access</div>
        <div class="vx-tile-desc">Critical prompts & traces</div>
      </a>
    </div>

    <!-- KPIs -->
    <div id="dashboard" class="vx-kpis">
      <div class="vx-kpi">
        <div class="vx-kpi-title">Users</div>
        <div class="vx-kpi-value"><?= count($users) ?></div>
      </div>
      <div class="vx-kpi">
        <div class="vx-kpi-title">Configured jobs</div>
        <div class="vx-kpi-value"><?= count($rulesByMetier) ?></div>
      </div>
    </div>

    <div class="vx-panels">

      <!-- Users -->
      <div id="users" class="vx-panel vx-full">
        <h3>👥 Manage users</h3>
        <label for="userAction">Action:</label><br>
        <select id="userAction" class="vx-select" aria-label="Choose a user action">
          <option value="create">Create</option>
          <option value="delete">Delete</option>
          <option value="modify">Modify</option>
          <option value="list">List</option>
        </select>

        <!-- Create -->
        <div id="panel-create" class="vx-subpanel active">
          <h4 style="margin:14px 0 8px">Create user</h4>
          <form method="post">
        <?= vx_csrf_field() ?>
            <label>Username:<br><input type="text" name="username" required></label><br>
            <label>Password:<br><input type="password" name="password" required></label><br>
            <label>Role:<br>
              <select name="role">
                <?php foreach ($roles as $role): ?>
                  <option value="<?= $role ?>"><?= $role ?></option>
                <?php endforeach; ?>
              </select>
            </label><br>
            <label>Job:<br>
              <select name="metier" required>
                <?php foreach (array_keys($rulesByMetier) as $metierOption): ?>
                  <option value="<?= htmlspecialchars($metierOption) ?>"><?= htmlspecialchars($metierOption) ?></option>
                <?php endforeach; ?>
              </select>
            </label><br><br>
            <input type="submit" name="create_user" value="Create user">
          </form>
        </div>

        <!-- Delete -->
        <div id="panel-delete" class="vx-subpanel">
          <h4 style="margin:14px 0 8px">Delete user</h4>
          <form method="post" class="vx-actions-row">
        <?= vx_csrf_field() ?>
            <select name="delete_user" required>
              <option value="">— Select a user —</option>
              <?php foreach ($users as $username => $data): ?>
                <option value="<?= htmlspecialchars($username) ?>"><?= htmlspecialchars($username) ?> (<?= htmlspecialchars($data['role']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <input type="submit" value="Delete" class="vx-btn vx-btn-secondary">
          </form>
        </div>

        <!-- Modify -->
        <div id="panel-modify" class="vx-subpanel">
          <h4 style="margin:14px 0 8px">Modify user</h4>
          <form method="post">
        <?= vx_csrf_field() ?>
            <label>User to modify:<br>
              <select name="username_existing" required>
                <option value="">— Select —</option>
                <?php foreach ($users as $username => $data): ?>
                  <option value="<?= htmlspecialchars($username) ?>"><?= htmlspecialchars($username) ?></option>
                <?php endforeach; ?>
              </select>
            </label><br>

            <label>New role:<br>
              <select name="new_role">
                <option value="">— don't change —</option>
                <?php foreach ($roles as $role): ?>
                  <option value="<?= $role ?>"><?= $role ?></option>
                <?php endforeach; ?>
              </select>
            </label><br>

            <label>New job:<br>
              <select name="new_metier">
                <option value="">— don't change —</option>
                <?php foreach (array_keys($rulesByMetier) as $metierOption): ?>
                  <option value="<?= htmlspecialchars($metierOption) ?>"><?= htmlspecialchars($metierOption) ?></option>
                <?php endforeach; ?>
              </select>
            </label><br>

            <label>New password (optional):<br>
              <input type="password" name="new_password" placeholder="Leave empty to keep current">
            </label><br><br>

            <input type="submit" name="modify_user" value="Save changes">
          </form>
          <div class="vx-note">Leave a field empty if you do not want to modify it.</div>
        </div>

        <!-- List -->
        <div id="panel-list" class="vx-subpanel">
          <details id="vx-users-collapsible">
            <summary style="cursor:pointer;font-weight:800">Existing users (<?= count($users) ?>)</summary>
            <div style="margin-top:10px">
              <table>
                <tr><th>Username</th><th>Role</th><th>Job</th><th>Action</th></tr>
                <?php foreach ($users as $username => $data): ?>
                  <tr>
                    <td><?= htmlspecialchars($username) ?></td>
                    <td><?= htmlspecialchars($data['role']) ?></td>
                    <td><?= htmlspecialchars($data['metier'] ?? '-') ?></td>
                    <td>
                      <form method="post" style="display:inline;">
        <?= vx_csrf_field() ?>
                        <input type="hidden" name="delete_user" value="<?= $username ?>">
                        <input type="submit" value="Delete" class="vx-btn vx-btn-secondary">
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </div>
          </details>
        </div>
      </div>

      <!-- Rules by job -->
      <div id="rules" class="vx-panel vx-compact">
        <h3>🧩 Rules by job</h3>

        <div class="vx-rules-select-wrap">
          <label for="rules-metier-select">Select a job:</label>
          <select id="rules-metier-select" class="vx-select">
            <?php
            $firstMetier = null;
            foreach ($rulesByMetier as $metier => $_) {
              if ($firstMetier === null) $firstMetier = $metier;
              echo '<option value="'.htmlspecialchars(vx_slug($metier)).'">'.htmlspecialchars($metier).'</option>';
            }
            ?>
          </select>
          <span class="vx-note">Only the selected job shows its checkboxes (POST format unchanged).</span>
        </div>

        <form method="post">
        <?= vx_csrf_field() ?>
          <?php foreach ($rulesByMetier as $metier => $selectedRules): ?>
            <?php $__slug = vx_slug($metier); ?>
            <fieldset id="metier-<?= $__slug ?>" class="vx-rules-fieldset">
              <legend><?= htmlspecialchars($metier) ?></legend>
              <?php foreach ($availableRules as $ruleKey => $ruleLabel): ?>
                <label>
                  <input type="checkbox" name="metier[<?= $metier ?>][<?= $ruleKey ?>]"
                         <?= in_array($ruleKey, $selectedRules) ? "checked" : "" ?>>
                  <?= $ruleLabel ?>
                </label><br>
              <?php endforeach; ?>
            </fieldset>
            <br>
          <?php endforeach; ?>
          <input type="submit" name="save_rules" value="Save rules">
        </form>
      </div>

      <!-- AI & Multimodal (reads config) -->
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
        <h3>⚙️ AI & Multimodal</h3>
        <p class="vx-note" style="margin-top:6px">
          Centralized configuration loaded from <code>config/providers.json</code>.
          Mode: <strong><?= htmlspecialchars($activeMode) ?></strong>.
        </p>

        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px;">
          <a href="admin_providers.php"><span class="vx-btn">Open config</span></a>
          <span class="vx-btn vx-btn-secondary" title="Groq status">
            Groq key store: <?= $__groqConfigured ? '✅ configured' : '❌ not configured' ?>
          </span>
        </div>

        <div style="margin-top:12px; overflow:auto;">
          <table>
            <tr>
              <th>Provider</th>
              <th>Enabled</th>
              <th>Model</th>
              <th>Currency</th>
              <th>Unit (tokens)</th>
              <th>Price IN / unit</th>
              <th>Price OUT / unit</th>
              <th>Calc. mode</th>
            </tr>
            <?php foreach ($PROV_LIST as $pv):
              $pb = $normGlobalProviders[$pv] ?? vx_norm_provider_block([]);
              $ena = !empty($enabledProviders[$pv]);
            ?>
              <tr>
                <td><?= strtoupper($pv) ?></td>
                <td><?= $ena ? '✅' : '⛔' ?></td>
                <td><?= htmlspecialchars($pb['model'] ?: '-') ?></td>
                <td><?= htmlspecialchars($pb['pricing']['currency']) ?></td>
                <td><?= number_format((int)$pb['pricing']['unit_tokens']) ?></td>
                <td><?= htmlspecialchars($pb['pricing']['input_price']) ?></td>
                <td><?= htmlspecialchars($pb['pricing']['output_price']) ?></td>
                <td><?= htmlspecialchars($pb['pricing']['calculation_mode']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
          <?php if ($activeMode === 'per_metier'): ?>
            <div class="vx-note" style="margin-top:8px">
              Per job mode: actual billing uses each job’s active provider.
              Below, costs are computed accordingly by distributing accepted prompts evenly across jobs (no per-job logs available).
            </div>
          <?php endif; ?>
        </div>

        <div class="vx-note" style="margin-top:10px">
          Keys are encrypted server-side (AES-256-GCM) and never sent back to the browser.
        </div>
      </div>

      <!-- Bots & Agents -->
      <div id="bots" class="vx-panel">
        <h3>🤖 Bots & Agents — Connection & egress</h3>
        <p class="vx-note" style="margin-top:6px">Declare bots (local/cloud), attach to a job, audit outgoing flows.</p>

        <div class="vx-actions-row" style="margin-top:10px">
          <a href="admin_bots.php" class="vx-btn">Manage / connect bots</a>
          <a href="bots_egress_logs.php" class="vx-btn vx-btn-secondary">📄 Logs (bots)</a>
          <a href="export_bots_pdf.php" class="vx-btn vx-btn-secondary">📄 Export PDF (violations)</a>
          <a href="export_bots_costs_csv.php" class="vx-btn vx-btn-secondary">💰 Export costs (CSV)</a>
        </div>

        <div class="vx-note" style="margin-top:10px">
          Registered bots: <strong><?= (int)$__botsCount ?></strong> • Cumulative costs (USD): <strong><?= number_format($__totalBotsCost, 2, '.', ' ') ?></strong>
        </div>
      </div>

      <!-- Directory AD/LDAP -->
      <div id="directories" class="vx-panel vx-compact">
        <h3>🏢 Directory AD/LDAP — Connectors & Pipelines</h3>
        <p class="vx-note" style="margin-top:6px">
          Create <strong>connectors</strong> (AD/LDAP) and <strong>pipelines</strong> to auto-import your <em>OU</em> as <em>jobs</em>.
          Rules by job remain managed here as usual.
        </p>

        <div class="vx-actions-row" style="margin-top:10px">
          <a href="admin_directories.php" class="vx-btn">⚙️ Configure AD/LDAP</a>
          <a href="jobs/directory_sync.php" target="_blank" class="vx-btn vx-btn-secondary">⏱️ Run sync</a>
          <?php if (file_exists($DIR_SYNC_LOG)): ?>
            <a href="logs/directory_sync.jsonl" class="vx-btn vx-btn-secondary" target="_blank">📄 Logs</a>
          <?php endif; ?>
        </div>

        <div class="vx-kpis" style="margin-top:12px">
          <div class="vx-kpi"><div class="vx-kpi-title">Connectors</div><div class="vx-kpi-value"><?= (int)$__connectorsCount ?></div></div>
          <div class="vx-kpi"><div class="vx-kpi-title">Pipelines</div><div class="vx-kpi-value"><?= (int)$__pipelinesCount ?></div></div>
          <div class="vx-kpi"><div class="vx-kpi-title">Last run</div><div class="vx-kpi-value"><?= htmlspecialchars($__lastRun) ?></div></div>
        </div>

        <?php if (!empty($__pipelinesCount)): ?>
          <table style="margin-top:10px">
            <tr><th>Pipeline ID</th><th>Connector</th><th>Include</th><th>Exclude</th><th>Enabled</th><th>Last run</th></tr>
            <?php foreach ($__pipelines as $px): ?>
              <tr>
                <td><?= htmlspecialchars($px['id'] ?? '-') ?></td>
                <td><?= htmlspecialchars($px['connector_id'] ?? '-') ?></td>
                <td><?= htmlspecialchars(implode(',', (array)($px['ou_include'] ?? []))) ?></td>
                <td><?= htmlspecialchars(implode(',', (array)($px['ou_exclude'] ?? []))) ?></td>
                <td><?= !empty($px['enabled']) ? '✅' : '⛔' ?></td>
                <td><?= htmlspecialchars($px['last_run'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>

      <!-- AI costs -->
      <div id="costs" class="vx-panel vx-full">
        <h3>💰 Estimated costs per AI</h3>
        <div class="vx-note" style="margin-bottom:10px">
          Mode: <strong><?= htmlspecialchars($activeMode) ?></strong>
          <?php if ($activeMode === 'global'): ?>
            — active provider: <strong><?= htmlspecialchars(strtoupper($activeProvider)) ?></strong>
          <?php else: ?>
            — distributed evenly across jobs, then billed to each job’s active provider.
          <?php endif; ?>
          • Assumption: <?= (int)$VX_TOKENS_PER_PROMPT_IN ?> tokens IN + <?= (int)$VX_TOKENS_PER_PROMPT_OUT ?> tokens OUT per accepted prompt.
        </div>

        <div class="vx-kpis" style="margin-top:8px">
          <div class="vx-kpi"><div class="vx-kpi-title">Total prompts</div><div class="vx-kpi-value"><?= (int)$totalPrompts ?></div></div>
          <div class="vx-kpi"><div class="vx-kpi-title">Accepted</div><div class="vx-kpi-value"><?= (int)$okPrompts ?></div></div>
          <div class="vx-kpi"><div class="vx-kpi-title">Refused</div><div class="vx-kpi-value"><?= (int)$koPrompts ?></div></div>
          <div class="vx-kpi"><div class="vx-kpi-title">Estimated total costs</div><div class="vx-kpi-value">
            <?php
              $currs = array_unique(array_filter(array_map(fn($r)=>$r['cost_total']>0 ? $r['currency'] : null, $rows)));
              $currs = array_values($currs); // reindex to avoid undefined key 0
              if (count($currs) === 1) {
                $cur = $currs[0];
                echo htmlspecialchars($cur).' '.number_format($grandTotalCost, 2, '.', ' ');
              } elseif (count($currs) === 0) {
                echo number_format($grandTotalCost, 2, '.', ' ');
              } else {
                echo number_format($grandTotalCost, 2, '.', ' ').' (mixed currencies)';
              }
            ?>
          </div></div>
        </div>

        <table style="width:100%;border-collapse:collapse;background:var(--vx-surface-2);border-radius:12px;overflow:hidden;margin-top:12px">
          <tr>
            <th>Provider</th>
            <th style="text-align:center">Enabled</th>
            <th>Model</th>
            <th style="text-align:right">Prompts OK</th>
            <th style="text-align:right">Tokens IN</th>
            <th style="text-align:right">Tokens OUT</th>
            <th style="text-align:right">Unit</th>
            <th style="text-align:right">Price IN</th>
            <th style="text-align:right">Price OUT</th>
            <th style="text-align:center">Calc.</th>
            <th style="text-align:right">Cost IN</th>
            <th style="text-align:right">Cost OUT</th>
            <th style="text-align:right">Total</th>
            <th style="text-align:center">Currency</th>
          </tr>
          <?php
          $hasAny = false;
          foreach ($rows as $pv => $r):
            $hasAny = true;
          ?>
            <tr>
              <td><?= htmlspecialchars($r['provider']) ?></td>
              <td style="text-align:center"><?= $r['enabled'] ? '✅' : '⛔' ?></td>
              <td><?= htmlspecialchars($r['model'] ?: '-') ?></td>
              <td style="text-align:right"><?= (int)$r['prompts_ok'] ?></td>
              <td style="text-align:right"><?= number_format((int)$r['tokens_in']) ?></td>
              <td style="text-align:right"><?= number_format((int)$r['tokens_out']) ?></td>
              <td style="text-align:right"><?= number_format((int)$r['unit']) ?></td>
              <td style="text-align:right"><?= number_format((float)$r['price_in'], 6, '.', ' ') ?></td>
              <td style="text-align:right"><?= number_format((float)$r['price_out'], 6, '.', ' ') ?></td>
              <td style="text-align:center"><?= htmlspecialchars($r['calc_mode']) ?></td>
              <td style="text-align:right"><?= number_format((float)$r['cost_in'], 2, '.', ' ') ?></td>
              <td style="text-align:right"><?= number_format((float)$r['cost_out'], 2, '.', ' ') ?></td>
              <td style="text-align:right"><strong><?= number_format((float)$r['cost_total'], 2, '.', ' ') ?></strong></td>
              <td style="text-align:center"><?= htmlspecialchars($r['currency']) ?></td>
            </tr>
          <?php endforeach;
          if (!$hasAny): ?>
            <tr><td colspan="14" style="padding:10px 12px">No data.</td></tr>
          <?php endif; ?>
        </table>

        <div class="vx-actions-row" style="margin-top:12px">
          <button class="vx-btn vx-btn-secondary" id="vx-export-costs">📄 Export PDF (table)</button>
          <span class="vx-note">Computed from your providers configuration (prices, unit, calculation mode). Adjust in <em>AI & Multimodal</em> → <em>Open config</em>.</span>
        </div>
      </div>

      <!-- Logs -->
      <div id="logs" class="vx-panel vx-full">
        <h3>📊 Logs & Exports</h3>
        <div class="vx-actions-row">
          <a href="audit_admin.php"><span class="vx-btn">🕵️ Audit prompts</span></a>
          <a href="view_logs.php"><span class="vx-btn vx-btn-secondary">📋 Logs complets</span></a>
          <a href="export_csv.php"><span class="vx-btn vx-btn-secondary">📥 Export CSV</span></a>
          <a href="export_pdf.php"><span class="vx-btn vx-btn-secondary">📄 Export PDF</span></a>
          <a href="export_bots_pdf.php"><span class="vx-btn vx-btn-secondary">🤖 Export bots PDF</span></a>
        </div>
      </div>

      <!-- Secured access -->
      <div id="secure" class="vx-panel">
        <h3>🔐 Secured access</h3>
        <div class="vx-actions-row">
          <a href="admin_agents.php" class="vx-btn">🧠 Agents IA</a>
          <a href="agent_report_euaiact.php" class="vx-btn vx-btn-secondary">🏛 EU AI Act</a>
          <a href="agent_logs.php" class="vx-btn vx-btn-secondary">📋 Logs agents</a>
          <a href="audit_admin.php" class="vx-btn vx-btn-secondary">🕵️ Audit prompts</a>
          <a href="admin_prompt_viewer.php" class="vx-btn vx-btn-secondary">🔍 Déchiffrer un prompt</a>
          <a href="view_logs.php" class="vx-btn vx-btn-secondary">📋 Logs complets</a>
          <a href="trace_summary.php" class="vx-btn vx-btn-secondary">📊 Traces</a>
          <a href="admin_health_check.php" class="vx-btn vx-btn-secondary">❤️ Health check</a>
          <a href="tests/run_tests.php?token=velixatest" class="vx-btn vx-btn-secondary" target="_blank">🧪 Tests</a>
        </div>
        <p class="vx-note">Accès restreint • affichage anonymisé • déchiffrement via clé Velixa uniquement</p>
      </div>

    </div><!-- /.vx-panels -->
  </main>

  <!-- Bottom logout -->
  <div class="vx-footer">
    <a href="logout.php"><button class="vx-logout">🚪 Log out</button></a>
  </div>
</div>

<!-- Back to top -->
<button id="vx-back-top" title="Back to top">⬆️ page up</button>

<script>
  // Users: local action switcher
  const select = document.getElementById('userAction');
  const panels = {
    create: document.getElementById('panel-create'),
    delete: document.getElementById('panel-delete'),
    modify: document.getElementById('panel-modify'),
    list:   document.getElementById('panel-list'),
  };
  function showPanel(key){
    Object.keys(panels).forEach(k=>{ if (panels[k]) panels[k].classList.toggle('active', k===key); });
  }
  if (select){
    select.addEventListener('change', () => showPanel(select.value));
    showPanel(select.value || 'create');
  }

  // Rules: show only selected job (checkboxes kept as-is for POST)
  const rulesSelect = document.getElementById('rules-metier-select');
  const ruleSets = document.querySelectorAll('.vx-rules-fieldset');
  function showRules(slug){
    ruleSets.forEach(fs => fs.classList.toggle('active', fs.id === 'metier-' + slug));
  }
  if (rulesSelect){
    rulesSelect.addEventListener('change', () => showRules(rulesSelect.value));
    showRules(rulesSelect.value);
  }

  // Export AI costs panel to print
  document.getElementById('vx-export-costs')?.addEventListener('click', () => {
    const panel = document.getElementById('costs');
    if (!panel) return;
    const w = window.open('', 'vx_costs', 'width=950,height=800');
    if (!w) return;

    const title = '<h2 style="margin:0 0 12px;font-family:Arial, sans-serif;">VELIXA — Estimated AI costs</h2>';
    const kpis  = panel.querySelector('.vx-kpis')?.outerHTML || '';
    const table = panel.querySelector('table')?.outerHTML || '';

    w.document.write(`
      <html><head><meta charset="utf-8"><title>VELIXA — AI costs</title>
        <style>
          body{font-family:Arial, sans-serif; padding:16px;}
          table{width:100%; border-collapse:collapse; margin-top:10px;}
          th,td{border:1px solid #ccc; padding:8px; text-align:right;}
          th{text-align:left; background:#eee;}
          h2{font-weight:700;}
        </style>
      </head><body>
        ${title}
        ${kpis}
        ${table}
        <script>window.onload=()=>window.print();<\/script>
      </body></html>
    `);
    w.document.close();
  });

  // Back to top
  const backTop = document.getElementById('vx-back-top');
  const onScroll = () => { backTop.style.display = window.scrollY > 200 ? 'block' : 'none'; };
  window.addEventListener('scroll', onScroll, {passive:true});
  onScroll();
  backTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

  // Smooth scroll for tiles
  document.querySelectorAll('a[href^="#"]').forEach(a=>{
    a.addEventListener('click', (e)=>{
      const id = a.getAttribute('href').slice(1);
      const el = document.getElementById(id);
      if (el){
        e.preventDefault();
        el.scrollIntoView({behavior:'smooth', block:'start'});
      }
    });
  });

  // Ensure "Existing users" section starts closed
  const usersDetails = document.getElementById('vx-users-collapsible');
  if (usersDetails) usersDetails.open = false;
</script>

</body>
</html>




























































































































































































































































































































































































































































































































































































































































































































































































































































































































































































