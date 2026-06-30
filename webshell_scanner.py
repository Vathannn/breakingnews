#!/usr/bin/env python3
"""
webshell_scanner.py
====================
Pemindai sederhana untuk mendeteksi kemungkinan webshell / backdoor PHP
di dalam sebuah direktori (mis. instalasi Joomla/WordPress/XAMPP).

CARA PAKAI:
    python3 webshell_scanner.py "C:\\xampp\\htdocs\\afpweb"

    Opsional:
    python3 webshell_scanner.py "C:\\xampp\\htdocs\\afpweb" --min-score 20 --out report.txt

CATATAN:
- Ini HEURISTIK, bukan antivirus. Selalu verifikasi manual sebelum
  menghapus/mengkarantina file, terutama yang berskor rendah.
- Skor 0-100, semakin tinggi semakin mencurigakan.
- Tidak butuh library eksternal — cukup Python 3 standar.
"""

import os
import re
import sys
import argparse
import hashlib

# ---------------------------------------------------------------------------
# Daftar pola mencurigakan beserta bobot skornya
# ---------------------------------------------------------------------------

PATTERNS = [
    # (regex, bobot, label)
    (r'\beval\s*\(', 18, "Penggunaan eval() — eksekusi kode dinamis"),
    (r'\bassert\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)', 25, "assert() dengan input user (eksekusi kode)"),
    (r'\b(system|exec|shell_exec|passthru|popen|proc_open)\s*\(', 20, "Fungsi eksekusi command sistem"),
    (r'\bpcntl_exec\s*\(', 18, "pcntl_exec — eksekusi proses"),
    (r'\$_(GET|POST|REQUEST|COOKIE)\s*\[\s*[\'"](cmd|c|action|exec|x|shell|do)[\'"]\s*\]', 15, "Parameter command umum pada webshell (cmd/exec/shell/x)"),
    (r'base64_decode\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)', 22, "base64_decode langsung dari input user"),
    (r'(base64_decode|hex2bin|gzinflate|gzuncompress|str_rot13)\s*\(\s*[\'"][A-Za-z0-9+/=]{40,}', 16, "Blob terenkode panjang (kemungkinan payload tersembunyi)"),
    (r'create_function\s*\(', 12, "create_function() — sering dipakai untuk obfuscation"),
    (r'preg_replace\s*\([^,]*[\'"][^\'"]*/e[\'"]', 22, "preg_replace dengan modifier /e (eksekusi kode, deprecated/berbahaya)"),
    (r'\$\{?\$_(GET|POST|REQUEST|COOKIE)', 14, "Variable-variable dari input user (teknik obfuscation umum)"),
    (r'move_uploaded_file\s*\(', 8, "Fungsi upload file (perlu cek validasi & autentikasi)"),
    (r'fwrite\s*\(.*\$_(GET|POST|REQUEST)', 16, "Menulis file dari input user langsung"),
    (r'@?unlink\s*\(\s*__FILE__\s*\)', 20, "Self-delete (__FILE__) — pola umum webshell sekali pakai"),
    (r'function\s+\w+\s*\(\s*\)\s*\{\s*return\s+__FILE__', 6, "Self-copy/self-reference pattern"),
    (r'copy\s*\(\s*__FILE__', 12, "Self-copy persistence"),
    (r'phpinfo\s*\(\s*\)', 5, "phpinfo() — biasanya recon attacker, bukan kode aplikasi normal"),
    (r'curl_exec|file_get_contents\s*\(\s*[\'"]https?://', 10, "Mengambil konten dari URL eksternal (kemungkinan loader remote payload)"),
    (r'\bchr\s*\(\d+\)\s*\.\s*chr\s*\(\d+\)', 10, "String dirakit dari chr() berulang (obfuscation)"),
    (r'\$GLOBALS\s*\[\s*[\'"]\w+[\'"]\s*\]\s*\(', 10, "Pemanggilan fungsi dinamis lewat $GLOBALS"),
    (r'(token|passwd|password|secret|pass)\s*==\s*[\'"]\w{4,}[\'"]', 10, "Token/password hardcoded untuk gerbang akses (token-gated shell)"),
]

# Pola pada NAMA FILE / PATH yang mencurigakan
FILENAME_PATTERNS = [
    (r'\.(php|phtml|php\d?|pht)$', 0, None),  # baseline, no score, just to identify php-like ext
    (r'\.(PHP|PhP|pHp|pHP|Php)$', 8, "Ekstensi PHP huruf campuran (mixed-case) — teknik evasi"),
    (r'(webshell|backdoor|shell|c99|r57|wso|b374k|sadboy)', 20, "Nama file mengandung kata kunci webshell umum"),
    (r'^(mini|simple|cmd|info|uploader|b64|rce_|config[a-z0-9]{4,})\.', 12, "Nama file generik khas dropper/webshell"),
    (r'^[a-f0-9]{8,16}\.php$', 14, "Nama file hex/random (pola dropper otomatis)"),
    (r'(iconfont|assets[\\/]+font|fonts)[\\/].*\.php', 18, "File PHP di dalam folder font/iconfont — lokasi tidak wajar"),
]

# Folder yang secara default boleh diabaikan untuk mempercepat scan (opsional)
DEFAULT_SKIP_DIRS = {'.git', 'node_modules', 'vendor/composer'}

CODE_EXTENSIONS = {'.php', '.phtml', '.pht', '.php3', '.php4', '.php5', '.php7'}


def is_code_file(filename: str) -> bool:
    ext = os.path.splitext(filename)[1].lower()
    return ext in CODE_EXTENSIONS or re.search(r'\.ph[a-z0-9]{0,3}$', filename, re.IGNORECASE)


def scan_file(path: str):
    """Mengembalikan (score, findings_list) untuk satu file."""
    score = 0
    findings = []

    fname = os.path.basename(path)
    for regex, weight, label in FILENAME_PATTERNS:
        if label and re.search(regex, fname, re.IGNORECASE):
            score += weight
            findings.append(f"[NAMA FILE] {label}")

    norm_path = path.replace('\\', '/')
    for regex, weight, label in FILENAME_PATTERNS:
        if 'iconfont' in regex or 'fonts' in regex:
            if re.search(regex, norm_path, re.IGNORECASE):
                if f"[NAMA FILE] {label}" not in findings:
                    score += weight
                    findings.append(f"[PATH] {label}")

    try:
        with open(path, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
    except Exception as e:
        return 0, [f"[ERROR] Tidak bisa membaca file: {e}"]

    for regex, weight, label in PATTERNS:
        matches = re.findall(regex, content, re.IGNORECASE)
        if matches:
            score += weight
            count = len(matches)
            findings.append(f"[KODE] {label} (ditemukan {count}x)")

    # Penalti tambahan: file sangat kecil tapi padat fungsi berbahaya = ciri khas one-liner shell
    if len(content) < 500 and score >= 20:
        score += 10
        findings.append("[POLA] File sangat kecil namun berisi fungsi berbahaya (ciri one-liner webshell)")

    score = min(score, 100)
    return score, findings


def classify(score: int) -> str:
    if score >= 60:
        return "CRITICAL"
    elif score >= 35:
        return "HIGH"
    elif score >= 15:
        return "MEDIUM"
    elif score > 0:
        return "LOW"
    else:
        return "SAFE"


def scan_directory(root: str, min_score: int = 1, skip_dirs=None):
    skip_dirs = skip_dirs or DEFAULT_SKIP_DIRS
    results = []
    total_scanned = 0

    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in skip_dirs]
        for fn in filenames:
            if not is_code_file(fn):
                continue
            full_path = os.path.join(dirpath, fn)
            total_scanned += 1
            score, findings = scan_file(full_path)
            if score >= min_score:
                results.append((full_path, score, findings))

    results.sort(key=lambda x: x[1], reverse=True)
    return results, total_scanned


def write_report(results, total_scanned, out_path=None):
    lines = []
    lines.append("=" * 70)
    lines.append("LAPORAN SCAN WEBSHELL / BACKDOOR")
    lines.append("=" * 70)
    lines.append(f"Total file PHP diperiksa : {total_scanned}")
    lines.append(f"Total file mencurigakan  : {len(results)}")
    lines.append("")

    for path, score, findings in results:
        label = classify(score)
        lines.append(f"{path}")
        lines.append(f"{score} {label}")
        for f in findings:
            lines.append(f"  - {f}")
        lines.append("")

    report = "\n".join(lines)
    if out_path:
        with open(out_path, 'w', encoding='utf-8') as f:
            f.write(report)
        print(f"Laporan disimpan ke: {out_path}")
    else:
        print(report)


def main():
    parser = argparse.ArgumentParser(description="Scanner sederhana untuk mendeteksi webshell PHP.")
    parser.add_argument("path", help="Folder yang akan dipindai, mis. C:\\xampp\\htdocs\\afpweb")
    parser.add_argument("--min-score", type=int, default=10, help="Skor minimum agar file dimasukkan ke laporan (default: 10)")
    parser.add_argument("--out", default=None, help="Simpan laporan ke file (opsional)")
    args = parser.parse_args()

    if not os.path.isdir(args.path):
        print(f"Error: folder tidak ditemukan: {args.path}")
        sys.exit(1)

    print(f"Memindai: {args.path} ...")
    results, total_scanned = scan_directory(args.path, min_score=args.min_score)
    write_report(results, total_scanned, args.out)


if __name__ == "__main__":
    main()
