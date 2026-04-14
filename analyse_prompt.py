import sys
import json
import re
import os
import subprocess
import base64
from datetime import datetime
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
MAX_TEXT = 40000
RUNNER_TIMEOUT = int(os.environ.get('VELIXA_RULES_TIMEOUT', '45'))

SEVERITY_WEIGHT = {"low": 10, "medium": 35, "high": 70, "critical": 100}


def add(violations, rule, reason, severity="medium", source="local-analyzer", category=None):
    category = category or rule.split('.', 1)[0]
    violations.append({
        "rule": rule,
        "reason": reason,
        "severity": severity,
        "source": source,
        "category": category,
    })


def infer_context(prompt: str):
    low = prompt.lower()
    themes = []
    if re.search(r"(patient|diagnostic|ordonnance|m[ée]dical|medical|traitement|hospitalisation)", low):
        themes.append("health")
    if re.search(r"(iban|facture|paiement|salaire|budget|carte|tva|fiscal)", low):
        themes.append("finance")
    if re.search(r"(admin|vpn|firewall|mot de passe|password|token|api key|cve|mfa|intrusion|active directory|ldap)", low):
        themes.append("security")
    if re.search(r"(contrat|avenant|nda|litige|avocat|clause)", low):
        themes.append("legal")
    if re.search(r"(employ[ée]|recrutement|licenciement|[ée]valuation|cv|ressources humaines|rh)", low):
        themes.append("hr")
    themes = list(dict.fromkeys(themes))
    sensitivity = "high" if len(themes) >= 2 else ("medium" if themes else "low")
    return {"themes": themes, "sensitivity": sensitivity}


def locate_php_cli() -> str:
    env_php = os.environ.get('PHP_CLI')
    if env_php and Path(env_php).exists():
        return env_php
    xampp_php = Path(r'C:\xampp\php\php.exe')
    if xampp_php.exists():
        return str(xampp_php)
    return 'php'


def flags_to_policy_ids(active_flags):
    mapping = {
        'rgpd': ['rgpd.personal_identifiers','rgpd.contact_details','rgpd.online_identifiers','rgpd.financial_identifiers','rgpd.special_categories','rgpd.children_data','rgpd.images_faces','rgpd.data_minimization','rgpd.pseudonymization_anonymization','rgpd.transparency_fairness'],
        'iso27001': ['iso.ip_exposure','iso.secrets_in_prompt'],
        'nis2': ['nis2.cyber_keywords','nis2.config_disclosure'],
        'hipaa': ['hipaa.ssn','hipaa.phi_sensitive'],
        'finance': ['finance.iban_or_pan'],
        'confidentialite_legale': ['legal.confidential_contract'],
        'confidentialite_rh': ['hr.identifiable_employee'],
        'donnees_sante': ['health.critical_diseases'],
    }
    ids = []
    for flag in active_flags:
        ids.extend(mapping.get(flag, []))
    return list(dict.fromkeys(ids))


def call_rules_runner(text: str, active_flags, timeout_sec: int = RUNNER_TIMEOUT):
    php = locate_php_cli()
    runner = BASE_DIR / 'rules_runner.php'
    policies = flags_to_policy_ids(active_flags)
    if not runner.exists() or not policies:
        return {'status': 'skipped', 'violations': []}
    code = "require $argv[1]; $txt=base64_decode($argv[2]); $pol=json_decode(base64_decode($argv[3]), true) ?: []; $res=run_policies($txt,$pol); echo json_encode($res, JSON_UNESCAPED_UNICODE);"
    args = [php, '-r', code, str(runner), base64.b64encode(text.encode('utf-8')).decode('ascii'), base64.b64encode(json.dumps(policies, ensure_ascii=False).encode('utf-8')).decode('ascii')]
    try:
        proc = subprocess.run(args, capture_output=True, text=True, timeout=timeout_sec, cwd=str(BASE_DIR))
        raw = (proc.stdout or '').strip()
        if not raw:
            return {'status': 'empty', 'violations': []}
        return json.loads(raw)
    except Exception:
        return {'status': 'error', 'violations': []}


def local_scan(prompt: str, active_rules):
    violations = []
    if "iso27001" in active_rules and re.search(r'(?:\d{1,3}\.){3}\d{1,3}', prompt):
        add(violations, "iso.ip_exposure", "Adresse IP détectée", "medium", category="security")
    if "rgpd" in active_rules and re.search(r'[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}', prompt, re.I):
        add(violations, "rgpd.contact_details", "Adresse e-mail détectée", "high", category="privacy")
    if "confidentialite_rh" in active_rules and re.search(r"[A-ZÀ-ÖØ-Ý][a-zà-öø-ÿ'’-]+\s+[A-ZÀ-ÖØ-Ý][a-zà-öø-ÿ'’-]+", prompt):
        add(violations, "hr.identifiable_employee", "Nom de personne détecté dans un contexte RH", "medium", category="hr")
    if "hipaa" in active_rules and re.search(r"\d{3}-\d{2}-\d{4}", prompt):
        add(violations, "hipaa.ssn", "Numéro type SSN détecté", "high", category="health")
    maladies = ["cancer", "sida", "alzheimer", "diabète", "epilepsie", "épilepsie"]
    if "donnees_sante" in active_rules and any(m in prompt.lower() for m in maladies):
        add(violations, "health.critical_diseases", "Maladie critique détectée", "high", category="health")
    if "finance" in active_rules:
        iban_regex = r'[A-Z]{2}[0-9]{2}(?:[\s \-]?[0-9A-Z]{4}){3,5}'
        if re.search(iban_regex, prompt):
            add(violations, "finance.iban_or_pan", "IBAN potentiel détecté", "high", category="finance")
    if "confidentialite_legale" in active_rules and any(kw in prompt.lower() for kw in ["contrat", "litige", "clause", "juridique", "tribunal", "plainte"]):
        add(violations, "legal.confidential_contract", "Contexte juridique sensible détecté", "medium", category="legal")
    if "nis2" in active_rules and any(kw in prompt.lower() for kw in ["mfa", "authentification", "admin", "faille", "intrusion", "cyberattaque", "vpn", "firewall", "ldap", "active directory"]):
        add(violations, "nis2.cyber_keywords", "Mot-clé cybersécurité détecté", "medium", category="cyber")
    return violations


def merge_violations(*sets):
    out = []
    seen = set()
    for s in sets:
        for v in s:
            if not isinstance(v, dict):
                continue
            rule = str(v.get('rule') or 'rule')
            reason = str(v.get('reason') or 'Violation')
            key = rule + '|' + reason
            if key in seen:
                continue
            seen.add(key)
            sev = str(v.get('severity') or 'medium').lower()
            cat = str(v.get('category') or rule.split('.', 1)[0])
            out.append({
                'rule': rule,
                'reason': reason,
                'severity': sev,
                'source': str(v.get('source') or 'analyzer'),
                'category': cat,
                'score': SEVERITY_WEIGHT.get(sev, 20),
            })
    return out


def decide(violations, context):
    if not violations:
        return {'decision': 'allow', 'risk_score': 0, 'reason': 'Aucune violation détectée', 'should_escalate': False}
    risk = min(100, sum(v.get('score', 20) for v in violations) + (20 if context.get('sensitivity') == 'high' else 0))
    categories = {v.get('category', 'general') for v in violations}
    high = any(v.get('severity') in ('high', 'critical') for v in violations)
    if risk >= 90:
        return {'decision': 'block_and_escalate', 'risk_score': risk, 'reason': 'Contenu bloqué et signalé pour revue', 'should_escalate': True}
    # FIX v7: seuil abaissé 70→45 pour violations contextuelles multi-catégories
    if high or risk >= 45 or len(categories) >= 2:
        return {'decision': 'block', 'risk_score': risk, 'reason': 'Contenu bloqué par les politiques actives', 'should_escalate': len(categories) >= 2}
    # FIX v7: allow_with_notice seulement si risk >= 25
    if risk >= 25:
        return {'decision': 'allow_with_notice', 'risk_score': risk, 'reason': 'Contenu autorisé avec avertissement', 'should_escalate': False}
    return {'decision': 'allow', 'risk_score': risk, 'reason': 'Contenu autorisé', 'should_escalate': False}


def build_summary(violations):
    return {
        'count': len(violations),
        'rules': list(dict.fromkeys(v.get('rule', 'rule') for v in violations)),
        'categories': list(dict.fromkeys(v.get('category', 'general') for v in violations)),
    }


if len(sys.argv) < 3:
    print(json.dumps({"error": "Arguments manquants"}, ensure_ascii=False))
    sys.exit(1)

prompt = (sys.argv[1] or '')[:MAX_TEXT]
try:
    active_rules = json.loads(sys.argv[2])
    if not isinstance(active_rules, list):
        active_rules = []
except Exception as e:
    print(json.dumps({"error": f"Erreur de parsing des règles : {str(e)}"}, ensure_ascii=False))
    sys.exit(1)

context = infer_context(prompt)
local_viol = local_scan(prompt, active_rules)
llm_raw = call_rules_runner(prompt, active_rules)
llm_viol = llm_raw.get('violations', []) if isinstance(llm_raw, dict) else []
violations = merge_violations(local_viol, llm_viol)
decision = decide(violations, context)
result = {
    "engine": "analyse_prompt.py",
    "analysis_mode": "local+phi3-mini",
    "context": context,
    "violations": violations,
    "risk_score": decision["risk_score"],
    "decision": decision["decision"],
    "should_escalate": decision["should_escalate"],
    "reason": decision["reason"],
    "summary": build_summary(violations),
}
print(json.dumps(result, ensure_ascii=False))

try:
    log_entry = {
        "datetime": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "prompt_excerpt": prompt[:200],
        "rules": active_rules,
        "result": result
    }
    log_dir = BASE_DIR / 'logs'
    log_dir.mkdir(parents=True, exist_ok=True)
    log_path = log_dir / 'debug_filtering.json'
    data = []
    if log_path.exists():
        try:
            data = json.loads(log_path.read_text(encoding='utf-8'))
            if not isinstance(data, list):
                data = []
        except Exception:
            data = []
    data.append(log_entry)
    log_path.write_text(json.dumps(data[-200:], indent=2, ensure_ascii=False), encoding='utf-8')
except Exception:
    pass
