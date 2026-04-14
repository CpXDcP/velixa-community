# analyse_file.py — AUDIT RAPIDE + PRE-SCAN DETERMINISTE + EXCERPT + RESUME AUTO
import sys, os, json, re, subprocess, base64
from pathlib import Path

try:
    import fitz
except ImportError:
    fitz = None
try:
    import docx
except ImportError:
    docx = None
try:
    from pdfminer.high_level import extract_text as pdfminer_extract
    from pdfminer.pdfparser import PDFSyntaxError
    HAS_PDFMINER = True
except ImportError:
    HAS_PDFMINER = False

def emit_json(obj, ensure_ascii: bool = False) -> None:
    try:
        sys.stdout.buffer.write(json.dumps(obj, ensure_ascii=ensure_ascii).encode('utf-8'))
        sys.stdout.buffer.flush()
    except Exception:
        print(json.dumps(obj, ensure_ascii=ensure_ascii))

PDF_MAX_PAGES = 30
MAX_CHARS = 200_000
MAX_FILE_SIZE = 8 * 1024 * 1024
EXCERPT_MAX = 8000
SUMMARY_TRIGGER = 2000
SUMMARY_MAX = 1200
RUNNER_TIMEOUT = int(os.environ.get('VELIXA_RULES_TIMEOUT', '60'))
BASE_DIR = Path(__file__).resolve().parent
LOG_DIR = BASE_DIR / 'logs'
LOG_DIR.mkdir(parents=True, exist_ok=True)
TRACE_LOG = LOG_DIR / 'trace.log'
OCR_DEBUG_TXT = LOG_DIR / 'ocr_last_text.txt'

def log_line(msg: str):
    try:
        with open(TRACE_LOG, 'a', encoding='utf-8') as f:
            f.write(msg.rstrip() + '\n')
    except Exception:
        pass

def normalize_windows_path(p: str) -> str:
    return p.replace('\\', '/')

def ensure_reason(text):
    return text.strip() if isinstance(text, str) and text.strip() else 'Violation détectée'

def extract_text_pdf_native(filepath: str) -> str:
    # Tentative 1 : PyMuPDF (fitz)
    if fitz:
        try:
            parts = []
            with fitz.open(filepath) as doc:
                for i in range(min(len(doc), PDF_MAX_PAGES)):
                    parts.append(doc[i].get_text('text') or '')
            text = '\n'.join(parts).strip()
            if text:
                log_line(f'[analyse_file.py] PDF extrait via PyMuPDF: {len(text)} chars')
                return text[:MAX_CHARS]
        except Exception as e:
            log_line(f'[analyse_file.py] PyMuPDF error: {e}')

    # Tentative 2 : pdfminer (fallback)
    if HAS_PDFMINER:
        try:
            text = pdfminer_extract(filepath)
            if text and text.strip():
                log_line(f'[analyse_file.py] PDF extrait via pdfminer: {len(text)} chars')
                return text.strip()[:MAX_CHARS]
        except Exception as e:
            log_line(f'[analyse_file.py] pdfminer error: {e}')

    return ''

def extract_text_docx(filepath: str) -> str:
    if not docx:
        return ''
    try:
        d = docx.Document(filepath)
        return '\n'.join(p.text for p in d.paragraphs).strip()[:MAX_CHARS]
    except Exception as e:
        log_line(f'[analyse_file.py] DOCX error: {e}')
        return ''

def extract_text_txt(filepath: str) -> str:
    p = Path(filepath)
    try:
        return p.read_text(encoding='utf-8', errors='ignore')[:MAX_CHARS]
    except Exception:
        return p.read_text(errors='ignore')[:MAX_CHARS]

def extract_text_from_file(filepath: str):
    filepath = normalize_windows_path(filepath)
    p = Path(filepath)
    ext = p.suffix.lower()
    try:
        if p.exists() and p.stat().st_size > MAX_FILE_SIZE:
            return '', 'file_too_large'
    except Exception:
        pass
    if ext == '.pdf':
        text = extract_text_pdf_native(filepath)
        return (text, '') if text else ('', 'pdf_no_text_ocr_disabled')
    if ext == '.docx':
        text = extract_text_docx(filepath)
        return (text, '') if text else ('', 'docx_empty')
    if ext in ['.txt', '.md', '.csv', '.json', '.log']:
        text = extract_text_txt(filepath)
        return (text, '') if text else ('', 'txt_empty')
    if ext in ['.png', '.jpg', '.jpeg', '.tiff', '.bmp']:
        return '', 'image_skipped_ocr_disabled'
    return '', 'unsupported_type'

def summarize_quick(text: str, target_len: int = SUMMARY_MAX) -> str:
    t = re.sub(r'\s+', ' ', text).strip()
    chunks = [t[: min(600, len(t))]]
    lines = [ln.strip() for ln in text.splitlines() if ln.strip()]
    bullet_like = []
    for ln in lines:
        if re.match(r'^(\-|\*|•|\d+[\.\)]|\([a-zA-Z]\))\s+', ln):
            bullet_like.append(ln)
        if len(bullet_like) >= 12:
            break
    if bullet_like:
        chunks.append(' • ' + ' • '.join(bullet_like[:12]))
    strong_sentences = []
    sentences = re.split(r'(?<=[\.\!\?])\s+', t)
    key_re = re.compile(r'\b\d{1,2}/\d{1,2}/\d{2,4}\b|\b\d{4}-\d{2}-\d{2}\b|\b\d+(\.\d+)?\s?%\b|\b\d{1,3}(?:[ .]\d{3})+(?:,\d+)?\b|\b€|\$|USD|EUR|CHF|GBP\b|[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}|\b(?:\d{1,3}\.){3}\d{1,3}\b', re.I)
    for s in sentences:
        if key_re.search(s):
            strong_sentences.append(s.strip())
        if len(strong_sentences) >= 8:
            break
    if strong_sentences:
        chunks.append(' '.join(strong_sentences))
    summary = ' '.join(chunks).strip()
    if len(summary) > target_len:
        summary = summary[:target_len].rsplit(' ', 1)[0].strip() + '…'
    return summary

def pre_scan_violations(text: str, active_flags):
    v = []
    flat = re.sub(r'\s+', ' ', text)
    low = text.lower()
    if 'rgpd' in active_flags:
        m_email = re.search(r'[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}', text, re.I)
        if m_email and '***' not in m_email.group(0):
            v.append({'rule': 'rgpd.personal_identifiers', 'reason': f'Email non anonymisé : {m_email.group(0)}', 'severity': 'high', 'source': 'regex'})
    if 'iso27001' in active_flags and re.search(r'\b(?:\d{1,3}\.){3}\d{1,3}\b', text):
        v.append({'rule': 'iso.ip_exposure', 'reason': 'Adresse IP complète détectée', 'severity': 'medium', 'source': 'regex'})
    if 'hipaa' in active_flags and re.search(r'\b\d{3}-\d{2}-\d{4}\b', text):
        v.append({'rule': 'hipaa.ssn', 'reason': 'SSN détecté', 'severity': 'high', 'source': 'regex'})
    if 'finance' in active_flags:
        if re.search(r'\b[A-Z]{2}\d{2}(?:\s?\d{2,4}){3,6}\b', flat):
            v.append({'rule': 'finance.iban_or_pan', 'reason': 'IBAN détecté', 'severity': 'high', 'source': 'regex'})
        if re.search(r'\b(?:\d[ -]?){13,19}\b', flat):
            v.append({'rule': 'finance.iban_or_pan', 'reason': 'Numéro de carte (PAN) potentiellement détecté', 'severity': 'high', 'source': 'regex'})
    if ('donnees_sante' in active_flags or 'rgpd' in active_flags) and re.search(r'\b(cancer|vih|alzheimer|diab[èe]te|hépatite|patient|ordonnance|traitement)\b', low):
        v.append({'rule': 'rgpd.special_categories', 'reason': 'Terme médical sensible détecté', 'severity': 'high', 'source': 'regex'})
    if 'nis2' in active_flags and re.search(r'\b(admin|root|mfa|intrusion|vpn|firewall|cve|exploit)\b', low):
        v.append({'rule': 'nis2.cyber_keywords', 'reason': 'Mot-clé cybersécurité détecté', 'severity': 'medium', 'source': 'regex'})
    return v

def locate_php_cli() -> str:
    env_php = os.environ.get('PHP_CLI')
    if env_php and Path(env_php).exists():
        return env_php
    xampp_php = r'C:\xampp\php\php.exe'
    if Path(xampp_php).exists():
        return xampp_php
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
        if proc.stderr and proc.stderr.strip():
            log_line(f'[analyse_file.py][rules_runner][stderr] {proc.stderr.strip()}')
        raw = (proc.stdout or '').strip()
        if not raw:
            return {'status': 'empty', 'violations': []}
        return json.loads(raw)
    except subprocess.TimeoutExpired:
        log_line('[analyse_file.py] rules_runner timeout')
    except Exception as e:
        log_line(f'[analyse_file.py] rules_runner error: {e}')
    return {'status': 'error', 'violations': []}

def infer_context(text: str):
    low = text.lower()
    themes = []
    if re.search(r'\b(patient|diagnostic|ordonnance|médical|medical|traitement)\b', low): themes.append('health')
    if re.search(r'\b(iban|facture|paiement|salaire|budget|carte)\b', low): themes.append('finance')
    if re.search(r'\b(admin|vpn|firewall|mot de passe|token|api key|cve|mfa)\b', low): themes.append('security')
    if re.search(r'\b(contrat|avenant|nda|litige|avocat)\b', low): themes.append('legal')
    if re.search(r'\b(employé|recrutement|licenciement|évaluation|cv)\b', low): themes.append('hr')
    sensitivity = 'high' if len(themes) >= 2 else ('medium' if themes else 'low')
    return {'themes': themes, 'sensitivity': sensitivity}

def main():
    if len(sys.argv) != 3:
        emit_json({'error': 'Usage: analyse_file.py <filepath> <rules_json>'})
        return
    filepath = sys.argv[1]
    try:
        active_flags = json.loads(sys.argv[2])
        if not isinstance(active_flags, list): active_flags = []
    except Exception:
        active_flags = []
    if not active_flags:
        active_flags = ['rgpd', 'iso27001', 'hipaa', 'finance', 'nis2', 'donnees_sante']
    text, note = extract_text_from_file(filepath)
    try:
        with open(OCR_DEBUG_TXT, 'w', encoding='utf-8') as f:
            f.write(text if text else '')
    except Exception:
        pass
    if not text.strip():
        reason_map = {'pdf_no_text_ocr_disabled': 'PDF sans couche texte (probablement scanné). OCR désactivé (audit rapide).', 'image_skipped_ocr_disabled': 'Image non lue (OCR désactivé en audit rapide).', 'docx_empty': 'DOCX sans texte lisible.', 'txt_empty': 'Fichier texte vide.', 'unsupported_type': 'Type de fichier non supporté en audit rapide.', 'file_too_large': 'Fichier trop volumineux pour analyse.'}
        reason = reason_map.get(note, 'Aucun texte exploitable extrait (audit rapide).')
        log_line(f'[analyse_file.py] output VIDE pour {filepath} — note={note}')
        emit_json({'violations': [{'rule': 'file.empty_or_unreadable', 'reason': reason, 'severity': 'high'}], 'compliant': False, 'excerpt': '', 'summary': '', 'char_count': 0, 'context': {'themes': [], 'sensitivity': 'high'}, 'risk_score': 90}, ensure_ascii=False)
        return
    violations = pre_scan_violations(text, active_flags)
    llm = call_rules_runner(text[:40000], active_flags)
    if isinstance(llm, dict) and isinstance(llm.get('violations'), list):
        seen = {(item.get('rule') or '') + '|' + ensure_reason(item.get('reason')) for item in violations if isinstance(item, dict)}
        for item in llm['violations']:
            if not isinstance(item, dict):
                continue
            rule = item.get('rule') or 'unknown'
            reason = ensure_reason(item.get('reason'))
            sev = item.get('severity') or ('high' if any(k in rule for k in ['special','secret','phi','iban','pan']) else 'medium')
            entry = {'rule': rule, 'reason': reason, 'severity': sev, 'source': item.get('source', 'phi3-mini')}
            key = rule + '|' + reason
            if key not in seen:
                violations.append(entry)
                seen.add(key)
    excerpt = text[:EXCERPT_MAX]
    summary = summarize_quick(text, SUMMARY_MAX) if len(text) > SUMMARY_TRIGGER else ''
    context = infer_context(text[:8000])
    risk_score = min(100, sum(70 if (v.get('severity') == 'high') else 35 for v in violations) + (20 if context['sensitivity'] == 'high' else 0))
    if len(violations) == 0:
        decision = 'allow'
    elif risk_score >= 85:
        decision = 'block_and_escalate'
    elif risk_score >= 55:
        decision = 'block'
    else:
        decision = 'allow_with_notice'
    emit_json({'violations': violations, 'compliant': len(violations) == 0, 'decision': decision, 'excerpt': excerpt, 'summary': summary, 'char_count': len(text), 'context': context, 'risk_score': risk_score}, ensure_ascii=False)

if __name__ == '__main__':
    main()
