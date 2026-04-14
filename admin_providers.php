<?php
require_once __DIR__ . '/inc/bootstrap.php';
/* =========================================================
   VELIXA — Admin Providers (LLM / Multimodal)
   - Admin only
   - Global mode or per job
   - Groq active by default if no config
   - Keys encrypted locally (AES-256-GCM)
   - Pricing per provider/model (currency, unit tokens, input/output price)
   ========================================================= */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
}

$CONFIG_DIR   = __DIR__ . '/config';
$INC_DIR      = __DIR__ . '/inc';
$CONF_FILE    = $CONFIG_DIR . '/providers.json';
$SECRETS_FILE = $INC_DIR . '/secrets_providers.json';
$MASTER_FILE  = $INC_DIR . '/.provider_master.key';
$RULES_FILE   = __DIR__ . '/rules_by_metier.json';

// Prepare dirs
if (!is_dir($CONFIG_DIR)) @mkdir($CONFIG_DIR, 0775, true);
if (!is_dir($INC_DIR))    @mkdir($INC_DIR,    0775, true);

// ---------- Theme (aligned with dashboard) ----------
$THEME = [
  'bg'        => '#0B0C0E', 'surface'   => '#121416', 'surface2' => '#0f1317',
  'border'    => '#1f242b', 'text'      => '#FFFFFF', 'muted'    => '#9CA3AF',
  'primary'   => '#3B82F6', 'accent'    => '#0A7C66', 'accent2'  => '#064E3B',
  'radius'    => '14px'
];

// ---------- Jobs list ----------
$rulesByMetier = file_exists($RULES_FILE) ? json_decode(file_get_contents($RULES_FILE), true) : [];
$metiers = is_array($rulesByMetier) ? array_keys($rulesByMetier) : [];

// ---------- Crypto helpers ----------
function vx_provider_master_key($MASTER_FILE){
    if (file_exists($MASTER_FILE)) {
        $k = file_get_contents($MASTER_FILE);
        if ($k !== false && strlen($k) === 32) return $k;
    }
    $k = random_bytes(32); // 256-bit key
    @file_put_contents($MASTER_FILE, $k, LOCK_EX);
    @chmod($MASTER_FILE, 0600);
    return $k;
}
function vx_encrypt_secret($plaintext, $MASTER_FILE){
    if ($plaintext === '' || $plaintext === null) return '';
    $key = vx_provider_master_key($MASTER_FILE);
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode(json_encode([
        'iv' => base64_encode($iv),
        'ct' => base64_encode($cipher),
        'tg' => base64_encode($tag),
    ]));
}
function vx_decrypt_secret($blob, $MASTER_FILE){
    if (!$blob) return null;
    $key = vx_provider_master_key($MASTER_FILE);
    $data = json_decode(base64_decode($blob), true);
    if (!is_array($data)) return null;
    $iv  = base64_decode($data['iv'] ?? '');
    $ct  = base64_decode($data['ct'] ?? '');
    $tg  = base64_decode($data['tg'] ?? '');
    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tg);
    return $plain === false ? null : $plain;
}
function vx_mask($k){
    if ($k === '' || $k === null) return '';
    $tail = substr($k, -4);
    return '••••••••' . $tail;
}

// ---------- Secrets store ----------
function vx_load_secrets($SECRETS_FILE){
    if (!file_exists($SECRETS_FILE)) return [];
    $j = json_decode(file_get_contents($SECRETS_FILE), true);
    return is_array($j) ? $j : [];
}
function vx_save_secrets($SECRETS_FILE, array $data){
    @file_put_contents($SECRETS_FILE, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    @chmod($SECRETS_FILE, 0600);
}
function vx_set_secret(&$secrets, $MASTER_FILE, $id, $plain){
    if ($plain === '' || $plain === null) return $id; // keep as-is if empty
    if ($id === '' || $id === null) $id = 'prov_' . bin2hex(random_bytes(6));
    $secrets[$id] = vx_encrypt_secret($plain, $MASTER_FILE);
    return $id;
}
function vx_get_secret($secrets, $MASTER_FILE, $id){
    if ($id === '' || $id === null) return null;
    if (!isset($secrets[$id])) return null;
    return vx_decrypt_secret($secrets[$id], $MASTER_FILE);
}

// ---------- Config load/save ----------
function vx_default_provider_block(){
    return [
        'enabled'=>false,
        'model'=>'',
        'key_ref'=>'',
        'key_mask'=>'',
        // NEW: pricing defaults
        'pricing'=>[
            'currency'=>'EUR',
            'unit_tokens'=>1000000, // 1M default
            'input_price'=>0.0,
            'output_price'=>0.0
        ]
    ];
}
function vx_default_config(){
    return [
      'mode' => 'global', // 'global' | 'per_metier'
      'global' => [
        'active_provider' => 'groq',
        'providers' => [
          'groq'      => ['enabled'=>true,  'model'=>'llama-3.1-8b-instant', 'key_ref'=>'', 'key_mask'=>'',
                          'pricing'=>['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0.0,'output_price'=>0.0]],
          'openai'    => ['enabled'=>false, 'model'=>'gpt-4o',               'key_ref'=>'', 'key_mask'=>'',
                          'pricing'=>['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0.0,'output_price'=>0.0]],
          'anthropic' => ['enabled'=>false, 'model'=>'claude-3-opus',        'key_ref'=>'', 'key_mask'=>'',
                          'pricing'=>['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0.0,'output_price'=>0.0]],
          'gemini'    => ['enabled'=>false, 'model'=>'gemini-1.5-pro',       'key_ref'=>'', 'key_mask'=>'',
                          'pricing'=>['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0.0,'output_price'=>0.0]],
        ]
      ],
      'per_metier' => []
    ];
}
function vx_load_config($CONF_FILE){
    if (!file_exists($CONF_FILE)) return vx_default_config();
    $j = json_decode(file_get_contents($CONF_FILE), true);
    if (!is_array($j)) return vx_default_config();
    if (!isset($j['mode'])) $j['mode']='global';
    if (!isset($j['global'])) $j['global']=vx_default_config()['global'];
    if (!isset($j['per_metier'])) $j['per_metier']=[];
    if (!is_array($j['per_metier'])) $j['per_metier'] = (array)$j['per_metier'];

    // Backfill pricing defaults if missing
    foreach (['groq','openai','anthropic','gemini'] as $pv) {
        if (!isset($j['global']['providers'][$pv])) $j['global']['providers'][$pv] = vx_default_provider_block();
        if (empty($j['global']['providers'][$pv]['pricing'])) {
            $j['global']['providers'][$pv]['pricing'] = ['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0.0,'output_price'=>0.0];
        }
    }
    if (isset($j['per_metier']) && is_array($j['per_metier'])) {
        foreach ($j['per_metier'] as $m => &$cfg) {
            if (!isset($cfg['providers']) || !is_array($cfg['providers'])) $cfg['providers'] = [];
            foreach (['groq','openai','anthropic','gemini'] as $pv) {
                if (!isset($cfg['providers'][$pv])) $cfg['providers'][$pv] = vx_default_provider_block();
                if (empty($cfg['providers'][$pv]['pricing'])) {
                    $cfg['providers'][$pv]['pricing'] = ['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0.0,'output_price'=>0.0];
                }
            }
        }
        unset($cfg);
    }

    return $j;
}
function vx_save_config($CONF_FILE, array $cfg){
    @file_put_contents($CONF_FILE, json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

$config  = vx_load_config($CONF_FILE);
$secrets = vx_load_secrets($SECRETS_FILE);
$notice  = '';

// ---------- POST handling ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'global';
    if (!in_array($mode, ['global','per_metier'], true)) $mode = 'global';
    $config['mode'] = $mode;

    $providers_list = [
        'groq'      => ['models'=>['llama-3.1-8b-instant','llama-3.1-70b-versatile','mixtral-8x7b-32768']],
        'openai'    => ['models'=>['gpt-4o','gpt-4.1-mini','gpt-4-turbo','gpt-3.5-turbo']],
        'anthropic' => ['models'=>['claude-3-opus','claude-3-sonnet','claude-3-haiku']],
        'gemini'    => ['models'=>['gemini-1.5-pro','gemini-1.5-flash','gemini-1.0-pro']]
    ];

    // Helper: read pricing fields from POST
    $read_pricing = function(string $prefix, array $existing){
        $currency = $_POST["{$prefix}_currency"] ?? ($existing['pricing']['currency'] ?? 'EUR');
        $unit     = (int)($_POST["{$prefix}_unit"] ?? ($existing['pricing']['unit_tokens'] ?? 1000000));
        if (!in_array($unit, [1000,100000,1000000], true)) $unit = 1000000;
        $pin      = (float)($_POST["{$prefix}_price_in"]  ?? ($existing['pricing']['input_price']  ?? 0));
        $pout     = (float)($_POST["{$prefix}_price_out"] ?? ($existing['pricing']['output_price'] ?? 0));
        return [
            'currency'     => $currency ?: 'EUR',
            'unit_tokens'  => $unit,
            'input_price'  => max(0, $pin),
            'output_price' => max(0, $pout),
        ];
    };

    if ($mode === 'global') {
        $config['global']['active_provider'] = $_POST['global_active_provider'] ?? 'groq';

        foreach ($providers_list as $prov => $meta) {
            $enabled = isset($_POST["g_{$prov}_enabled"]);
            $model   = $_POST["g_{$prov}_model"] ?? ($meta['models'][0] ?? '');
            $key_in  = trim($_POST["g_{$prov}_key"] ?? '');

            $prev    = $config['global']['providers'][$prov] ?? vx_default_provider_block();

            $ref     = $prev['key_ref'] ?? '';
            $ref     = vx_set_secret($secrets, $MASTER_FILE, $ref, $key_in);
            $mask    = $prev['key_mask'] ?? '';
            if ($key_in !== '') $mask = vx_mask($key_in);

            $pricing = $read_pricing("g_{$prov}", $prev);

            $config['global']['providers'][$prov] = [
                'enabled' => $enabled,
                'model'   => $model,
                'key_ref' => $ref,
                'key_mask'=> $mask,
                'pricing' => $pricing
            ];
        }
        if (!isset($config['per_metier']) || !is_array($config['per_metier'])) $config['per_metier'] = [];

    } else {
        if (!isset($config['per_metier']) || !is_array($config['per_metier'])) $config['per_metier'] = [];
        $pm = [];
        foreach ($metiers as $m) {
            $act = $_POST["pm_{$m}_active"] ?? 'groq';
            $pvals = [];
            foreach ($providers_list as $prov => $meta) {
                $ena   = isset($_POST["pm_{$m}_{$prov}_enabled"]);
                $model = $_POST["pm_{$m}_{$prov}_model"] ?? ($meta['models'][0] ?? '');
                $key   = trim($_POST["pm_{$m}_{$prov}_key"] ?? '');

                $prev  = $config['per_metier'][$m]['providers'][$prov] ?? vx_default_provider_block();

                $oldRef = $prev['key_ref'] ?? '';
                $ref    = vx_set_secret($secrets, $MASTER_FILE, $oldRef, $key);
                $mask   = $prev['key_mask'] ?? '';
                if ($key !== '') $mask = vx_mask($key);

                $pricing = $read_pricing("pm_{$m}_{$prov}", $prev);

                $pvals[$prov] = [
                    'enabled'=>$ena,'model'=>$model,'key_ref'=>$ref,'key_mask'=>$mask,
                    'pricing'=>$pricing
                ];
            }
            $pm[$m] = ['active_provider'=>$act, 'providers'=>$pvals];
        }
        $config['per_metier'] = $pm;
    }

    vx_save_secrets($SECRETS_FILE, $secrets);
    vx_save_config($CONF_FILE, $config);
    $notice = "✅ Configuration saved.";
}

// ---------- UI helpers ----------
function is_checked($b){ return $b ? 'checked' : ''; }
function sel($a,$b){ return ($a===$b)?'selected':''; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>VELIXA — AI & Multimodal</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --vx-bg:<?= $THEME['bg']?>; --vx-surface:<?= $THEME['surface']?>; --vx-surface2:<?= $THEME['surface2']?>;
  --vx-border:<?= $THEME['border']?>; --vx-text:<?= $THEME['text']?>; --vx-muted:<?= $THEME['muted']?>;
  --vx-primary:<?= $THEME['primary']?>; --vx-accent:<?= $THEME['accent']?>; --vx-accent2:<?= $THEME['accent2']?>;
  --vx-radius:<?= $THEME['radius']?>;

  /* Extras to match dashboard buttons */
  --vx-green:#10B981; --vx-green-2:#0EA371;
  --vx-purple:#8B5CF6;
}
*{box-sizing:border-box}
a{color:inherit;text-decoration:none}
a:hover{text-decoration:none;filter:brightness(1.04)}
body{margin:0;background:var(--vx-bg);color:var(--vx-text);font-family:Inter,Roboto,system-ui,-apple-system,Segoe UI,Arial,sans-serif;}

/* Header bar */
.topbar{position:sticky;top:0;z-index:10;display:grid;grid-template-columns:1fr auto 1fr;
  align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--vx-border);
  background:linear-gradient(180deg,#0f1114 0%, #0b0c0e 100%);}
.topbrand{justify-self:center;display:flex;align-items:center;gap:10px}
.vx-logo{height:40px;vertical-align:middle}
.logout{justify-self:end;background:#18231e;color:#e5e7eb;border:1px solid var(--vx-green);padding:8px 12px;border-radius:10px;cursor:pointer;
  transition:transform .12s ease, box-shadow .12s ease, filter .12s ease}
.logout:hover{filter:brightness(1.08);box-shadow:0 0 0 3px rgba(16,185,129,.35),0 8px 24px rgba(0,0,0,.45)}

.wrap{max-width:1100px;margin:28px auto;padding:0 16px;}
.header{display:flex;align-items:center;justify-content:space-between;margin:12px 0;}
.badge{display:inline-block;background:#0f1317;border:1px solid var(--vx-border);padding:6px 10px;border-radius:999px;font-size:12px;color:#cfd6dd}

.actions{display:flex;gap:8px;flex-wrap:wrap}

/* Buttons: green defaults, purple save */
.btn{
  background:linear-gradient(180deg, var(--vx-green), var(--vx-green-2));
  color:#0a1612; border:1px solid var(--vx-green); border-radius:10px;
  padding:10px 14px; display:inline-block; font-weight:800; cursor:pointer;
  box-shadow:0 6px 16px rgba(16,185,129,.28);
  transition:transform .12s ease, box-shadow .12s ease, filter .12s ease;
}
.btn:hover{
  filter:brightness(1.08);
  box-shadow:0 0 0 4px rgba(16,185,129,.45), 0 10px 28px rgba(0,0,0,.50);
  transform:translateY(-1px);
}
.btn-secondary{ background:#0f1b17; color:#d9fff3; border:1px solid rgba(16,185,129,.5); box-shadow:none; }
.btn-secondary:hover{ box-shadow:0 0 0 4px rgba(16,185,129,.35), 0 8px 22px rgba(0,0,0,.45); filter:brightness(1.06); }

.btn-primary{
  background:linear-gradient(180deg, var(--vx-purple), #6D28D9);
  color:#fff; border:1px solid var(--vx-purple);
  box-shadow:0 6px 16px rgba(139,92,246,.35);
}
.btn-primary:hover{
  box-shadow:0 0 0 4px rgba(139,92,246,.45), 0 10px 28px rgba(0,0,0,.55);
  filter:brightness(1.06); transform:translateY(-1px);
}

.panel{background:var(--vx-surface);border:1px solid var(--vx-border);border-radius:var(--vx-radius);padding:18px;margin:12px 0;}
.note{color:var(--vx-muted);font-size:13px}
.kv{display:grid;gap:12px;grid-template-columns:1fr 1fr}
@media(max-width:900px){ .kv{grid-template-columns:1fr} }
.card{background:var(--vx-surface2);border:1px solid var(--vx-border);border-radius:12px;padding:14px;margin-top:10px}
.card h4{margin:0 0 8px 0}
.row{display:grid;gap:10px;grid-template-columns:1fr 1fr 1fr}
@media(max-width:900px){ .row{grid-template-columns:1fr} }

label{display:block;font-size:14px;margin:6px 0 2px}
input[type="text"], input[type="password"], select, input[type="number"]{
  width:100%;background:var(--vx-surface2);color:#e5e7eb;border:1px solid #222933;border-radius:10px;padding:10px 12px;
  outline:none; transition:box-shadow .15s,border-color .15s, filter .12s ease;
}
input[type="text"]:focus, input[type="password"]:focus, select:focus, input[type="number"]:focus{
  border-color:var(--vx-green); box-shadow:0 0 0 3px rgba(16,185,129,.30);
}

.check{display:flex;align-items:center;gap:8px;margin:8px 0;color:#e5e7eb}
.sep{height:1px;background:var(--vx-border);margin:14px 0}
.notice{margin:12px 0;padding:10px 12px;border-radius:10px;background:#102312;border:1px solid #194a1d;color:#d9fbd0}
.collapser{cursor:pointer;display:flex;align-items:center;justify-content:space-between;background:#161b22;border:1px solid var(--vx-border);border-radius:10px;padding:10px 12px;margin:10px 0}
.collapser:hover{background:#1a2130}
.hidden{display:none}
.keymask{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;color:#cfe5ff}

/* Pricing subgrid */
.price-grid{display:grid;grid-template-columns: 110px 1fr 1fr 1fr; gap:8px; align-items:end; margin-top:8px;}
@media(max-width:900px){ .price-grid{grid-template-columns:1fr 1fr; } .price-grid > div{min-width:0;} }

/* Footer */
.footer{margin:24px 0;display:flex;justify-content:center}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
  <div></div>
  <div class="topbrand">
    <?php if (file_exists(__DIR__.'/assets/velixa-logo.png')): ?>
      <img src="assets/velixa-logo.png" class="vx-logo" alt="Velixa">
    <?php else: ?>
      <strong>VELIXA</strong>
    <?php endif; ?>
    <span class="badge">AI & Multimodal</span>
  </div>
  <a href="logout.php"><button class="logout">🚪 Log out</button></a>
</div>

<div class="wrap">
  <div class="header">
    <div class="actions">
      <a href="dashboard.php#providers" class="btn-secondary btn">⬅ Back</a>
    </div>
  </div>

  <?php if ($notice): ?>
    <div class="notice"><?= h($notice) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
        <?= vx_csrf_field() ?>
    <div class="panel">
      <h3 style="margin:0 0 8px">Application mode</h3>
      <div class="kv">
        <label class="check"><input type="radio" name="mode" value="global"   <?= $config['mode']==='global'?'checked':''; ?>> Set for entire organization</label>
        <label class="check"><input type="radio" name="mode" value="per_metier" <?= $config['mode']==='per_metier'?'checked':''; ?>> Set per job</label>
      </div>
      <div class="note">Groq is active by default if no other config is set.</div>
    </div>

    <!-- GLOBAL -->
    <div id="vx-global" class="panel <?= $config['mode']==='global'?'':'hidden' ?>">
      <h3 style="margin:0 0 8px">Global configuration</h3>
      <?php $g = $config['global']; ?>
      <label>Active provider:</label>
      <select name="global_active_provider" style="max-width:360px">
        <option value="groq"      <?= sel($g['active_provider'],'groq') ?>>Groq</option>
        <option value="openai"    <?= sel($g['active_provider'],'openai') ?>>OpenAI</option>
        <option value="anthropic" <?= sel($g['active_provider'],'anthropic') ?>>Anthropic</option>
        <option value="gemini"    <?= sel($g['active_provider'],'gemini') ?>>Google Gemini</option>
      </select>

      <div class="row">
        <!-- GROQ -->
        <?php $p = $g['providers']['groq']; $pr=$p['pricing']??['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0,'output_price'=>0]; ?>
        <div class="card">
          <h4>Groq</h4>
          <label class="check">
            <input type="checkbox" name="g_groq_enabled" <?= is_checked($p['enabled']) ?>> Activate Groq
          </label>
          <label>Model</label>
          <select name="g_groq_model">
            <?php foreach (['llama-3.1-8b-instant','llama-3.1-70b-versatile','mixtral-8x7b-32768'] as $m): ?>
              <option value="<?= h($m) ?>" <?= sel($p['model'],$m) ?>><?= h($m) ?></option>
            <?php endforeach; ?>
          </select>
          <label>API key (leave empty to keep current)</label>
          <input type="password" name="g_groq_key" placeholder="<?= $p['key_mask']? h($p['key_mask']) : '••••••••' ?>" autocomplete="new-password">
          <?php if (!empty($p['key_mask'])): ?><div class="note">Registered key: <span class="keymask"><?= h($p['key_mask']) ?></span></div><?php endif; ?>

          <div class="sep"></div>
          <div class="note">Pricing (optional) — dashboard uses these values to show indicative costs.</div>
          <div class="price-grid">
            <div>
              <label>Currency</label>
              <select name="g_groq_currency">
                <?php foreach (['EUR','USD','GBP','CHF'] as $cur): ?>
                  <option value="<?= $cur ?>" <?= sel($pr['currency'],$cur) ?>><?= $cur ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Unit (tokens)</label>
              <select name="g_groq_unit">
                <option value="1000"    <?= sel((int)$pr['unit_tokens'],1000)    ?>>1K</option>
                <option value="100000"  <?= sel((int)$pr['unit_tokens'],100000)  ?>>100K</option>
                <option value="1000000" <?= sel((int)$pr['unit_tokens'],1000000) ?>>1M</option>
              </select>
            </div>
            <div>
              <label>Input price / unit</label>
              <input type="number" step="0.0001" min="0" name="g_groq_price_in" value="<?= h((float)$pr['input_price']) ?>">
            </div>
            <div>
              <label>Output price / unit</label>
              <input type="number" step="0.0001" min="0" name="g_groq_price_out" value="<?= h((float)$pr['output_price']) ?>">
            </div>
          </div>
        </div>

        <!-- OpenAI -->
        <?php $p = $g['providers']['openai']; $pr=$p['pricing']??['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0,'output_price'=>0]; ?>
        <div class="card">
          <h4>OpenAI</h4>
          <label class="check">
            <input type="checkbox" name="g_openai_enabled" <?= is_checked($p['enabled']) ?>> Activate OpenAI
          </label>
          <label>Model</label>
          <select name="g_openai_model">
            <?php foreach (['gpt-4o','gpt-4.1-mini','gpt-4-turbo','gpt-3.5-turbo'] as $m): ?>
              <option value="<?= h($m) ?>" <?= sel($p['model'],$m) ?>><?= h($m) ?></option>
            <?php endforeach; ?>
          </select>
          <label>API key (leave empty to keep current)</label>
          <input type="password" name="g_openai_key" placeholder="<?= $p['key_mask']? h($p['key_mask']) : '••••••••' ?>" autocomplete="new-password">
          <?php if (!empty($p['key_mask'])): ?><div class="note">Registered key: <span class="keymask"><?= h($p['key_mask']) ?></span></div><?php endif; ?>

          <div class="sep"></div>
          <div class="price-grid">
            <div>
              <label>Currency</label>
              <select name="g_openai_currency">
                <?php foreach (['EUR','USD','GBP','CHF'] as $cur): ?>
                  <option value="<?= $cur ?>" <?= sel($pr['currency'],$cur) ?>><?= $cur ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Unit (tokens)</label>
              <select name="g_openai_unit">
                <option value="1000"    <?= sel((int)$pr['unit_tokens'],1000)    ?>>1K</option>
                <option value="100000"  <?= sel((int)$pr['unit_tokens'],100000)  ?>>100K</option>
                <option value="1000000" <?= sel((int)$pr['unit_tokens'],1000000) ?>>1M</option>
              </select>
            </div>
            <div>
              <label>Input price / unit</label>
              <input type="number" step="0.0001" min="0" name="g_openai_price_in" value="<?= h((float)$pr['input_price']) ?>">
            </div>
            <div>
              <label>Output price / unit</label>
              <input type="number" step="0.0001" min="0" name="g_openai_price_out" value="<?= h((float)$pr['output_price']) ?>">
            </div>
          </div>
        </div>

        <!-- Anthropic -->
        <?php $p = $g['providers']['anthropic']; $pr=$p['pricing']??['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0,'output_price'=>0]; ?>
        <div class="card">
          <h4>Anthropic</h4>
          <label class="check">
            <input type="checkbox" name="g_anthropic_enabled" <?= is_checked($p['enabled']) ?>> Activate Anthropic
          </label>
          <label>Model</label>
          <select name="g_anthropic_model">
            <?php foreach (['claude-3-opus','claude-3-sonnet','claude-3-haiku'] as $m): ?>
              <option value="<?= h($m) ?>" <?= sel($p['model'],$m) ?>><?= h($m) ?></option>
            <?php endforeach; ?>
          </select>
          <label>API key (leave empty to keep current)</label>
          <input type="password" name="g_anthropic_key" placeholder="<?= $p['key_mask']? h($p['key_mask']) : '••••••••' ?>" autocomplete="new-password">
          <?php if (!empty($p['key_mask'])): ?><div class="note">Registered key: <span class="keymask"><?= h($p['key_mask']) ?></span></div><?php endif; ?>

          <div class="sep"></div>
          <div class="price-grid">
            <div>
              <label>Currency</label>
              <select name="g_anthropic_currency">
                <?php foreach (['EUR','USD','GBP','CHF'] as $cur): ?>
                  <option value="<?= $cur ?>" <?= sel($pr['currency'],$cur) ?>><?= $cur ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Unit (tokens)</label>
              <select name="g_anthropic_unit">
                <option value="1000"    <?= sel((int)$pr['unit_tokens'],1000)    ?>>1K</option>
                <option value="100000"  <?= sel((int)$pr['unit_tokens'],100000)  ?>>100K</option>
                <option value="1000000" <?= sel((int)$pr['unit_tokens'],1000000) ?>>1M</option>
              </select>
            </div>
            <div>
              <label>Input price / unit</label>
              <input type="number" step="0.0001" min="0" name="g_anthropic_price_in" value="<?= h((float)$pr['input_price']) ?>">
            </div>
            <div>
              <label>Output price / unit</label>
              <input type="number" step="0.0001" min="0" name="g_anthropic_price_out" value="<?= h((float)$pr['output_price']) ?>">
            </div>
          </div>
        </div>

        <!-- Gemini -->
        <?php $p = $g['providers']['gemini']; $pr=$p['pricing']??['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0,'output_price'=>0]; ?>
        <div class="card">
          <h4>Google Gemini</h4>
          <label class="check">
            <input type="checkbox" name="g_gemini_enabled" <?= is_checked($p['enabled']) ?>> Activate Gemini
          </label>
          <label>Model</label>
          <select name="g_gemini_model">
            <?php foreach (['gemini-1.5-pro','gemini-1.5-flash','gemini-1.0-pro'] as $m): ?>
              <option value="<?= h($m) ?>" <?= sel($p['model'],$m) ?>><?= h($m) ?></option>
            <?php endforeach; ?>
          </select>
          <label>API key (leave empty to keep current)</label>
          <input type="password" name="g_gemini_key" placeholder="<?= $p['key_mask']? h($p['key_mask']) : '••••••••' ?>" autocomplete="new-password">
          <?php if (!empty($p['key_mask'])): ?><div class="note">Registered key: <span class="keymask"><?= h($p['key_mask']) ?></span></div><?php endif; ?>

          <div class="sep"></div>
          <div class="price-grid">
            <div>
              <label>Currency</label>
              <select name="g_gemini_currency">
                <?php foreach (['EUR','USD','GBP','CHF'] as $cur): ?>
                  <option value="<?= $cur ?>" <?= sel($pr['currency'],$cur) ?>><?= $cur ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Unit (tokens)</label>
              <select name="g_gemini_unit">
                <option value="1000"    <?= sel((int)$pr['unit_tokens'],1000)    ?>>1K</option>
                <option value="100000"  <?= sel((int)$pr['unit_tokens'],100000)  ?>>100K</option>
                <option value="1000000" <?= sel((int)$pr['unit_tokens'],1000000) ?>>1M</option>
              </select>
            </div>
            <div>
              <label>Input price / unit</label>
              <input type="number" step="0.0001" min="0" name="g_gemini_price_in" value="<?= h((float)$pr['input_price']) ?>">
            </div>
            <div>
              <label>Output price / unit</label>
              <input type="number" step="0.0001" min="0" name="g_gemini_price_out" value="<?= h((float)$pr['output_price']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- PER JOB -->
    <div id="vx-permetier" class="panel <?= $config['mode']==='per_metier'?'':'hidden' ?>">
      <h3 style="margin:0 0 8px">Configuration by jobs</h3>
      <?php if (empty($metiers)): ?>
        <div class="note">No job configured yet. Go to the dashboard first.</div>
      <?php else: ?>
        <?php
          if (!isset($config['per_metier']) || !is_array($config['per_metier'])) $config['per_metier'] = [];
          foreach ($metiers as $m) {
            if (!isset($config['per_metier'][$m]) || !is_array($config['per_metier'][$m])) {
              $config['per_metier'][$m] = [
                'active_provider'=>'groq',
                'providers'=>[
                  'groq'      => ['enabled'=>true,  'model'=>'llama-3.1-8b-instant', 'key_ref'=>'', 'key_mask'=>'',
                                  'pricing'=>['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0,'output_price'=>0]],
                  'openai'    => ['enabled'=>false, 'model'=>'gpt-4o',               'key_ref'=>'', 'key_mask'=>'',
                                  'pricing'=>['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0,'output_price'=>0]],
                  'anthropic' => ['enabled'=>false, 'model'=>'claude-3-opus',        'key_ref'=>'', 'key_mask'=>'',
                                  'pricing'=>['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0,'output_price'=>0]],
                  'gemini'    => ['enabled'=>false, 'model'=>'gemini-1.5-pro',       'key_ref'=>'', 'key_mask'=>'',
                                  'pricing'=>['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0,'output_price'=>0]],
                ]
              ];
            }
          }
        ?>
        <?php foreach ($metiers as $m): $cfg = $config['per_metier'][$m]; ?>
          <div class="collapser" data-target="m-<?= h($m) ?>">
            <strong><?= h($m) ?></strong>
            <span>▾</span>
          </div>
          <div id="m-<?= h($m) ?>" class="card hidden">
            <label>Active provider:</label>
            <select name="pm_<?= h($m) ?>_active" style="max-width:360px">
              <option value="groq"      <?= sel($cfg['active_provider'],'groq') ?>>Groq</option>
              <option value="openai"    <?= sel($cfg['active_provider'],'openai') ?>>OpenAI</option>
              <option value="anthropic" <?= sel($cfg['active_provider'],'anthropic') ?>>Anthropic</option>
              <option value="gemini"    <?= sel($cfg['active_provider'],'gemini') ?>>Google Gemini</option>
            </select>

            <div class="row" style="margin-top:10px">
              <?php
                $prov_def = [
                  'groq'      => ['models'=>['llama-3.1-8b-instant','llama-3.1-70b-versatile','mixtral-8x7b-32768']],
                  'openai'    => ['models'=>['gpt-4o','gpt-4.1-mini','gpt-4-turbo','gpt-3.5-turbo']],
                  'anthropic' => ['models'=>['claude-3-opus','claude-3-sonnet','claude-3-haiku']],
                  'gemini'    => ['models'=>['gemini-1.5-pro','gemini-1.5-flash','gemini-1.0-pro']],
                ];
                foreach (['groq','openai','anthropic','gemini'] as $prov):
                  $p  = $cfg['providers'][$prov];
                  $pr = $p['pricing'] ?? ['currency'=>'EUR','unit_tokens'=>1000000,'input_price'=>0,'output_price'=>0];
              ?>
                <div class="card">
                  <h4><?= ucfirst($prov) ?></h4>
                  <label class="check">
                    <input type="checkbox" name="pm_<?= h($m) ?>_<?= $prov ?>_enabled" <?= is_checked($p['enabled']) ?>> Activate <?= ucfirst($prov) ?>
                  </label>
                  <label>Model</label>
                  <select name="pm_<?= h($m) ?>_<?= $prov ?>_model">
                    <?php foreach ($prov_def[$prov]['models'] as $mm): ?>
                      <option value="<?= h($mm) ?>" <?= sel($p['model'],$mm) ?>><?= h($mm) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label>API key (leave empty to keep current)</label>
                  <input type="password" name="pm_<?= h($m) ?>_<?= $prov ?>_key" placeholder="<?= $p['key_mask']? h($p['key_mask']) : '••••••••' ?>" autocomplete="new-password">
                  <?php if (!empty($p['key_mask'])): ?><div class="note">Registered key: <span class="keymask"><?= h($p['key_mask']) ?></span></div><?php endif; ?>

                  <div class="sep"></div>
                  <div class="price-grid">
                    <div>
                      <label>Currency</label>
                      <select name="pm_<?= h($m) ?>_<?= $prov ?>_currency">
                        <?php foreach (['EUR','USD','GBP','CHF'] as $cur): ?>
                          <option value="<?= $cur ?>" <?= sel($pr['currency'],$cur) ?>><?= $cur ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label>Unit (tokens)</label>
                      <select name="pm_<?= h($m) ?>_<?= $prov ?>_unit">
                        <option value="1000"    <?= sel((int)$pr['unit_tokens'],1000)    ?>>1K</option>
                        <option value="100000"  <?= sel((int)$pr['unit_tokens'],100000)  ?>>100K</option>
                        <option value="1000000" <?= sel((int)$pr['unit_tokens'],1000000) ?>>1M</option>
                      </select>
                    </div>
                    <div>
                      <label>Input price / unit</label>
                      <input type="number" step="0.0001" min="0" name="pm_<?= h($m) ?>_<?= $prov ?>_price_in" value="<?= h((float)$pr['input_price']) ?>">
                    </div>
                    <div>
                      <label>Output price / unit</label>
                      <input type="number" step="0.0001" min="0" name="pm_<?= h($m) ?>_<?= $prov ?>_price_out" value="<?= h((float)$pr['output_price']) ?>">
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="panel" style="display:flex;align-items:center;gap:12px;justify-content:space-between">
      <div>
        <button type="submit" class="btn btn-primary">💾 Save</button>
        <span class="note">Keys are encrypted at rest and hidden on screen.</span>
      </div>
      <div class="actions">
        <a href="dashboard.php#providers" class="btn-secondary btn">Back to dashboard</a>
      </div>
    </div>
  </form>

  <!-- Bottom logout -->
  <div class="footer">
    <a href="logout.php"><button class="logout">🚪 Log out</button></a>
  </div>
</div>

<script>
// Toggle view by mode
const modeRadios = document.querySelectorAll('input[name="mode"]');
const g = document.getElementById('vx-global');
const m = document.getElementById('vx-permetier');
modeRadios.forEach(r=>{
  r.addEventListener('change',()=>{
    if (r.value === 'global'){ g.classList.remove('hidden'); m.classList.add('hidden'); }
    else { m.classList.remove('hidden'); g.classList.add('hidden'); }
  });
});

// Collapsers per job (closed by default)
document.querySelectorAll('.collapser').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.getAttribute('data-target');
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('hidden');
    const caret = btn.querySelector('span');
    if (caret) caret.textContent = el.classList.contains('hidden') ? '▾' : '▴';
  });
});
</script>
</body>
</html>
