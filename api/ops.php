<?php
// Actions du panel qui parlent à DHD (ECOTRACK) : stock, envoi du colis (pris dans le stock chez DHD), suivi.
// Le token DHD est collé par le patron sur /cles/ : jamais dans le site ni sur GitHub.
require __DIR__ . '/lib.php';
cors();
const DHD = 'https://platform.dhd-dz.com/api/v1';
$b = body();
$role = panel_role($b['code'] ?? '');
if (!$role) fail('code', 401);
$TOKEN = secret('dhd_token');
if (!$TOKEN) fail('dhd_not_configured', 503);

function dhd(string $path, string $method = 'GET'): array {
  global $TOKEN;
  $ch = curl_init(DHD . $path);
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $TOKEN, 'Accept: application/json']]);
  $t = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $j = json_decode($t, true);
  return [$c >= 200 && $c < 300, $c, is_array($j) ? $j : ['raw' => mb_substr($t, 0, 300)]];
}
$act = (string)($b['action'] ?? '');

// ---------- stock chez DHD + liens produits du site ↔ produits DHD ----------
if ($act === 'dhd_stock') {
  [$ok, $c, $j] = dhd('/get/products/list');
  if (!$ok) fail('dhd ' . $c . ' ' . json_encode($j['message'] ?? $j, JSON_UNESCAPED_UNICODE), 502);
  $site = q("SELECT `key`, name FROM site_catalog WHERE active = 1 ORDER BY sort_order")->fetchAll();
  out(['products' => $j['products'] ?? $j['data'] ?? $j, 'map' => setting('dhd_map') ?? new stdClass, 'site' => $site]);
}
if ($act === 'dhd_map') {
  if ($role !== 'admin') fail('admin', 403);
  $m = []; foreach ((array)($b['map'] ?? []) as $k => $v) if (preg_match('/^[a-z0-9_]{1,60}$/', (string)$k) && trim((string)$v) !== '') $m[$k] = cut($v, 60);
  q("REPLACE INTO site_settings (k, val) VALUES ('dhd_map', ?)", [json_encode($m ?: new stdClass, JSON_UNESCAPED_UNICODE)]);
  out(['ok' => true]);
}

// ---------- créer le colis chez DHD (stock = 1 : DHD prend le produit dans ton stock) ----------
if ($act === 'dhd_send') {
  $o = one("SELECT * FROM site_orders WHERE id = ? AND deleted = 0", [(string)($b['id'] ?? '')]);
  if (!$o || is_eu($o)) fail('not_found', 404);
  if ($o['dhd_tracking']) out(['tracking' => $o['dhd_tracking']]);
  $map = (array)(setting('dhd_map') ?? []);
  $lines = array_values(array_filter(json_decode($o['items'], true) ?: [], fn($i) => !empty($map[$i['key'] ?? ''])));
  if (!$lines) fail("Aucun produit de cette commande n'est relié au stock DHD (bouton 📦 Stock DHD).");
  preg_match('/\d+/', (string)$o['wilaya'], $m); $wil = (int)($m[0] ?? 0);
  if ($wil < 1 || $wil > 58) fail('wilaya');
  $p = ['reference' => $o['order_no'], 'nom_client' => $o['name'], 'telephone' => preg_replace('/\D/', '', $o['phone']),
    'adresse' => $o['address'] ?: ($o['commune'] ?: '-'), 'commune' => (string)$o['commune'], 'code_wilaya' => (string)$wil,
    'montant' => (string)$o['total_da'], 'remarque' => mb_substr((string)$o['note'], 0, 250), 'type' => '1',
    'stop_desk' => $o['ship_type'] === 'office' ? '1' : '0', 'stock' => '1',
    'produit' => implode(',', array_map(fn($i) => $map[$i['key']], $lines)), 'quantite' => implode(',', array_map(fn($i) => (string)max(1, (int)$i['qty']), $lines))];
  if ($o['phone2']) $p['telephone_2'] = preg_replace('/\D/', '', $o['phone2']);
  [$ok, $c, $j] = dhd('/create/order?' . http_build_query($p), 'POST');
  $tr = $j['tracking'] ?? $j['data']['tracking'] ?? null;
  if (!$ok || !$tr) fail('DHD : ' . json_encode($j['errors'] ?? $j['message'] ?? $j, JSON_UNESCAPED_UNICODE));
  q("UPDATE site_orders SET dhd_tracking = ?, dhd_status = 'prete_a_expedier', status = IF(status = 'new', 'confirmed', status), status_changed_at = UTC_TIMESTAMP(3) WHERE id = ?", [$tr, $o['id']]);
  out(['tracking' => $tr]);
}

// ---------- mettre à jour les statuts des colis ----------
if ($act === 'dhd_sync') {
  $rows = q("SELECT id, dhd_tracking, dhd_status, status FROM site_orders WHERE dhd_tracking IS NOT NULL AND deleted = 0
             AND COALESCE(dhd_status, '') NOT IN ('paye_et_archive', 'retour_archive', 'annule') LIMIT 300")->fetchAll();
  $changed = 0;
  foreach (array_chunk($rows, 100) as $part) {
    [$ok, , $j] = dhd('/get/orders/status?' . http_build_query(['api_token' => $GLOBALS['TOKEN'], 'trackings' => implode(',', array_column($part, 'dhd_tracking')), 'status' => 'all']));
    $data = $j['data'] ?? [];
    foreach ($part as $x) {
      $st = $data[$x['dhd_tracking']]['status'] ?? null;
      if (!$st || $st === $x['dhd_status']) continue;
      $del = preg_match('/^(livre|encaisse|paiement|paye)/', $st) && $x['status'] !== 'delivered';
      q("UPDATE site_orders SET dhd_status = ?" . ($del ? ", status = 'delivered', status_changed_at = UTC_TIMESTAMP(3)" : '') . " WHERE id = ?", [$st, $x['id']]);
      $changed++;
    }
  }
  out(['ok' => true, 'changed' => $changed]);
}
fail('action');
