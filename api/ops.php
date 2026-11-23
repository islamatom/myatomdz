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

// ---------- Europe : étiquette Mondial Relay (API Shipment 1.0) ----------
if ($act === 'mr_label') {
  $o = one("SELECT * FROM site_orders WHERE id = ? AND deleted = 0", [(string)($b['id'] ?? '')]);
  if (!$o || !is_eu($o)) fail('not_found', 404);
  if (!$o['paid_at']) fail("Commande pas encore payée : pas d'étiquette.");
  if ($o['mr_tracking'] && $o['mr_label']) out(['tracking' => $o['mr_tracking'], 'label' => $o['mr_label']]);
  if (!$o['relay_id']) fail("Pas de Point Relais choisi pour cette commande.");
  $L = secret('mr_login'); $P = secret('mr_password'); $C = secret('mr_customer');
  if (!$L || !$P || !$C) fail('Identifiants Mondial Relay manquants (page des clés).', 503);
  $x = fn($v) => htmlspecialchars(trim((string)$v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
  $COUNTRY = ['Belgique'=>'BE','France'=>'FR','Pays-Bas'=>'NL','Luxembourg'=>'LU','Allemagne'=>'DE','Espagne'=>'ES','Italie'=>'IT','Portugal'=>'PT','Suisse'=>'CH','Autriche'=>'AT','Irlande'=>'IE','Danemark'=>'DK','Suède'=>'SE','Norvège'=>'NO','Finlande'=>'FI','Pologne'=>'PL','Tchéquie'=>'CZ','Grèce'=>'GR','Roumanie'=>'RO','Hongrie'=>'HU','Royaume-Uni'=>'GB'];
  $cname = trim(preg_replace('/^🇪🇺\s*/u', '', (string)$o['wilaya']));
  $cc = $COUNTRY[$cname] ?? substr((string)$o['relay_id'], 0, 2);
  $addr = trim((string)$o['address']); $zip = ''; if (preg_match('/^(\S+)\s+(.*)$/u', $addr, $m) && preg_match('/\d/', $m[1])) { $zip = $m[1]; $addr = $m[2]; }
  $parts = preg_split('/\s+/', trim((string)$o['name']), 2); $fn = $parts[0]; $ln = $parts[1] ?? $parts[0];
  $items = json_decode($o['items'], true) ?: []; $n = array_sum(array_map(fn($i) => max(1, (int)$i['qty']), $items)) ?: 1;
  $xml = '<?xml version="1.0" encoding="utf-8"?>'
    . '<ShipmentCreationRequest xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns="http://www.example.org/Request">'
    . '<Context><Login>' . $x($L) . '</Login><Password>' . $x($P) . '</Password><CustomerId>' . $x($C) . '</CustomerId><Culture>fr-FR</Culture><VersionAPI>1.0</VersionAPI></Context>'
    . '<OutputOptions><OutputFormat>10x15</OutputFormat><OutputType>PdfUrl</OutputType></OutputOptions>'
    . '<ShipmentsList><Shipment><OrderNo>' . $x($o['order_no']) . '</OrderNo><CustomerNo>' . $x(substr(preg_replace('/\D/', '', $o['phone']), -9)) . '</CustomerNo><ParcelCount>1</ParcelCount>'
    . '<DeliveryMode Mode="24R" Location="' . $x($o['relay_id']) . '" /><CollectionMode Mode="REL" Location="" />'
    . '<Parcels><Parcel><Content>' . $x('Boitier TV x' . $n) . '</Content><Weight Value="' . (700 * $n) . '" Unit="gr" /></Parcel></Parcels>'
    . '<DeliveryInstruction></DeliveryInstruction>'
    . '<Sender><Address><Title /><Firstname /><Lastname /><Streetname>Rue du Commerce</Streetname><HouseNo>9</HouseNo><CountryCode>BE</CountryCode><PostCode>5300</PostCode><City>Andenne</City>'
    . '<AddressAdd1>ATOM</AddressAdd1><AddressAdd2 /><AddressAdd3 /><PhoneNo /><MobileNo>+213540290775</MobileNo><Email>' . $x(secret('smtp_user') ?: 'info@atomdz.com') . '</Email></Address></Sender>'
    . '<Recipient><Address><Title /><Firstname>' . $x($fn) . '</Firstname><Lastname>' . $x($ln) . '</Lastname><Streetname>' . $x($addr ?: '-') . '</Streetname><HouseNo /><CountryCode>' . $x($cc) . '</CountryCode>'
    . '<PostCode>' . $x($zip ?: '0000') . '</PostCode><City>' . $x($o['commune'] ?: '-') . '</City><AddressAdd1 /><AddressAdd2 /><AddressAdd3 /><PhoneNo /><MobileNo>' . $x($o['phone']) . '</MobileNo><Email>' . $x($o['email']) . '</Email></Address></Recipient>'
    . '</Shipment></ShipmentsList></ShipmentCreationRequest>';
  $ch = curl_init('https://connect-api.mondialrelay.com/api/Shipment');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => $xml,
    CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'Accept: application/xml']]);
  $res = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  preg_match('/ShipmentNumber="([^"]+)"/', $res, $sn);
  preg_match('#<Output>([^<]+)</Output>#', $res, $lab);
  if (!$sn || !$lab) {
    preg_match_all('/Message="([^"]+)"/', $res, $msgs);
    fail('Mondial Relay : ' . ($msgs[1] ? implode(' · ', array_unique($msgs[1])) : ('réponse ' . $code . ' ' . mb_substr(strip_tags($res), 0, 200))));
  }
  $url = html_entity_decode($lab[1]);
  q("UPDATE site_orders SET mr_tracking = ?, mr_label = ? WHERE id = ?", [$sn[1], $url, $o['id']]);
  out(['tracking' => $sn[1], 'label' => $url]);
}

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
