<?php
declare(strict_types=1);
// Tes fungsional API transaksi via HTTP (port 8768)

$BASE = 'http://127.0.0.1:8768/api';
$PASS = 0; $FAIL = 0;

function http(string $url, array $data = [], string $method = 'POST', string $token = ''): array {
    $opts = ['http' => ['method' => $method, 'header' => "Content-Type: application/json\r\n" . ($token ? "Authorization: Bearer $token\r\n" : ''), 'content' => json_encode($data), 'timeout' => 5, 'ignore_errors' => true]];
    if ($method === 'GET' && $data) { $url .= (strpos($url,'?')!==false?'&':'?') . http_build_query($data); unset($opts['http']['content']); }
    $res = file_get_contents($url, false, stream_context_create($opts));
    return json_decode($res ?: '{}', true) ?? [];
}

function check(string $label, bool $cond): void {
    global $PASS, $FAIL;
    if ($cond) { echo "  ✅ $label\n"; $PASS++; } else { echo "  ❌ $label\n"; $FAIL++; }
}

// ── Login dulu ────────────────────────────────────────────────────────────────
echo "\n=== Login untuk dapat token ===\n";
$r = http("$BASE/auth.php?action=logout", ['username'=>'Group A','machine'=>'Mesin BHP 1']);
$r = http("$BASE/auth.php?action=login", ['username'=>'Group A','password'=>'12345','machine'=>'Mesin BHP 1']);
check('login sukses', $r['success'] === true);
$TOKEN = $r['data']['token'] ?? '';
echo "  Token: " . substr($TOKEN,0,8) . "...\n";

// ── Init data ─────────────────────────────────────────────────────────────────
echo "\n=== GET init.php ===\n";
$r = http("$BASE/init.php", [], 'GET', $TOKEN);
check('success=true',          $r['success'] === true);
check('ada suppliers.order',   is_array($r['data']['suppliers']['order'] ?? null));
check('ada conversion',        is_array($r['data']['conversion'] ?? null));
check('ada config.mesin',      is_array($r['data']['config']['mesin'] ?? null));

// ── Settings ──────────────────────────────────────────────────────────────────
echo "\n=== GET settings.php ===\n";
$r = http("$BASE/settings.php", [], 'GET', $TOKEN);
check('success=true',          $r['success'] === true);
check('ada enableHandover',    isset($r['data']['enableHandover']));

// ── Submit transaksi ──────────────────────────────────────────────────────────
echo "\n=== POST transactions.php?action=submit ===\n";
$materials = [
    ['name'=>'MATERIAL TEST A','supplier'=>'Supplier X','stockAwal'=>100,'masuk'=>50,'retur'=>5,'reject'=>3,
     'hours'=>[10,12,8,9,11,13,14,7],'photos'=>[]],
    ['name'=>'MATERIAL TEST B','supplier'=>'','stockAwal'=>200,'masuk'=>0,'retur'=>0,'reject'=>0,
     'hours'=>[5,5,5,5,5,5,5,5],'photos'=>[]],
];
$outputs = [
    ['mid'=>'TEST001','name'=>'PRODUK TEST','catBag'=>'CAT A','catBox'=>'CAT B','qtyBox'=>10,
     'counterPcs'=>1000,'totalKg'=>50.5,'lossKg'=>1.2]
];
$report = ['counterKg'=>'50.50','lossKg'=>'1.20','lossPct'=>'2.38',
           'rejectPrintingKg'=>0.5,'speed'=>450,'downtimeMin'=>30,'downtimePct'=>'6.25%',
           'trouble'=>'Test trouble','nearMiss'=>'','notes'=>'Test notes',
           'itemsDetail'=>[['mid'=>'TEST001','counterPcs'=>1000,'totalKg'=>50.5,'lossKg'=>1.2]]];

$payload = [
    'tanggal'      => date('Y-m-d'),
    'shift'        => '1',
    'mesin'        => 'Mesin BHP 1',
    'size'         => 'Size M',
    'materialsJson' => json_encode($materials),
    'outputsJson'   => json_encode($outputs),
    'reportJson'    => json_encode($report),
];
$r = http("$BASE/transactions.php?action=submit", $payload, 'POST', $TOKEN);
check('success=true',    $r['success'] === true);
check('ada uuid',        !empty($r['data']['uuid'] ?? ''));
check('rev=0',           ($r['data']['rev'] ?? -1) === 0);
$UUID = $r['data']['uuid'] ?? '';
echo "  UUID: " . substr($UUID,0,8) . "...\n";

// ── Verifikasi DB ─────────────────────────────────────────────────────────────
echo "\n=== Verifikasi DB (psql) ===\n";
$dbCheck = shell_exec('PGPASSWORD=SASMU123 "C:/Program Files/PostgreSQL/18/bin/psql.exe" -U postgres -d prod_admin -t -A -c "SELECT COUNT(*) FROM transactions WHERE uuid=\'' . $UUID . '\'"') ?? '';
check('transactions: 1 row',  trim($dbCheck) === '1');

$matCheck = shell_exec('PGPASSWORD=SASMU123 "C:/Program Files/PostgreSQL/18/bin/psql.exe" -U postgres -d prod_admin -t -A -c "SELECT COUNT(*) FROM transaction_materials WHERE transaction_uuid=\'' . $UUID . '\'"') ?? '';
check('transaction_materials: 2 rows', trim($matCheck) === '2');

$hourCheck = shell_exec('PGPASSWORD=SASMU123 "C:/Program Files/PostgreSQL/18/bin/psql.exe" -U postgres -d prod_admin -t -A -c "SELECT COUNT(*) FROM material_hourly_usage mhu JOIN transaction_materials tm ON tm.id=mhu.transaction_material_id WHERE tm.transaction_uuid=\'' . $UUID . '\'"') ?? '';
check('material_hourly_usage: > 0 rows', (int)trim($hourCheck) > 0);

$outCheck = shell_exec('PGPASSWORD=SASMU123 "C:/Program Files/PostgreSQL/18/bin/psql.exe" -U postgres -d prod_admin -t -A -c "SELECT COUNT(*) FROM production_outputs WHERE transaction_uuid=\'' . $UUID . '\'"') ?? '';
check('production_outputs: 1 row', trim($outCheck) === '1');

$repCheck = shell_exec('PGPASSWORD=SASMU123 "C:/Program Files/PostgreSQL/18/bin/psql.exe" -U postgres -d prod_admin -t -A -c "SELECT COUNT(*) FROM production_reports WHERE transaction_uuid=\'' . $UUID . '\'"') ?? '';
check('production_reports: 1 row', trim($repCheck) === '1');

// ── History ───────────────────────────────────────────────────────────────────
echo "\n=== GET history.php?action=paged ===\n";
$r = http("$BASE/history.php?action=paged", ['page'=>1,'limit'=>10,'startDate'=>date('Y-m-d'),'endDate'=>date('Y-m-d')], 'GET', $TOKEN);
check('success=true',    $r['success'] === true);
check('total >= 1',      ($r['data']['total'] ?? 0) >= 1);
check('data is array',   is_array($r['data']['data'] ?? null));
$found = array_filter($r['data']['data'] ?? [], fn($d) => $d['id'] === $UUID);
check('UUID ada di history', count($found) > 0);

// ── Revisi ────────────────────────────────────────────────────────────────────
echo "\n=== POST transactions.php?action=revise ===\n";
$payload['uuid']     = $UUID;
$materials[0]['stockAwal'] = 120; // ubah sedikit
$payload['materialsJson'] = json_encode($materials);
$r = http("$BASE/transactions.php?action=revise", $payload, 'POST', $TOKEN);
check('success=true', $r['success'] === true);
check('rev=1',        ($r['data']['rev'] ?? -1) === 1);

// ── Finalize ──────────────────────────────────────────────────────────────────
echo "\n=== POST transactions.php?action=finalize ===\n";
$r = http("$BASE/transactions.php?action=finalize", ['uuid'=>$UUID], 'POST', $TOKEN);
check('success=true', $r['success'] === true);

$statCheck = shell_exec('PGPASSWORD=SASMU123 "C:/Program Files/PostgreSQL/18/bin/psql.exe" -U postgres -d prod_admin -t -A -c "SELECT status FROM transactions WHERE uuid=\'' . $UUID . '\' AND status NOT IN (\'HISTORY\',\'SUPERSEDED\') LIMIT 1"') ?? '';
check('status = FINAL', trim($statCheck) === 'FINAL');

// ── previousStock ─────────────────────────────────────────────────────────────
echo "\n=== GET previousStock ===\n";
$r = http("$BASE/transactions.php?action=previousStock", ['mesin'=>'Mesin BHP 1','shift'=>'2','date'=>date('Y-m-d')], 'GET', $TOKEN);
check('success=true', $r['success'] === true);
check('data is object', is_array($r['data'] ?? null));

// ── Delete ────────────────────────────────────────────────────────────────────
echo "\n=== POST transactions.php?action=delete ===\n";
$r = http("$BASE/transactions.php?action=delete", ['id'=>$UUID], 'POST', $TOKEN);
check('success=true', $r['success'] === true);
$delCheck = shell_exec('PGPASSWORD=SASMU123 "C:/Program Files/PostgreSQL/18/bin/psql.exe" -U postgres -d prod_admin -t -A -c "SELECT COUNT(*) FROM transactions WHERE uuid=\'' . $UUID . '\'"') ?? '';
check('deleted dari DB', trim($delCheck) === '0');

// Cleanup login
http("$BASE/auth.php?action=logout", ['username'=>'Group A','machine'=>'Mesin BHP 1'], 'POST', $TOKEN);

echo "\n══════════════════════════════════════\n";
echo "Hasil: PASS=$PASS  FAIL=$FAIL\n";
echo ($FAIL === 0 ? "✅ Semua tes lulus!\n" : "❌ Ada tes gagal.\n");
