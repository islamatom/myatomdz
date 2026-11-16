<?php
// ATOM Europe — paiement en ligne (SumUp Hosted Checkout), en euros uniquement.
// Les clés sont collées par le patron dans le panel (🔑 → Clés) : elles ne sont jamais dans le site ni sur GitHub.
require __DIR__ . '/lib.php';
cors();
const SUMUP = 'https://api.sumup.com/v0.1/checkouts';
$KEY = secret('sumup_key'); $MERCHANT = secret('sumup_merchant');
if (!$KEY || !$MERCHANT) fail('not_configured', 503);

function http(string $method, string $url, array $headers, ?string $body = null): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $t = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code, json_decode((string)$t, true)];
}
function esc($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
// e-mail à Atom (Brevo) — ne bloque jamais le paiement
function mail_atom(string $subject, array $o, string $extra = ''): void {
  $to = secret('notify_email');
  if (!$to) return;
  $items = implode('', array_map(fn($i) => '<li>' . (int)$i['qty'] . ' × ' . esc($i['name']) . ' — ' . (int)$i['price'] . ' €</li>', json_decode($o['items'], true) ?: []));
  $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#111">' . $extra . '<h2 style="margin:0 0 8px">' . esc($o['order_no']) . ' · ' . esc($o['total_da']) . ' € TVA comprise</h2>'
    . '<p><b>' . esc($o['name']) . '</b> · ' . esc($o['phone']) . ($o['email'] ? ' · ' . esc($o['email']) : '') . '</p>'
    . '<p>📍 ' . esc(preg_replace('/^🇪🇺\s*/u', '', (string)$o['wilaya'])) . ' · ' . esc($o['commune']) . ' · ' . esc($o['address']) . '</p><ul>' . $items . '</ul>'
    . ($o['ship_fee'] ? '<p>Livraison : ' . esc($o['ship_fee']) . ' €</p>' : '') . '<p><a href="https://atomdz.com">Ouvrir le panel</a></p></div>';
  send_mail($to, 'Atom', $subject, $html);
}
// demande à SumUp l'état réel du paiement ; marque la commande payée si c'est le cas
function sync(string $cid): bool {
  global $KEY;
  [$code, $c] = http('GET', SUMUP . '/' . rawurlencode($cid), ['Authorization: Bearer ' . $KEY]);
  if ($code !== 200 || ($c['status'] ?? '') !== 'PAID') return false;
  $o = one("SELECT * FROM site_orders WHERE sumup_id = ?", [$cid]);
  if ($o && !$o['paid_at']) {
    $n = q("UPDATE site_orders SET paid_at = UTC_TIMESTAMP(3), status = IF(status = 'new', 'confirmed', status), status_changed_at = UTC_TIMESTAMP(3),
            note = LEFT(CONCAT('✅ PAYÉ en ligne · ', COALESCE(note, '')), 250) WHERE id = ? AND paid_at IS NULL", [$o['id']])->rowCount();
    if ($n) { $o2 = one("SELECT * FROM site_orders WHERE id = ?", [$o['id']]); if ($o2 && $o2['email']) mail_paid($o2); }
    if ($n) mail_atom("✅ Payée — {$o['order_no']} · {$o['total_da']} € · {$o['name']}", $o, '<p style="font-size:18px;color:#167a4e"><b>✅ Paiement reçu</b> — tu peux préparer le colis.</p>');
  }
  return true;
}

$b = body();
if (($_GET['hook'] ?? '') === '1') { if (is_string($b['id'] ?? null)) sync($b['id']); echo 'ok'; exit; }

$no = (string)($b['order_no'] ?? ''); $phone = preg_replace('/[^0-9+]/', '', (string)($b['phone'] ?? ''));
if (!preg_match('/^AT-\d{1,8}$/', $no) || strlen($phone) < 8) fail('bad');
$o = one("SELECT * FROM site_orders WHERE order_no = ? AND phone = ?", [$no, $phone]);
if (!$o || $o['currency'] !== 'EUR') fail('not_found', 404);

if (($b['action'] ?? '') === 'check') {
  $paid = (bool)$o['paid_at'] || ($o['sumup_id'] ? sync($o['sumup_id']) : false);
  out(['paid' => $paid, 'url' => $paid ? null : $o['sumup_url']]);
}
if ($o['paid_at']) out(['paid' => true]);
if ($o['sumup_url']) out(['url' => $o['sumup_url']]);

$items = json_decode($o['items'], true) ?: [];
$o_ = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = preg_match('#^https://(www\.)?(atomdz\.com|myatom\.dz|brown-yak-780163\.hostingersite\.com)$#', $o_) ? $o_ : 'https://atomdz.com';
$api = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'atomdz.com');
[$code, $c] = http('POST', SUMUP, ['Content-Type: application/json', 'Authorization: Bearer ' . $KEY], json_encode([
  'checkout_reference' => $o['order_no'] . '-' . substr($o['id'], 0, 8),
  'amount' => (float)$o['total_da'], 'currency' => 'EUR', 'merchant_code' => $MERCHANT,
  'description' => mb_substr('ATOM ' . $o['order_no'] . ' · ' . implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'], $items)), 0, 250),
  'redirect_url' => $host . '/eu/?cmd=' . rawurlencode($o['order_no']),
  'return_url' => $api . '/functions/v1/sumup-pay?hook=1',
  'hosted_checkout' => ['enabled' => true],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
if ($code >= 300 || empty($c['hosted_checkout_url'])) fail('sumup: ' . ($c['message'] ?? $c['error_code'] ?? $code), 502);
q("UPDATE site_orders SET sumup_id = ?, sumup_url = ? WHERE id = ?", [$c['id'], $c['hosted_checkout_url'], $o['id']]);
mail_atom("🇪🇺 Nouvelle commande {$o['order_no']} · {$o['total_da']} € · {$o['name']}", $o, '<p style="font-size:18px"><b>🇪🇺 Nouvelle commande Europe</b> — le client est sur la page de paiement.</p>');
out(['url' => $c['hosted_checkout_url']]);
