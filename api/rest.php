<?php
// Lecture publique (comme /rest/v1/<table> de Supabase) : seulement le catalogue, les réglages publics et les photos.
require __DIR__ . '/lib.php';
cors();
$t = (string)($_GET['t'] ?? '');
$allowed = [
  'site_catalog'    => ['cols' => ['key', 'name', 'price_da', 'kind', 'sort_order'], 'where' => 'active = 1', 'json' => []],
  'site_catalog_eu' => ['cols' => ['key', 'slug', 'name', 'price_eur', 'descr', 'specs', 'inbox', 'imgs', 'sort_order'], 'where' => 'active = 1', 'json' => ['specs', 'inbox', 'imgs']],
  'site_settings'   => ['cols' => ['key', 'val'], 'where' => "k IN ('eu_ship')", 'json' => ['val']],
  'site_img'        => ['cols' => ['id', 'data'], 'where' => '1', 'json' => []],
];
if (!isset($allowed[$t])) fail('not found', 404);
$A = $allowed[$t];
$colName = fn($c) => ($t === 'site_settings' && $c === 'key') ? 'k' : $c;

$sel = array_values(array_intersect(array_map('trim', explode(',', (string)($_GET['select'] ?? '*'))), $A['cols']));
if (!$sel) $sel = $A['cols'];
$where = [$A['where']]; $p = [];
foreach ($_GET as $k => $v) {
  if (in_array($k, ['t', 'select', 'order', 'limit'], true) || !in_array($k, $A['cols'], true) || !is_string($v)) continue;
  if (str_starts_with($v, 'eq.')) { $where[] = '`' . $colName($k) . '` = ?'; $p[] = substr($v, 3); }
  elseif (preg_match('/^in\.\((.*)\)$/', $v, $m)) {
    $vals = array_slice(array_filter(array_map(fn($x) => trim($x, " \""), explode(',', $m[1])), 'strlen'), 0, 100);
    if (!$vals) out([]);
    $where[] = '`' . $colName($k) . '` IN (' . implode(',', array_fill(0, count($vals), '?')) . ')'; $p = array_merge($p, $vals);
  }
}
$order = '';
if (preg_match('/^([a-z_]+)(\.desc)?/', (string)($_GET['order'] ?? ''), $m) && in_array($m[1], $A['cols'], true)) $order = ' ORDER BY `' . $colName($m[1]) . '`' . (isset($m[2]) ? ' DESC' : '');
$cols = implode(',', array_map(fn($c) => '`' . $colName($c) . '` AS `' . $c . '`', $sel));
$rows = q("SELECT $cols FROM $t WHERE " . implode(' AND ', $where) . $order . ' LIMIT 500', $p)->fetchAll();
foreach ($rows as &$r) foreach ($r as $c => &$v) {
  if (in_array($c, $A['json'], true)) $v = json_decode((string)$v, true);
  elseif (in_array($c, ['price_da', 'price_eur', 'sort_order'], true)) $v = (int)$v;
}
header('Cache-Control: public, max-age=60');
http_response_code(200); header('Content-Type: application/json; charset=utf-8');
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
