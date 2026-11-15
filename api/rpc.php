<?php
// Les mêmes fonctions qu'avant sur Supabase (/rest/v1/rpc/<nom>), même réponses : le site et le panel ne changent pas.
require __DIR__ . '/lib.php';
cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);
$fn = preg_replace('/[^a-z_]/', '', (string)($_GET['fn'] ?? ''));
$b = body();

function order_json(array $o): array {
  return ['id' => $o['id'], 'no' => $o['order_no'], 'date' => iso($o['created_at']), 'status' => $o['status'], 'changed' => iso($o['status_changed_at']),
    'name' => $o['name'], 'phone' => $o['phone'], 'phone2' => $o['phone2'], 'wilaya' => $o['wilaya'], 'commune' => $o['commune'], 'address' => $o['address'],
    'note' => $o['note'], 'items' => json_decode($o['items'], true) ?: [], 'ship' => $o['ship_type'], 'fee' => (int)$o['ship_fee'], 'device' => $o['device'],
    'renew' => (bool)$o['renew'], 'total' => (int)$o['total_da'], 'cur' => $o['currency'], 'email' => $o['email'], 'paid' => iso($o['paid_at']), 'mailed' => iso($o['mailed_at'] ?? null),
    'dhd' => $o['dhd_tracking'], 'dhd_status' => $o['dhd_status']];
}
function too_many(string $phone): bool {
  return (int) one("SELECT COUNT(*) c FROM site_orders WHERE phone = ? AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)", [$phone])['c'] >= 3;
}
function insert_order(array $f): array {
  $f['id'] = uuid(); $f['order_no'] = next_order_no(); $f['created_at'] = now();
  $cols = array_keys($f);
  q('INSERT INTO site_orders (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($f));
  return $f;
}
function lines_from(array $items, string $table, string $priceCol): array {
  $lines = []; $sub = 0;
  foreach ($items as $it) {
    if (!is_array($it)) continue;
    $c = one("SELECT `key`, name, $priceCol p FROM $table WHERE `key` = ? AND active = 1", [(string)($it['key'] ?? '')]);
    if (!$c) continue;
    $qty = max(1, min(10, (int)($it['qty'] ?? 1)));
    $lines[] = ['key' => $c['key'], 'name' => $c['name'], 'qty' => $qty, 'price' => (int)$c['p']];
    $sub += (int)$c['p'] * $qty;
  }
  if (!$lines) fail('empty');
  return [$lines, $sub];
}
function admin($code): void { if (panel_role($code) !== 'admin') out(false); }

switch ($fn) {

  // ---------- le site algérien envoie une commande ----------
  case 'submit_site_order': {
    $name = cut($b['p_name'] ?? '', 80); $phone = (string)($b['p_phone'] ?? '');
    if (mb_strlen($name) < 2) fail('name');
    if (!preg_match('/^0[2-7][0-9]{8}$/', $phone)) fail('phone');
    if (trim((string)($b['p_wilaya'] ?? '')) === '') fail('wilaya');
    if (too_many($phone)) fail('too many orders');
    [$lines, $sub] = lines_from((array)($b['p_items'] ?? []), 'site_catalog', 'price_da');
    $st = in_array($b['p_ship_type'] ?? null, ['home', 'office'], true) ? $b['p_ship_type'] : null;
    $fee = $st ? max(0, min(2500, (int)($b['p_ship_fee'] ?? 0))) : 0;
    $o = insert_order(['name' => $name, 'phone' => $phone, 'phone2' => cut($b['p_phone2'] ?? '', 16) ?: null,
      'wilaya' => cut($b['p_wilaya'], 60), 'commune' => cut($b['p_commune'] ?? '', 60), 'address' => cut($b['p_address'] ?? '', 200),
      'note' => cut($b['p_note'] ?? '', 300), 'items' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'ship_type' => $st, 'ship_fee' => $fee,
      'device' => in_array($b['p_device'] ?? null, ['box', 'other'], true) ? $b['p_device'] : null, 'renew' => empty($b['p_renew']) ? 0 : 1,
      'total_da' => $sub + $fee, 'currency' => 'DA']);
    out(['order_no' => $o['order_no'], 'total_da' => $o['total_da']]);
  }

  // ---------- portail Europe ----------
  case 'submit_eu_order': {
    $name = cut($b['p_name'] ?? '', 80); $ph = preg_replace('/[^0-9+]/', '', (string)($b['p_phone'] ?? ''));
    if (mb_strlen($name) < 2) fail('name');
    if (!preg_match('/^\+?[0-9]{8,15}$/', $ph)) fail('phone');
    if (trim((string)($b['p_country'] ?? '')) === '' || trim((string)($b['p_address'] ?? '')) === '') fail('address');
    $email = trim((string)($b['p_email'] ?? ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? cut($email, 120) : null;   // obligatoire dans le formulaire
    if (too_many($ph)) fail('too many orders');
    [$lines, $sub] = lines_from((array)($b['p_items'] ?? []), 'site_catalog_eu', 'price_eur');
    $sv = setting('eu_ship'); $fee = is_numeric($sv) ? max(0, (int)$sv) : 0;
    $o = insert_order(['name' => $name, 'phone' => mb_substr($ph, 0, 16), 'email' => $email, 'wilaya' => '🇪🇺 ' . cut($b['p_country'], 40),
      'commune' => cut($b['p_city'] ?? '', 60), 'address' => cut(trim(($b['p_zip'] ?? '') . ' ' . $b['p_address']), 200),
      'note' => '💳 Paiement en ligne' . ($email ? ' · ' . $email : ''), 'items' => json_encode($lines, JSON_UNESCAPED_UNICODE),
      'ship_type' => 'home', 'ship_fee' => $fee, 'total_da' => $sub + $fee, 'currency' => 'EUR']);
    out(['order_no' => $o['order_no'], 'total' => $o['total_da']]);
  }

  case 'track_site_event': {
    q("INSERT INTO site_events (at, visitor, event, item, source, device, lang) VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)",
      [cut($b['p_visitor'] ?? '', 40), cut($b['p_event'] ?? '', 20), cut($b['p_item'] ?? '', 40), cut($b['p_source'] ?? '', 40), cut($b['p_device'] ?? '', 10), cut($b['p_lang'] ?? '', 4)]);
    out(null);
  }

  // ---------- panel (patron 121204… ou Salima) ----------
  case 'panel_whoami': out(panel_role($b['p_code'] ?? '') ?? 'none');

  case 'panel_orders': {
    if (!panel_role($b['p_code'] ?? '')) out(['error' => 'bad code']);
    $rows = q("SELECT * FROM site_orders WHERE deleted = 0 ORDER BY created_at DESC LIMIT 3000")->fetchAll();
    out(array_map('order_json', $rows));
  }
  case 'panel_set_status': {
    if (!panel_role($b['p_code'] ?? '')) out(false);
    $st = (string)($b['p_status'] ?? '');
    if (!in_array($st, ['new', 'confirmed', 'postponed', 'noanswer', 'cancelled', 'delivered'], true)) fail('status');
    q("UPDATE site_orders SET status = ?, status_changed_at = UTC_TIMESTAMP(3) WHERE id = ?", [$st, (string)($b['p_id'] ?? '')]);
    // Europe payée marquée « Envoyée » → e-mail au client (une seule fois)
    if ($st === 'delivered') {
      $o = one("SELECT * FROM site_orders WHERE id = ?", [(string)($b['p_id'] ?? '')]);
      if ($o && is_eu($o) && $o['paid_at'] && $o['email'] && !$o['mailed_at'] && mail_shipped($o)) q("UPDATE site_orders SET mailed_at = UTC_TIMESTAMP(3) WHERE id = ?", [$o['id']]);
    }
    out(true);
  }
  case 'panel_set_fee': {
    if (!panel_role($b['p_code'] ?? '')) out(false);
    $f = max(0, min(100000, (int)($b['p_fee'] ?? 0)));
    q("UPDATE site_orders SET total_da = total_da - ship_fee + ?, ship_fee = ?, ship_type = COALESCE(ship_type, 'home') WHERE id = ?", [$f, $f, (string)($b['p_id'] ?? '')]);
    out(true);
  }
  case 'panel_delete': {
    admin($b['p_code'] ?? '');
    q("UPDATE site_orders SET deleted = 1 WHERE id = ?", [(string)($b['p_id'] ?? '')]);
    out(true);
  }
  case 'panel_change_code': {
    admin($b['p_code'] ?? '');
    $n = (string)($b['p_new'] ?? '');
    if (!preg_match('/^[0-9]{6}$/', $n)) out(false);
    $s = one("SELECT staff_hash FROM panel_settings WHERE id = 1");
    if ($s && $s['staff_hash'] && password_verify($n, $s['staff_hash'])) out(false);
    q("UPDATE panel_settings SET code_hash = ? WHERE id = 1", [password_hash($n, PASSWORD_BCRYPT)]);
    out(true);
  }
  case 'panel_set_staff_code': {
    admin($b['p_code'] ?? '');
    $n = (string)($b['p_new'] ?? '');
    if (!preg_match('/^[0-9]{6}$/', $n) || $n === (string)$b['p_code']) out(false);
    q("UPDATE panel_settings SET staff_hash = ? WHERE id = 1", [password_hash($n, PASSWORD_BCRYPT)]);
    out(true);
  }

  // ---------- catalogue Europe (🛒 dans le panel) ----------
  case 'panel_eu_list': {
    admin($b['p_code'] ?? '');
    $items = array_map(fn($c) => ['key' => $c['key'], 'slug' => $c['slug'], 'name' => $c['name'], 'price_eur' => (int)$c['price_eur'], 'descr' => $c['descr'],
      'specs' => json_decode($c['specs'] ?? '[]', true) ?: [], 'inbox' => json_decode($c['inbox'] ?? '[]', true) ?: [], 'imgs' => json_decode($c['imgs'] ?? '[]', true) ?: [],
      'sort_order' => (int)$c['sort_order'], 'active' => (bool)$c['active']], q("SELECT * FROM site_catalog_eu ORDER BY sort_order, `key`")->fetchAll());
    out(['ship' => setting('eu_ship'), 'items' => $items]);
  }
  case 'panel_eu_save': {
    admin($b['p_code'] ?? '');
    $k = (string)($b['p_key'] ?? ''); $nm = cut($b['p_name'] ?? '', 60);
    if (!preg_match('/^eu_[a-z0-9_]{1,40}$/', $k) || mb_strlen($nm) < 2) fail('bad');
    $j = fn($v) => json_encode(is_array($v) ? array_values($v) : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    q("INSERT INTO site_catalog_eu (`key`, slug, name, price_eur, descr, specs, inbox, imgs, active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE name = VALUES(name), price_eur = VALUES(price_eur), descr = VALUES(descr), specs = VALUES(specs), inbox = VALUES(inbox),
       imgs = VALUES(imgs), active = VALUES(active), sort_order = VALUES(sort_order), slug = COALESCE(slug, VALUES(slug))",
      [$k, substr($k, 3), $nm, max(0, min(100000, (int)($b['p_price'] ?? 0))), cut($b['p_descr'] ?? '', 160), $j($b['p_specs'] ?? []), $j($b['p_inbox'] ?? []),
       $j($b['p_imgs'] ?? []), ($b['p_active'] ?? true) ? 1 : 0, (int)($b['p_sort'] ?? 99)]);
    out(true);
  }
  case 'panel_eu_delete': {
    admin($b['p_code'] ?? '');
    q("DELETE FROM site_catalog_eu WHERE `key` = ?", [(string)($b['p_key'] ?? '')]);
    out(true);
  }
  case 'panel_img_add': {
    admin($b['p_code'] ?? '');
    $d = (string)($b['p_data'] ?? '');
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $d) || strlen($d) > 600000) fail('bad image');
    $id = uuid(); q("INSERT INTO site_img (id, data, created_at) VALUES (?, ?, UTC_TIMESTAMP())", [$id, $d]);
    out($id);
  }
  case 'panel_set_setting': {
    admin($b['p_code'] ?? '');
    if (($b['p_key'] ?? '') !== 'eu_ship') fail('bad');
    q("REPLACE INTO site_settings (k, val) VALUES ('eu_ship', ?)", [json_encode($b['p_val'] ?? null)]);
    out(true);
  }

  // ---------- clés privées (SumUp, Brevo, DHD) : le patron les colle lui-même, jamais relues ----------
  case 'panel_set_secret': {
    admin($b['p_code'] ?? '');
    $k = (string)($b['p_name'] ?? ''); $v = trim((string)($b['p_val'] ?? ''));
    if (!in_array($k, ['sumup_key', 'sumup_merchant', 'brevo_key', 'brevo_sender', 'notify_email', 'dhd_token', 'smtp_user', 'smtp_pass'], true) || strlen($v) > 600) fail('bad');
    if ($v === '') q("DELETE FROM site_secrets WHERE k = ?", [$k]); else q("REPLACE INTO site_secrets (k, val) VALUES (?, ?)", [$k, $v]);
    out(true);
  }
  case 'panel_secrets_status': {   // dit seulement quelles clés sont remplies, jamais leur valeur
    admin($b['p_code'] ?? '');
    out(array_column(q("SELECT k FROM site_secrets")->fetchAll(), 'k'));
  }
}
fail('unknown function', 404);
