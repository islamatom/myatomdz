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

// e-mail : par Brevo si la clé est collée sur /cles/, sinon par le serveur Hostinger. Ne bloque jamais rien.
function send_mail(string $to, string $toName, string $subject, string $html): bool {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  // 1) adresse pro Hostinger (ex. commande@atomdz.com) : SMTP
  $su = secret('smtp_user'); $sp = secret('smtp_pass');
  if ($su && $sp) return smtp_send($su, $sp, $to, $toName, $subject, $html);
  $bk = secret('brevo_key'); $from = secret('brevo_sender') ?: 'commande@atomdz.com';
  if ($bk) {
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
      CURLOPT_HTTPHEADER => ['api-key: ' . $bk, 'Content-Type: application/json', 'Accept: application/json'],
      CURLOPT_POSTFIELDS => json_encode(['sender' => ['name' => 'ATOM', 'email' => $from], 'to' => [['email' => $to, 'name' => $toName]],
        'replyTo' => ['email' => $from], 'subject' => $subject, 'htmlContent' => $html], JSON_UNESCAPED_UNICODE)]);
    curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $c >= 200 && $c < 300;
  }
  $h = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: ATOM <$from>\r\nReply-To: $from\r\n";
  return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $h, '-f' . $from);
}
function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
// e-mail au client Europe : « votre colis est parti »
function mail_shipped(array $o): bool {
  $items = implode('', array_map(fn($i) => '<li>' . (int)$i['qty'] . ' × ' . h($i['name']) . '</li>', json_decode($o['items'], true) ?: []));
  $first = h(explode(' ', trim((string)$o['name']))[0]);
  $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#111;max-width:560px;margin:auto">'
    . '<div style="background:#2c4fbf;color:#fff;padding:18px 20px;border-radius:14px 14px 0 0"><b style="font-size:20px">AT✻M</b></div>'
    . '<div style="border:1px solid #e5e7eb;border-top:0;padding:20px;border-radius:0 0 14px 14px">'
    . '<h2 style="margin:0 0 12px">📦 Votre colis est en route !</h2><p>Bonjour ' . $first . ',</p>'
    . '<p>Bonne nouvelle : votre commande <b>' . h($o['order_no']) . '</b> vient de partir. Vous la recevrez dans <b>2 à 3 jours</b>.</p>'
    . '<ul>' . $items . '</ul><p>Adresse de livraison : ' . h($o['address']) . ', ' . h($o['commune']) . ' (' . h(preg_replace('/^🇪🇺\s*/u', '', (string)$o['wilaya'])) . ')</p>'
    . '<p>Une question ? Écrivez-nous sur WhatsApp : <a href="https://wa.me/32499374830">+32 499 37 48 30</a></p>'
    . '<p style="color:#666;font-size:13px">Merci pour votre confiance — ATOM · atomdz.com/eu</p></div></div>';
  return send_mail((string)$o['email'], (string)$o['name'], '📦 Votre commande ' . $o['order_no'] . ' est en route', $html);
}

// envoi SMTP par la boîte pro Hostinger (smtp.hostinger.com, port 465 SSL)
function smtp_send(string $user, string $pass, string $to, string $toName, string $subject, string $html): bool {
  $fp = @stream_socket_client('ssl://smtp.hostinger.com:465', $en, $es, 15);
  if (!$fp) return false;
  stream_set_timeout($fp, 15);
  $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
  $cmd = function (string $c, string $ok) use ($fp, $read) { fwrite($fp, $c . "\r\n"); $r = $read(); return strncmp($r, $ok, 3) === 0; };
  $enc = fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
  $okAll = strncmp($read(), '220', 3) === 0
    && $cmd('EHLO atomdz.com', '250') && $cmd('AUTH LOGIN', '334') && $cmd(base64_encode($user), '334') && $cmd(base64_encode($pass), '235')
    && $cmd("MAIL FROM:<$user>", '250') && $cmd("RCPT TO:<$to>", '250') && $cmd('DATA', '354');
  if ($okAll) {
    $msg = 'From: ' . $enc('ATOM') . " <$user>\r\nTo: " . $enc($toName) . " <$to>\r\nReply-To: $user\r\nSubject: " . $enc($subject)
      . "\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@' . substr(strrchr($user, '@'), 1) . ">\r\nMIME-Version: 1.0\r\n"
      . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n.";
    $okAll = $cmd($msg, '250');
  }
  @fwrite($fp, "QUIT\r\n"); fclose($fp);
  return $okAll;
}
