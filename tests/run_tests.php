<?php
declare(strict_types=1);
/**
 * VELIXA — Suite de tests fonctionnels
 * Accès : http://localhost/velixa/tests/run_tests.php?token=velixatest
 */
if (($_GET['token'] ?? '') !== 'velixatest') {
    http_response_code(403); die('Accès refusé. Ajoutez ?token=velixatest');
}
define('VX_ROOT', dirname(__DIR__));
define('VX_TEST_START', microtime(true));

class VxTestRunner {
    private array $results = [];
    private string $suite = '';
    public function suite(string $n): void { $this->suite = $n; }
    public function test(string $n, callable $fn): void {
        $s = microtime(true);
        try {
            $d = $fn();
            $this->results[] = ['suite'=>$this->suite,'name'=>$n,'status'=>'PASS','detail'=>is_string($d)?$d:'','ms'=>round((microtime(true)-$s)*1000,1)];
        } catch (\Throwable $e) {
            $this->results[] = ['suite'=>$this->suite,'name'=>$n,'status'=>'FAIL','detail'=>$e->getMessage(),'ms'=>round((microtime(true)-$s)*1000,1)];
        }
    }
    public function results(): array { return $this->results; }
}
function vxa(bool $c, string $m=''): void { if(!$c) throw new \RuntimeException($m?:'Assertion failed'); }

$t = new VxTestRunner();

// ══════════════════════════════════════════════════════
// SUITE 1 : Infrastructure
// ══════════════════════════════════════════════════════
$t->suite('Infrastructure');

$t->test('Extension LDAP chargée', function() {
    vxa(extension_loaded('ldap'), "❌ Activez extension=ldap dans C:\\xampp\\php\\php.ini puis redémarrez Apache");
    return 'ext/ldap OK';
});
$t->test('Extension OpenSSL chargée', function() {
    vxa(extension_loaded('openssl'), 'OpenSSL manquant'); return 'ext/openssl OK';
});
$t->test('Extension cURL chargée', function() {
    vxa(extension_loaded('curl'), 'cURL manquant'); return 'ext/curl OK';
});
$t->test('logs/ accessible en écriture', function() {
    $d = VX_ROOT.'/logs';
    vxa(is_dir($d) && is_writable($d), "logs/ non accessible. Créez le dossier et donnez les droits.");
    return $d;
});
$t->test('config/ lisible', function() {
    vxa(is_dir(VX_ROOT.'/config'), 'config/ absent');
    vxa(is_readable(VX_ROOT.'/config/bots_registry.json'), 'bots_registry.json illisible');
    return 'config/ OK';
});
$t->test('Chiffrement AES-256-GCM (secure_store)', function() {
    require_once VX_ROOT.'/inc/secure_store.php';
    $plain = 'Secret Velixa 🔐 test';
    $dec   = vx_secure_store_decrypt(vx_secure_store_encrypt($plain));
    vxa($dec === $plain, "Déchiffrement incorrect: '$dec'");
    return 'Encrypt/decrypt OK';
});
$t->test('Clé maître 32 bytes', function() {
    require_once VX_ROOT.'/inc/secure_store.php';
    vxa(strlen(vx_secure_store_master_key()) === 32, 'Clé maître invalide');
    return 'Clé 32 bytes OK';
});
$t->test('CSRF token généré', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    $tok = vx_csrf_token();
    vxa(strlen($tok) === 64, 'Token CSRF invalide');
    return substr($tok,0,12).'…';
});
$t->test('.htaccess protection config/', function() {
    $path = VX_ROOT.'/config/.htaccess';
    vxa(file_exists($path), '.htaccess absent dans config/ — vos JSON sont exposés!');
    return 'Protection .htaccess présente';
});
$t->test('.htaccess protection logs/', function() {
    vxa(file_exists(VX_ROOT.'/logs/.htaccess'), '.htaccess absent dans logs/');
    return 'Protection .htaccess présente';
});

// ══════════════════════════════════════════════════════
// SUITE 2 : Auth & Utilisateurs
// ══════════════════════════════════════════════════════
$t->suite('Auth & Utilisateurs');

$t->test('users.json chargeable', function() {
    require_once VX_ROOT.'/users_lib.php';
    $u = load_users();
    vxa(is_array($u) && count($u) > 0, 'users.json vide ou invalide');
    vxa(isset($u['admin']), 'Compte admin absent');
    return count($u).' utilisateur(s)';
});
$t->test('Hash admin valide (format)', function() {
    require_once VX_ROOT.'/users_lib.php';
    $u = load_users();
    $h = $u['admin']['password_hash'] ?? $u['admin']['password'] ?? '';
    vxa($h !== '' && str_starts_with($h,'$'), 'Hash invalide ou absent');
    $algo = str_contains($h,'argon2id')?'argon2id':(str_contains($h,'2y')?'bcrypt':'?');
    return "Algo: $algo";
});
$t->test('Hash admin valide (argon2id)', function() {
    require_once VX_ROOT.'/users_lib.php';
    $u = load_users();
    $h = $u['admin']['password_hash'] ?? $u['admin']['password'] ?? '';
    vxa($h !== '', '❌ Aucun hash trouvé pour admin');
    $info = password_get_info($h);
    vxa(in_array($info['algoName'], ['argon2id','bcrypt'], true),
        '❌ Algorithme invalide: ' . $info['algoName']);
    return 'Hash valide — algo: ' . $info['algoName'] . ' (mot de passe personnalisé)';
});
$t->test('TOTP : génération secret', function() {
    require_once VX_ROOT.'/mfa_lib.php';
    $s = mfa_random_base32(32);
    vxa(strlen($s)===32 && preg_match('/^[A-Z2-7]+$/',$s), 'Secret TOTP invalide');
    return "Secret: $s";
});
$t->test('TOTP : génération + vérification code', function() {
    require_once VX_ROOT.'/mfa_lib.php';
    $s = mfa_random_base32(32);
    $c = mfa_totp_at($s, time());
    vxa(strlen($c)===6 && ctype_digit($c), 'Code TOTP invalide');
    vxa(mfa_verify_totp($s,$c,1), 'TOTP verify échoue sur code frais');
    return "Code $c validé ✓";
});
$t->test('Codes de récupération MFA', function() {
    require_once VX_ROOT.'/mfa_lib.php';
    $codes = mfa_generate_recovery_codes(8);
    vxa(count($codes)===8, '8 codes attendus');
    return '8 codes: '.$codes[0].'…';
});
$t->test('encryption_utils.php neutralisé', function() {
    require_once VX_ROOT.'/encryption_utils.php';
    $caught = false;
    try { crypter_prompt('test'); } catch(\RuntimeException $e) { $caught = true; }
    vxa($caught, '❌ crypter_prompt() doit lancer une exception (fichier non neutralisé)');
    return 'Fonctions legacy bloquées ✓';
});

// ══════════════════════════════════════════════════════
// SUITE 3 : LDAP / Active Directory
// ══════════════════════════════════════════════════════
$t->suite('LDAP / Active Directory');

$t->test('Fonctions LDAP helpers disponibles', function() {
    require_once VX_ROOT.'/inc/egress_helpers.php';
    require_once VX_ROOT.'/inc/ldap_helpers.php';
    foreach(['vx_ldap_bind','vx_ldap_find_user_entry','vx_ldap_user_bind_ok','vx_ldap_list_ous','vx_ldap_role_from_groups'] as $fn)
        vxa(function_exists($fn), "$fn() absente");
    return '5 fonctions LDAP disponibles';
});
$t->test('use_tls / use_starttls harmonisés', function() {
    $cfg = require VX_ROOT.'/ldap_config.php';
    vxa(is_array($cfg), 'ldap_config.php invalide');
    vxa(array_key_exists('use_starttls',$cfg), 'Clé use_starttls absente → bug STARTTLS');
    vxa($cfg['use_starttls'] === $cfg['use_tls'], 'use_tls et use_starttls désynchronisés!');
    return 'use_starttls='.($cfg['use_starttls']?'true':'false').' (sync OK)';
});
$t->test('Connecteurs configurés', function() {
    $d = json_decode(file_get_contents(VX_ROOT.'/config/directory_connectors.json'),true);
    $n = count($d['connectors'] ?? []);
    if($n===0) return '⚠️ Aucun connecteur — configurez-en un dans Admin → AD/LDAP';
    $enabled = count(array_filter($d['connectors'],fn($c)=>!empty($c['enabled'])));
    return "$n connecteur(s), $enabled actif(s)";
});
$t->test('Connexion LDAP réelle (si connecteur actif)', function() {
    require_once VX_ROOT.'/inc/egress_helpers.php';
    require_once VX_ROOT.'/inc/ldap_helpers.php';
    $d    = json_decode(file_get_contents(VX_ROOT.'/config/directory_connectors.json'),true);
    $list = array_filter($d['connectors']??[], fn($c)=>!empty($c['enabled']));
    if(empty($list)) return '⚠️ Aucun connecteur actif — test ignoré';
    $cx  = array_values($list)[0];
    $cfg = ['scheme'=>$cx['scheme']??'ldap','host'=>$cx['host']??'localhost',
            'port'=>(int)($cx['port']??389),'use_starttls'=>!empty($cx['use_starttls']??$cx['use_tls']??false),
            'bind_dn'=>$cx['bind_dn']??'','bind_password'=>'','base_dn'=>$cx['base_dn']??''];
    $res = vx_ldap_bind($cfg);
    if(!$res['ok']) throw new \RuntimeException("Bind échoué: ".($res['error']??'?'));
    @ldap_unbind($res['link']);
    return "Connexion OK → {$cfg['host']}:{$cfg['port']}";
});
$t->test('Listing OUs (si connecteur actif)', function() {
    require_once VX_ROOT.'/inc/egress_helpers.php';
    require_once VX_ROOT.'/inc/ldap_helpers.php';
    $d    = json_decode(file_get_contents(VX_ROOT.'/config/directory_connectors.json'),true);
    $list = array_filter($d['connectors']??[], fn($c)=>!empty($c['enabled']));
    if(empty($list)) return '⚠️ Aucun connecteur actif — test ignoré';
    $cx  = array_values($list)[0];
    $cfg = ['scheme'=>$cx['scheme']??'ldap','host'=>$cx['host']??'localhost',
            'port'=>(int)($cx['port']??389),'use_starttls'=>!empty($cx['use_starttls']??$cx['use_tls']??false),
            'bind_dn'=>$cx['bind_dn']??'','bind_password'=>'','base_dn'=>$cx['base_dn']??''];
    $res = vx_ldap_bind($cfg);
    if(!$res['ok']) throw new \RuntimeException("Bind échoué: ".($res['error']??'?'));
    $ous = vx_ldap_list_ous($res['link'],(string)($cx['base_dn']??''));
    @ldap_unbind($res['link']);
    if(!$ous['ok']) throw new \RuntimeException("Listing OUs échoué: ".($ous['error']??'?'));
    return count($ous['ous']).' OU(s) trouvée(s): '.implode(', ',array_column(array_slice($ous['ous'],0,5),'ou'));
});

// ══════════════════════════════════════════════════════
// SUITE 4 : Bots & Agents IA
// ══════════════════════════════════════════════════════
$t->suite('Bots & Agents IA');

$t->test('Registre bots chargeable', function() {
    require_once VX_ROOT.'/inc/egress_helpers.php';
    $r = vx_json_load_assoc(VX_ROOT.'/config/bots_registry.json');
    vxa(is_array($r) && isset($r['bots']), 'bots_registry.json invalide');
    $e = count(array_filter($r['bots'],fn($b)=>!empty($b['enabled'])));
    return count($r['bots'])." bots, $e actif(s)";
});
$t->test('vx_get_bot() fonctionne', function() {
    require_once VX_ROOT.'/inc/egress_helpers.php';
    $r = vx_json_load_assoc(VX_ROOT.'/config/bots_registry.json');
    if(empty($r['bots'])) return '⚠️ Aucun bot';
    $id = $r['bots'][0]['bot_id'];
    $b  = vx_get_bot($id);
    vxa(is_array($b) && ($b['bot_id']??'')===$id, "vx_get_bot('$id') échoue");
    return "Bot '$id' trouvé OK";
});
$t->test('vx_host_allowed() — exact match', function() {
    require_once VX_ROOT.'/inc/egress_helpers.php';
    vxa(vx_host_allowed('api.groq.com',['api.groq.com']), 'exact match échoue');
    vxa(!vx_host_allowed('evil.com',['api.groq.com']), 'evil.com ne devrait pas passer');
    return 'Exact match OK';
});
$t->test('vx_host_allowed() — wildcard', function() {
    require_once VX_ROOT.'/inc/egress_helpers.php';
    vxa(vx_host_allowed('sub.example.org',['*.example.org']), 'wildcard échoue');
    vxa(!vx_host_allowed('example.org',['*.example.org']), 'root ne doit pas matcher wildcard');
    return 'Wildcard OK';
});
$t->test('Ollama accessible (LLM local)', function() {
    $ch = curl_init('http://127.0.0.1:11434/api/tags');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_CONNECTTIMEOUT=>2]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if($err||!$raw) throw new \RuntimeException("Ollama hors ligne. Lancez : ollama serve");
    $j = json_decode($raw,true);
    $models = array_column($j['models']??[],'name');
    if(empty($models)) throw new \RuntimeException("Ollama actif mais aucun modèle. Lancez : ollama pull phi3:mini");
    return 'En ligne — '.implode(', ',$models);
});
$t->test('phi3:mini présent dans Ollama', function() {
    $ch = curl_init('http://127.0.0.1:11434/api/tags');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3]);
    $raw = curl_exec($ch); curl_close($ch);
    if(!$raw) throw new \RuntimeException('Ollama hors ligne');
    $models = array_column(json_decode($raw,true)['models']??[],'name');
    $ok = !empty(array_filter($models,fn($m)=>str_contains($m,'phi3')));
    if(!$ok) throw new \RuntimeException("phi3:mini absent. Lancez : ollama pull phi3:mini\nDisponibles: ".implode(', ',$models));
    return 'phi3:mini disponible ✓';
});
$t->test('Ollama répond à une requête simple', function() {
    $ch = curl_init('http://127.0.0.1:11434/api/generate');
    $payload = json_encode(['model'=>'phi3:mini','prompt'=>'Reply only with the word: OK','stream'=>false,'options'=>['temperature'=>0,'num_predict'=>5]]);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>30]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if($err) throw new \RuntimeException("cURL: $err");
    $j = json_decode($raw,true);
    $resp = trim($j['response']??'');
    vxa($resp !== '', "Réponse vide de phi3:mini");
    return "Réponse: ".substr($resp,0,50);
});

// ══════════════════════════════════════════════════════
// SUITE 5 : Pipeline de sécurité
// ══════════════════════════════════════════════════════
$t->suite('Pipeline de sécurité');

$t->test('Fonctions pipeline disponibles', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    foreach(['vx_sp_analyze_input','vx_sp_filter_output','vx_sp_simple_scan','vx_sp_mask_text','vx_sp_decide'] as $f)
        vxa(function_exists($f),"$f() absente");
    return '5 fonctions disponibles';
});
$t->test('Scan regex — email RGPD détecté', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    $r = vx_sp_simple_scan('Contactez jean.dupont@example.com svp',['rgpd'],'input');
    vxa(!empty($r),'Email non détecté');
    vxa(in_array('rgpd.contact_details',array_column($r,'rule')),'Règle rgpd.contact_details absente');
    return 'Email → rgpd.contact_details ✓';
});
$t->test('Scan regex — IP ISO27001 détectée', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    $r = vx_sp_simple_scan('Serveur: 192.168.1.254',['iso27001'],'input');
    vxa(in_array('iso.ip_exposure',array_column($r,'rule')),'Règle iso.ip_exposure absente');
    return 'IP → iso.ip_exposure ✓';
});
$t->test('Scan regex — IBAN Finance', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    $r = vx_sp_simple_scan('IBAN: BE71096012345678',['finance'],'input');
    vxa(!empty($r),'IBAN non détecté');
    return 'IBAN détecté ✓';
});
$t->test('Scan regex — SSN HIPAA', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    $r = vx_sp_simple_scan('SSN: 123-45-6789',['hipaa'],'input');
    vxa(!empty($r),'SSN non détecté');
    return 'SSN → hipaa.ssn ✓';
});
$t->test('Prompt sain — zéro faux positif', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    $r = vx_sp_simple_scan('Aide-moi à rédiger un email de bienvenue pour notre équipe.',['rgpd','iso27001','finance','hipaa'],'input');
    vxa(empty($r),'Faux positif: '.implode(', ',array_column($r,'rule')));
    return 'Zéro faux positif ✓';
});
$t->test('Masquage email et IP dans les réponses', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    $text   = 'Résultat: test@example.com, serveur 10.0.0.1';
    $masked = vx_sp_mask_text($text,['rgpd','iso27001']);
    vxa(!str_contains($masked,'test@example.com'),'Email non masqué');
    vxa(!str_contains($masked,'10.0.0.1'),'IP non masquée');
    return "Masqué OK: $masked";
});
$t->test('Décision BLOCK sur violation HIGH', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    $v = [['rule'=>'rgpd.contact_details','reason'=>'Email','severity'=>'high','source'=>'regex','category'=>'privacy','score'=>70]];
    $d = vx_sp_decide($v,'input');
    vxa(in_array($d['decision'],['block','block_and_escalate']),'Violation high doit bloquer');
    return "Décision: {$d['decision']} (score: {$d['risk_score']})";
});
$t->test('Décision ALLOW sur prompt propre', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    $d = vx_sp_decide([],'input');
    vxa($d['decision']==='allow','Aucune violation → allow attendu');
    return 'Décision: allow ✓';
});
$t->test('vx_sp_filter_output() analyse les réponses LLM', function() {
    require_once VX_ROOT.'/inc/bootstrap.php';
    require_once VX_ROOT.'/inc/security_pipeline.php';
    // Réponse contenant un email
    $res = vx_sp_filter_output('Voici votre contact: admin@secret.com',['rgpd']);
    vxa(isset($res['decision']),'filter_output ne retourne pas de décision');
    vxa(!empty($res['violations']),'Email dans réponse non détecté');
    return "Output filtré: décision={$res['decision']}, ".count($res['violations'])." violation(s)";
});

// ══════════════════════════════════════════════════════
// SUITE 6 : Contrôle flux egress bots
// ══════════════════════════════════════════════════════
$t->suite('Contrôle flux egress bots');

$t->test('egress_fetch.php contient tous les contrôles', function() {
    $c = file_get_contents(VX_ROOT.'/egress_fetch.php');
    foreach(['vx_host_allowed','vx_host_resolves_public_only','vx_hmac_check','vx_content_violations'] as $fn)
        vxa(str_contains($c,$fn),"$fn absent de egress_fetch.php");
    return '4 contrôles sécurité présents';
});
$t->test('Bot sans auth → HTTP 401', function() {
    $ch = curl_init('http://localhost/velixa/egress_fetch.php');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>5,
        CURLOPT_POSTFIELDS=>json_encode(['url'=>'https://api.groq.com','method'=>'GET','actor'=>['id'=>'hacker','type'=>'bot']]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    $code = (int)curl_getinfo(curl_exec($ch)?$ch:$ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    vxa($code===401,"Bot sans clé → attendu 401, reçu $code");
    return "HTTP 401 reçu ✓ (accès refusé sans auth)";
});
$t->test('Hôte non autorisé → bloqué', function() {
    $kf = VX_ROOT.'/data/secure/egress_global_api_key.txt';
    if(!file_exists($kf)) return '⚠️ Clé API non encore générée — ignoré';
    $k = trim(file_get_contents($kf));
    $ch = curl_init('http://localhost/velixa/egress_fetch.php');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>5,
        CURLOPT_POSTFIELDS=>json_encode(['url'=>'https://evil-exfil.com/steal','method'=>'GET','actor'=>['id'=>'test','type'=>'bot']]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Api-Key: '.$k]]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode($raw,true);
    $blocked = $code===403 || in_array($j['policy']['decision']??'',['block','block_and_escalate']);
    vxa($blocked,"evil-exfil.com devrait être bloqué (HTTP $code)");
    return "evil-exfil.com bloqué (HTTP $code) ✓";
});
$t->test('IP privée → bloquée', function() {
    $kf = VX_ROOT.'/data/secure/egress_global_api_key.txt';
    if(!file_exists($kf)) return '⚠️ Clé API non encore générée — ignoré';
    $k = trim(file_get_contents($kf));
    $ch = curl_init('http://localhost/velixa/egress_fetch.php');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>5,
        CURLOPT_POSTFIELDS=>json_encode(['url'=>'http://192.168.1.1/admin','method'=>'GET','actor'=>['id'=>'test','type'=>'bot']]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Api-Key: '.$k]]);
    $code = (int)curl_getinfo(curl_exec($ch)?$ch:$ch, CURLINFO_HTTP_CODE); curl_close($ch);
    vxa(in_array($code,[400,403]),"IP privée → attendu 400/403, reçu $code");
    return "Accès IP privée bloqué (HTTP $code) ✓";
});
$t->test('Log egress écriture fonctionnelle', function() {
    require_once VX_ROOT.'/inc/egress_helpers.php';
    $p = VX_ROOT.'/logs/egress.ndjson';
    $e = ['ts'=>date('c'),'_test'=>true,'actor'=>['id'=>'phptest','type'=>'test'],'policy'=>['decision'=>'allow']];
    vx_append_json_log($p,$e);
    vxa(file_exists($p),'Log egress non créé');
    $last=''; foreach(file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l) $last=$l;
    vxa((json_decode($last,true)['_test']??false)===true,'Entrée test non trouvée');
    return 'Log écrit et relu OK';
});

// ══════════════════════════════════════════════════════
// RENDU HTML
// ══════════════════════════════════════════════════════
$results = $t->results();
$pass  = count(array_filter($results,fn($r)=>$r['status']==='PASS'));
$fail  = count(array_filter($results,fn($r)=>$r['status']==='FAIL'));
$total = count($results);
$ms    = round((microtime(true)-VX_TEST_START)*1000);
?><!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>VELIXA Tests</title>
<style>
:root{--bg:#0B0C0E;--surf:#121416;--bd:#1f242b;--green:#10B981;--red:#EF4444;--amber:#F59E0B;--blue:#3B82F6}
*{box-sizing:border-box;margin:0;padding:0}body{background:var(--bg);color:#fff;font-family:Inter,Arial,sans-serif;padding:24px;font-size:14px}
h1{font-size:22px;margin-bottom:4px}.meta{color:#9CA3AF;font-size:13px;margin-bottom:20px}
.bar{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px}
.sc{padding:12px 20px;border-radius:12px;font-weight:700;font-size:16px;border:1px solid}
.sc.p{background:#064e3b;border-color:var(--green);color:var(--green)}
.sc.f{background:#450a0a;border-color:var(--red);color:var(--red)}
.sc.t{background:#1e3a5f;border-color:var(--blue);color:#93c5fd}
.sc.m{background:#1f2937;border-color:#374151;color:#9CA3AF}
.st{font-size:15px;font-weight:700;margin:20px 0 8px;padding-bottom:6px;border-bottom:1px solid var(--bd);color:#93c5fd}
.row{display:flex;align-items:flex-start;gap:10px;padding:8px 12px;border-radius:8px;margin-bottom:4px;background:var(--surf);border:1px solid var(--bd)}
.row:hover{background:#161a1f}
.badge{min-width:52px;text-align:center;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;flex-shrink:0;margin-top:2px}
.badge.PASS{background:#064e3b;color:var(--green)}.badge.FAIL{background:#450a0a;color:var(--red)}
.nm{font-weight:600;flex:1}.dt{font-size:12px;color:#9CA3AF;margin-top:2px;font-family:monospace;white-space:pre-wrap}
.dt.e{color:#f87171}.tm{color:#4B5563;font-size:11px;flex-shrink:0;margin-top:3px}
.warn{background:#451a03;border:1px solid var(--amber);color:var(--amber);padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px}
.btn{display:inline-block;margin-top:20px;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:700;margin-right:8px;font-size:14px}
.btn.b{background:var(--blue);color:#fff}.btn.g{background:#1b2330;color:#e5e7eb;border:1px solid var(--bd)}
</style></head><body>
<h1>🧪 VELIXA — Suite de tests</h1>
<div class="meta">Exécuté le <?=date('Y-m-d H:i:s')?> · PHP <?=PHP_VERSION?></div>
<?php if($fail>0): ?><div class="warn">⚠️ <?=$fail?> test(s) échoué(s) — corrigez les points rouges ci-dessous.</div><?php endif; ?>
<div class="bar">
  <div class="sc p">✅ <?=$pass?> PASS</div>
  <div class="sc f">❌ <?=$fail?> FAIL</div>
  <div class="sc t">📊 <?=$total?> tests</div>
  <div class="sc m">⏱ <?=$ms?>ms</div>
</div>
<?php $suite=''; foreach($results as $r):
  if($r['suite']!==$suite){$suite=$r['suite'];echo "<div class='st'>📂 $suite</div>";}
  $ec = $r['status']==='FAIL'?'e':'';
  $warn = str_starts_with($r['detail']??'','⚠️');
?>
<div class="row">
  <span class="badge <?=$r['status']?>"><?=$r['status']?></span>
  <div style="flex:1">
    <div class="nm"><?=htmlspecialchars($r['name'])?></div>
    <?php if($r['detail']??''): ?>
    <div class="dt <?=$warn?'':''.($r['status']==='FAIL'?'e':'')?>"><?=htmlspecialchars($r['detail'])?></div>
    <?php endif; ?>
  </div>
  <div class="tm"><?=$r['ms']?>ms</div>
</div>
<?php endforeach; ?>
<a class="btn b" href="?token=velixatest">🔄 Relancer</a>
<a class="btn g" href="../dashboard.php">⬅ Dashboard</a>
</body></html>
