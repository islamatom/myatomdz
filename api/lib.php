<?php
// MyAtomDZ — serveur Hostinger (remplace Supabase). Les secrets sont dans ../atomdz-config.php (hors du site, jamais sur GitHub).
declare(strict_types=1);
$cfgFile = dirname($_SERVER['DOCUMENT_ROOT']) . '/atomdz-config.php';
if (!is_file($cfgFile)) $cfgFile = '/home/u124088486/atomdz-config.php';
if (!is_file($cfgFile)) { http_response_code(503); echo json_encode(['message' => 'not configured']); exit; }
$CFG = require $cfgFile;

function cors(): void {
  $o = $_SERVER['HTTP_ORIGIN'] ?? '';
  $ok = ['https://atomdz.com', 'https://www.atomdz.com', 'https://atom-gestion.pages.dev', 'https://brown-yak-780163.hostingersite.com', 'https://myatom.dz', 'https://www.myatom.dz'];
  if (in_array($o, $ok, true)) { header("Access-Control-Allow-Origin: $o"); header('Vary: Origin'); }
  header('Access-Control-Allow-Headers: authorization, apikey, content-type, x-client-info, prefer');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}
function out($data, int $code = 200): void { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): void { out(['message' => $msg, 'error' => $msg], $code); }
function body(): array { $j = json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($j) ? $j : []; }
function db(): PDO {
  static $pdo = null; global $CFG;
  if (!$pdo) {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $CFG['db_name'] . ';charset=utf8mb4', $CFG['db_user'], $CFG['db_pass'],
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+00:00'");
  }
  return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []) { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function uuid(): string { $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); }
function iso(?string $dt): ?string { return $dt ? gmdate('Y-m-d\TH:i:s', strtotime($dt . ' UTC')) . 'Z' : null; }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function cut($s, int $n): string { return mb_substr(trim((string)($s ?? '')), 0, $n); }

// rôle du code du panel : 'admin' (patron), 'staff' (Salima) ou null ; 10 essais ratés = bloqué 15 min
function panel_role($code): ?string {
  $bad = (int) one("SELECT COUNT(*) c FROM panel_attempts WHERE ok = 0 AND at > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)")['c'];
  if ($bad >= 10) return null;
  $s = one("SELECT code_hash, staff_hash FROM panel_settings WHERE id = 1");
  $c = (string)($code ?? ''); $r = null;
  if ($s && $c !== '' && password_verify($c, $s['code_hash'])) $r = 'admin';
  elseif ($s && $c !== '' && $s['staff_hash'] && password_verify($c, $s['staff_hash'])) $r = 'staff';
  q("INSERT INTO panel_attempts (at, ok) VALUES (UTC_TIMESTAMP(), ?)", [$r ? 1 : 0]);
  if (random_int(1, 50) === 1) q("DELETE FROM panel_attempts WHERE at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)");
  return $r;
}
function is_eu(array $o): bool { return ($o['currency'] ?? '') === 'EUR' || str_starts_with((string)($o['wilaya'] ?? ''), '🇪🇺'); }
function next_order_no(): string {
  q("UPDATE counters SET val = LAST_INSERT_ID(val + 1) WHERE name = 'order_no'");
  return 'AT-' . db()->lastInsertId();
}
function setting(string $k) { $r = one("SELECT val FROM site_settings WHERE k = ?", [$k]); return $r ? json_decode($r['val'], true) : null; }
// clé privée : d'abord celle collée dans le panel (table site_secrets), sinon celle du fichier de config
function secret(string $k): string { global $CFG; $r = one("SELECT val FROM site_secrets WHERE k = ?", [$k]); return $r ? (string)$r['val'] : (string)($CFG[$k] ?? ''); }
