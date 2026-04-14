<?php 
/* =========================================================
   VELIXA — Runtime providers (Groq / OpenAI / Anthropic / Gemini)
   - lit config/providers.json + config/secrets_providers.json
   - résout le provider actif (global ou par métier)
   - expose des helpers d’appel API (PoC simple, textes)
   ========================================================= */

declare(strict_types=1);

/* ---------- chemins robustes ---------- */
$__VX_ROOT_DIR    = dirname(__DIR__);               // racine projet (htdocs/resix)
$__VX_CONFIG_DIR  = $__VX_ROOT_DIR . '/config';

$__VX_CONF_FILE    = $__VX_CONFIG_DIR . '/providers.json';
$__VX_SECRETS_FILE = $__VX_CONFIG_DIR . '/secrets_providers.json';
$__VX_MASTER_FILE  = $__VX_CONFIG_DIR . '/.provider_master.key';

/* ---------- utils ---------- */
function vxrt_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function vxrt_file_json_load(string $path): array {
  if (!is_file($path)) return [];
  $j = json_decode(@file_get_contents($path), true);
  return is_array($j) ? $j : [];
}

function vxrt_master_key(string $file): string {
  $k = @file_get_contents($file);
  if ($k !== false && strlen($k) === 32) return $k;
  $k = random_bytes(32);
  @file_put_contents($file, $k, LOCK_EX);
  @chmod($file, 0600);
  return $k;
}

/** Déchiffre un secret (format json base64 {iv,ct,tg}) */
function vxrt_decrypt(string $blob, string $masterFile): ?string {
  $decoded = base64_decode((string)$blob, true);
  if ($decoded === false) return null;
  $data = json_decode($decoded, true);
  if (!is_array($data)) return null;

  $iv = base64_decode($data['iv'] ?? '', true);
  $ct = base64_decode($data['ct'] ?? '', true);
  $tg = base64_decode($data['tg'] ?? '', true);
  if ($iv === false || $ct === false || $tg === false) return null;

  $key = vxrt_master_key($masterFile);
  $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tg);
  return $plain === false ? null : $plain;
}

/** Récupère une API key:
 * - si ref commence par 'ENV:' => lit variable d'env
 * - sinon cherche dans secrets_providers.json (chiffré), puis déchiffre
 */
function vxrt_resolve_api_key(?string $ref, array $secrets, string $masterFile, ?string $envFallback = null): ?string {
  $ref = (string)$ref;
  if ($ref !== '') {
    if (stripos($ref, 'ENV:') === 0) {
      $env = substr($ref, 4);
      $val = getenv($env);
      if ($val && $val !== '') return $val;
    }
    if (isset($secrets[$ref])) {
      $dec = vxrt_decrypt($secrets[$ref], $masterFile);
      if ($dec !== null && $dec !== '') return $dec;
    }
  }
  // fallback éventuel par variable d’env standard (ex: GROQ_API_KEY)
  if ($envFallback) {
    $v = getenv($envFallback);
    if ($v && $v !== '') return $v;
  }
  return null;
}

/* ---------- lecture config + secrets ---------- */
function vx_provider_runtime_config(): array {
  global $__VX_CONF_FILE;
  $cfg = vxrt_file_json_load($__VX_CONF_FILE);

  if (!isset($cfg['mode'])) $cfg['mode'] = 'global';
  if (!isset($cfg['global'])) {
    $cfg['global'] = [
      'active_provider'=>'groq',
      'providers'=>[
        'groq'      => ['enabled'=>true,  'model'=>'llama-3.1-8b-instant', 'key_ref'=>'', 'key_mask'=>''],
        'openai'    => ['enabled'=>false, 'model'=>'gpt-4o',               'key_ref'=>'', 'key_mask'=>''],
        'anthropic' => ['enabled'=>false, 'model'=>'claude-3-opus',        'key_ref'=>'', 'key_mask'=>''],
        'gemini'    => ['enabled'=>false, 'model'=>'gemini-1.5-pro',       'key_ref'=>'', 'key_mask'=>''],
      ]
    ];
  }
  if (!isset($cfg['per_metier'])) $cfg['per_metier'] = [];

  return $cfg;
}

function vx_provider_runtime_secrets(): array {
  global $__VX_SECRETS_FILE;
  return vxrt_file_json_load($__VX_SECRETS_FILE);
}

/**
 * Résout la configuration applicable à un métier
 * Retourne:
 * [
 *   'mode' => 'global'|'per_metier',
 *   'active' => 'groq'|...,
 *   'providers' => [
 *      prov => ['enabled'=>bool,'model'=>string,'api_key'=>string|null]
 *   ]
 * ]
 */
function vx_provider_runtime_for(string $metier): array {
  global $__VX_MASTER_FILE;

  $cfg = vx_provider_runtime_config();
  $se  = vx_provider_runtime_secrets();

  // Choix du set à utiliser
  if (($cfg['mode'] ?? 'global') === 'per_metier' && isset($cfg['per_metier'][$metier])) {
    $resolveSet = $cfg['per_metier'][$metier];
  } else {
    $resolveSet = $cfg['global'];
  }
  $active = $resolveSet['active_provider'] ?? 'groq';

  // Construit la table des providers avec API key résolue
  $out = ['mode'=>$cfg['mode'] ?? 'global', 'active'=>$active, 'providers'=>[]];

  $known = ['groq','openai','anthropic','gemini'];
  foreach ($known as $prov){
    $p = $resolveSet['providers'][$prov] ?? ['enabled'=>($prov==='groq'),'model'=>'','key_ref'=>''];
    $keyRef = (string)($p['key_ref'] ?? '');

    // Fallbacks d'env standard par provider
    $envFallback = null;
    if ($prov === 'groq')      $envFallback = 'GROQ_API_KEY';
    if ($prov === 'openai')    $envFallback = 'OPENAI_API_KEY';
    if ($prov === 'anthropic') $envFallback = 'ANTHROPIC_API_KEY';
    if ($prov === 'gemini')    $envFallback = 'GEMINI_API_KEY';

    $apiKey = vxrt_resolve_api_key($keyRef, $se, $__VX_MASTER_FILE, $envFallback);

    $out['providers'][$prov] = [
      'enabled' => (bool)($p['enabled'] ?? false),
      'model'   => (string)($p['model'] ?? ''),
      'api_key' => $apiKey,
    ];
  }
  return $out;
}

/* ---------- appels API (PoC minimal, chat texte) ---------- */

function vx_call_groq(string $apiKey, string $model, array $messages, int $maxTokens=512, float $temp=0.2): ?string {
  if (!$apiKey) return null;
  $url = 'https://api.groq.com/openai/v1/chat/completions';
  $payload = [
    "model"=>$model ?: "llama-3.1-8b-instant",
    "messages"=>$messages,
    "temperature"=>$temp,
    "max_tokens"=>$maxTokens
  ];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>[
      "Content-Type: application/json",
      "Authorization: Bearer ".$apiKey
    ],
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT=>40,
    CURLOPT_PROXY=>'',
    CURLOPT_NOPROXY=>''
  ]);
  $raw = curl_exec($ch);
  curl_close($ch);
  $data = json_decode((string)$raw, true);
  return $data['choices'][0]['message']['content'] ?? null;
}

function vx_call_openai(string $apiKey, string $model, array $messages, int $maxTokens=512, float $temp=0.2): ?string {
  if (!$apiKey) return null;
  $url = 'https://api.openai.com/v1/chat/completions';
  $payload = [
    "model"=>$model ?: "gpt-4o",
    "messages"=>$messages,
    "temperature"=>$temp,
    "max_tokens"=>$maxTokens
  ];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>[
      "Content-Type: application/json",
      "Authorization: Bearer ".$apiKey
    ],
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT=>40,
    CURLOPT_PROXY=>'',
    CURLOPT_NOPROXY=>''
  ]);
  $raw = curl_exec($ch);
  curl_close($ch);
  $data = json_decode((string)$raw, true);
  return $data['choices'][0]['message']['content'] ?? null;
}

function vx_call_anthropic(string $apiKey, string $model, string $prompt, int $maxTokens=512, float $temp=0.2): ?string {
  if (!$apiKey) return null;
  $url = 'https://api.anthropic.com/v1/messages';
  $payload = [
    "model"=>$model ?: "claude-3-opus",
    "max_tokens"=>$maxTokens,
    "temperature"=>$temp,
    "messages"=>[["role"=>"user","content"=>$prompt]]
  ];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>[
      "Content-Type: application/json",
      "x-api-key: ".$apiKey,
      "anthropic-version: 2023-06-01"
    ],
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT=>40,
    CURLOPT_PROXY=>'',
    CURLOPT_NOPROXY=>''
  ]);
  $raw = curl_exec($ch);
  curl_close($ch);
  $data = json_decode((string)$raw, true);
  return $data['content'][0]['text'] ?? null;
}

function vx_call_gemini(string $apiKey, string $model, string $prompt, int $maxTokens=512, float $temp=0.2): ?string {
  if (!$apiKey) return null;
  $m = $model ?: 'gemini-1.5-pro';
  $url = "https://generativelanguage.googleapis.com/v1beta/models/{$m}:generateContent?key=".$apiKey;
  $payload = [
    "generationConfig"=>["temperature"=>$temp, "maxOutputTokens"=>$maxTokens],
    "contents"=>[["parts"=>[["text"=>$prompt]]]]
  ];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>["Content-Type: application/json"],
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT=>40,
    CURLOPT_PROXY=>'',
    CURLOPT_NOPROXY=>''
  ]);
  $raw = curl_exec($ch);
  curl_close($ch);
  $data = json_decode((string)$raw, true);
  return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
}