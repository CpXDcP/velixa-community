<?php

require_once __DIR__ . '/inc/secure_store.php';

function vx_users_encrypt_secret(?string $plain): ?string {
    if ($plain === null || $plain === '') return $plain;
    if (str_starts_with($plain, 'enc:')) return $plain;
    return 'enc:' . vx_secure_store_encrypt($plain);
}

function vx_users_decrypt_secret(?string $stored): ?string {
    if ($stored === null || $stored === '') return $stored;
    if (!str_starts_with($stored, 'enc:')) return $stored;
    $plain = vx_secure_store_decrypt(substr($stored, 4));
    return is_string($plain) ? $plain : null;
}

function vx_hash_recovery_code(string $code): string {
    return 'rc$' . password_hash(strtoupper(trim($code)), PASSWORD_DEFAULT);
}

function vx_recovery_code_matches(string $input, string $stored): bool {
    $input = strtoupper(trim($input));
    if (str_starts_with($stored, 'rc$')) {
        return password_verify($input, substr($stored, 3));
    }
    return hash_equals(strtoupper($stored), $input);
}

/* users_lib.php — compatible with your current JSON:
   {
     "admin": {"password":"$2y$...","role":"admin","metier":"..."},
     "user_it": {"password":"$2y$...","role":"user","metier":"it"}
   }
   Also works if some entries use {"username":"...","password_hash":"..."}.
*/

function users_path() {
    return __DIR__ . '/users.json';
}

function load_users_raw() {
    $p = users_path();
    if (!file_exists($p)) return [];
    $raw = @file_get_contents($p);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function normalize_user($uid, $u) {
    if (!is_array($u)) $u = [];
    $username = isset($u['username']) ? $u['username'] : $uid;
    $pwd_hash = isset($u['password_hash']) ? $u['password_hash'] : (isset($u['password']) ? $u['password'] : '');

    $u_norm = $u;
    $u_norm['_uid'] = $uid;
    $u_norm['username'] = $username;
    $u_norm['password_hash'] = $pwd_hash;
    if (!isset($u_norm['role'])) $u_norm['role'] = 'user';
    if (!isset($u_norm['metier']) && isset($u['job'])) $u_norm['metier'] = $u['job'];
    if (!isset($u_norm['must_change_password'])) $u_norm['must_change_password'] = false;
    if (!isset($u_norm['mfa'])) $u_norm['mfa'] = array('enabled'=>false,'type'=>null,'secret'=>null,'recovery_codes'=>array());
    if (!empty($u_norm['mfa']['secret'])) $u_norm['mfa']['secret'] = vx_users_decrypt_secret((string)$u_norm['mfa']['secret']);
    if (!isset($u_norm['status'])) $u_norm['status'] = 'active';
    if (!isset($u_norm['last_login'])) $u_norm['last_login'] = null;
    return $u_norm;
}

function load_users() {
    $raw = load_users_raw();
    $out = array();
    foreach ($raw as $uid => $u) {
        $out[$uid] = normalize_user($uid, $u);
    }
    return $out;
}

function save_users($users) {
    $raw = load_users_raw();
    foreach ($users as $uid => $u_norm) {
        if (!isset($raw[$uid]) || !is_array($raw[$uid])) $raw[$uid] = array();

        // keep original field name for password
        if (array_key_exists('password', $raw[$uid])) {
            if (isset($u_norm['password_hash'])) $raw[$uid]['password'] = $u_norm['password_hash'];
        } else {
            if (isset($u_norm['password_hash'])) $raw[$uid]['password_hash'] = $u_norm['password_hash'];
        }

        // only write username if it already existed
        if (array_key_exists('username', $raw[$uid]) && isset($u_norm['username'])) {
            $raw[$uid]['username'] = $u_norm['username'];
        }

        foreach (array('role','metier','must_change_password','status','last_login') as $k) {
            if (isset($u_norm[$k])) $raw[$uid][$k] = $u_norm[$k];
        }
        if (isset($u_norm['mfa']) && is_array($u_norm['mfa'])) {
            $mfa = $u_norm['mfa'];
            if (!empty($mfa['secret'])) $mfa['secret'] = vx_users_encrypt_secret((string)$mfa['secret']);
            if (!empty($mfa['recovery_codes']) && is_array($mfa['recovery_codes'])) {
                $codes = [];
                foreach ($mfa['recovery_codes'] as $code) {
                    $code = (string)$code;
                    $codes[] = str_starts_with($code, 'rc$') ? $code : vx_hash_recovery_code($code);
                }
                $mfa['recovery_codes'] = $codes;
            }
            $raw[$uid]['mfa'] = $mfa;
        }
    }

    $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $p = users_path();
    $tmp = $p . '.tmp';
    $ok = @file_put_contents($tmp, $json, LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $p);
}

function find_user_by_username($username) {
    $users = load_users();
    $needle = strtolower($username);
    foreach ($users as $uid => $u) {
        $u_name = isset($u['username']) ? strtolower($u['username']) : '';
        if ($u_name !== '' && hash_equals($u_name, $needle)) return $u;
        if (hash_equals(strtolower($uid), $needle)) return $u; // fallback to key ("admin")
    }
    return null;
}

function update_user($uid, $patch) {
    $all = load_users();
    if (!isset($all[$uid])) return false;
    $all[$uid] = array_replace_recursive($all[$uid], $patch);
    return save_users($all);
}

function generate_uid() {
    return 'uid-' . bin2hex(random_bytes(8));
}

function create_user($uid_or_username, $user) {
    $raw = load_users_raw();
    if (isset($raw[$uid_or_username])) return false;
    if (isset($user['password_hash'])) {
        $raw[$uid_or_username] = array('password' => $user['password_hash']);
        unset($user['password_hash']);
    } else {
        $raw[$uid_or_username] = array();
    }
    foreach (array('role','metier','must_change_password','status','last_login','username') as $k) {
        if (isset($user[$k])) $raw[$uid_or_username][$k] = $user[$k];
    }
    if (isset($user['mfa']) && is_array($user['mfa'])) {
        $mfa = $user['mfa'];
        if (!empty($mfa['secret'])) $mfa['secret'] = vx_users_encrypt_secret((string)$mfa['secret']);
        if (!empty($mfa['recovery_codes']) && is_array($mfa['recovery_codes'])) {
            $codes = [];
            foreach ($mfa['recovery_codes'] as $code) {
                $code = (string)$code;
                $codes[] = str_starts_with($code, 'rc$') ? $code : vx_hash_recovery_code($code);
            }
            $mfa['recovery_codes'] = $codes;
        }
        $raw[$uid_or_username]['mfa'] = $mfa;
    }
    $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $p = users_path();
    $tmp = $p . '.tmp';
    $ok = @file_put_contents($tmp, $json, LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $p) ? $uid_or_username : false;
}
