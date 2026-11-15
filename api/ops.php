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
function dhd_valid(string $tr): bool {
  [$ok, , $j] = dhd('/valid/order?' . http_build_query(['tracking' => $tr, 'ask_collection' => '0']), 'POST');
  if ($ok && ($j['success'] ?? true) !== false) return true;
  $GLOBALS['dhdValidErr'] = json_encode($j['message'] ?? $j['errors'] ?? $j, JSON_UNESCAPED_UNICODE);
  return false;
}
$act = (string)($b['action'] ?? '');

// ---------- créer le colis chez DHD (stock = 1 : DHD prend le produit dans ton stock) ----------
if ($act === 'dhd_send') {
  $o = one("SELECT * FROM site_orders WHERE id = ? AND deleted = 0", [(string)($b['id'] ?? '')]);
  if (!$o || is_eu($o)) fail('not_found', 404);
  if ($o['dhd_tracking']) out(['tracking' => $o['dhd_tracking']]);
  $map = (array)(setting('dhd_map') ?? []);
  $all = json_decode($o['items'], true) ?: [];
  $lines = array_values(array_filter($all, fn($i) => !empty($map[$i['key'] ?? ''])));
  $other = array_values(array_filter($all, fn($i) => empty($map[$i['key'] ?? '']) && !str_starts_with((string)($i['key'] ?? ''), 'promax')));
  if (!$lines && !$other) fail("Rien à livrer dans cette commande (abonnement seulement).");
  preg_match('/\d+/', (string)$o['wilaya'], $m); $wil = (int)($m[0] ?? 0);
  if ($wil < 1 || $wil > 58) fail('wilaya');
  $txt = fn($arr) => implode(' + ', array_map(fn($i) => max(1, (int)$i['qty']) . ' ' . $i['name'], $arr));
  $p = ['reference' => $o['order_no'], 'nom_client' => $o['name'], 'telephone' => preg_replace('/\D/', '', $o['phone']),
    'adresse' => $o['address'] ?: ($o['commune'] ?: '-'), 'commune' => (string)$o['commune'], 'code_wilaya' => (string)$wil,
    'montant' => (string)$o['total_da'], 'remarque' => mb_substr(trim(($other && $lines ? '+ ' . $txt($other) . ' · ' : '') . (string)$o['note']), 0, 250), 'type' => '1',
    'stop_desk' => $o['ship_type'] === 'office' ? '1' : '0'];
  if ($lines) { $p['stock'] = '1'; $p['produit'] = implode(',', array_map(fn($i) => $map[$i['key']], $lines)); $p['quantite'] = implode(',', array_map(fn($i) => (string)max(1, (int)$i['qty']), $lines)); }
  else $p['produit'] = mb_substr($txt($other), 0, 250);
  if ($o['phone2']) $p['telephone_2'] = preg_replace('/\D/', '', $o['phone2']);
  [$ok, $c, $j] = dhd('/create/order?' . http_build_query($p), 'POST');
  $tr = $j['tracking'] ?? $j['data']['tracking'] ?? null;
  if (!$ok || !$tr) fail('DHD : ' . json_encode($j['errors'] ?? $j['message'] ?? $j, JSON_UNESCAPED_UNICODE));
  // sur DHD, un colis créé doit être validé avant de partir : c'est Salima (ou le patron) qui valide ensuite (dhd_valid)
  q("UPDATE site_orders SET dhd_tracking = ?, dhd_status = 'a_valider', status = IF(status = 'new', 'confirmed', status), status_changed_at = UTC_TIMESTAMP(3) WHERE id = ?", [$tr, $o['id']]);
  out(['tracking' => $tr]);
}
// ---------- valider l'envoi d'un colis créé (bouton « Valider l'envoi ») ----------
if ($act === 'dhd_valid') {
  $o = one("SELECT id, dhd_tracking FROM site_orders WHERE id = ? AND dhd_tracking IS NOT NULL", [(string)($b['id'] ?? '')]);
  if (!$o) fail('not_found', 404);
  if (!dhd_valid($o['dhd_tracking'])) fail('DHD : ' . ($GLOBALS['dhdValidErr'] ?? 'validation refusée'));
  q("UPDATE site_orders SET dhd_status = 'prete_a_expedier' WHERE id = ?", [$o['id']]);
  out(['ok' => true]);
}

// ---------- mettre à jour les statuts des colis ----------
if ($act === 'dhd_sync') {
  $rows = q("SELECT id, dhd_tracking, dhd_status, status FROM site_orders WHERE dhd_tracking IS NOT NULL AND deleted = 0
             AND COALESCE(dhd_status, '') NOT IN ('a_valider', 'paye_et_archive', 'retour_archive', 'annule') LIMIT 300")->fetchAll();
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
