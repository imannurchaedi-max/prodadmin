<?php
declare(strict_types=1);
// Tes fungsional via HTTP ke PHP built-in server (port 8765)

$BASE = 'http://127.0.0.1:8765/api/auth.php';
$PASS = 0;
$FAIL = 0;

function http(string $action, array $data = [], string $method = 'POST', string $token = ''): array {
    global $BASE;
    $url = "$BASE?action=$action";

    $ctx = [
        'http' => [
            'method'          => $method,
            'header'          => "Content-Type: application/json\r\n" .
                                 ($token ? "Authorization: Bearer $token\r\n" : ''),
            'content'         => json_encode($data),
            'timeout'         => 5,
            'ignore_errors'   => true,
        ]
    ];

    if ($method === 'GET' && $data) {
        $url .= '&' . http_build_query($data);
        unset($ctx['http']['content']);
    }

    $res = file_get_contents($url, false, stream_context_create($ctx));
    if ($res === false) { echo "  ⚠️  Koneksi gagal ke $url\n"; return []; }

    $parsed = json_decode($res, true);
    if ($parsed === null) { echo "  ⚠️  Output bukan JSON: " . substr($res, 0, 150) . "\n"; return []; }
    return $parsed;
}

function check(string $label, bool $cond): void {
    global $PASS, $FAIL;
    if ($cond) { echo "  ✅ $label\n"; $PASS++; }
    else        { echo "  ❌ $label\n"; $FAIL++; }
}

// ────────────────────────────────────────────────────────────────────────────

echo "\n=== 1. Login salah password ===\n";
$r = http('login', ['username'=>'Group A','password'=>'SALAH','machine'=>'Mesin Test']);
check('success = false',        $r['success'] === false);
check('ada message',            !empty($r['message']));

echo "\n=== 2. Login sukses ===\n";
$r = http('login', ['username'=>'Group A','password'=>'12345','machine'=>'Mesin Test']);
check('success = true',         $r['success'] === true);
check('ada token',              !empty($r['data']['token'] ?? ''));
check('username cocok',         ($r['data']['username'] ?? '') === 'Group A');
$tokenA = $r['data']['token'] ?? '';

echo "\n=== 3. Validate token valid ===\n";
$r = http('validate', [], 'GET', $tokenA);
check('success = true',         $r['success'] === true);
check('username = Group A',     ($r['data']['username'] ?? '') === 'Group A');
check('isAdmin = false',        ($r['data']['isAdmin'] ?? true) === false);

echo "\n=== 4. Validate token palsu ===\n";
$r = http('validate', [], 'GET', '00000000-0000-0000-0000-000000000000');
check('success = false',        $r['success'] === false);

echo "\n=== 5. Login ulang → WAITING_APPROVAL ===\n";
$r = http('login', ['username'=>'Group A','password'=>'12345','machine'=>'Mesin Test']);
check('success = false',        $r['success'] === false);
check('status WAITING_APPROVAL',($r['status'] ?? '') === 'WAITING_APPROVAL');
check('ada tempToken',          !empty($r['tempToken'] ?? ''));
$tempToken = $r['tempToken'] ?? '';

echo "\n=== 6. checkTakeover — user aktif lihat request ===\n";
$r = http('checkTakeover', ['username'=>'Group A','machine'=>'Mesin Test','token'=>$tokenA], 'GET');
check('hasRequest = true',      ($r['data']['hasRequest'] ?? false) === true);

echo "\n=== 7. takeoverDecision — setujui ===\n";
$r = http('takeoverDecision', ['username'=>'Group A','machine'=>'Mesin Test','approved'=>true,'token'=>$tokenA]);
check('success = true',         $r['success'] === true);

echo "\n=== 8. checkTakeoverStatus — penantang dapat SUCCESS ===\n";
$r = http('takeoverStatus', ['username'=>'Group A','machine'=>'Mesin Test','tempToken'=>$tempToken], 'GET');
check('status = SUCCESS',       ($r['data']['status'] ?? '') === 'SUCCESS');
check('token = tempToken',      ($r['data']['token'] ?? '') === $tempToken);
$tokenA_new = $r['data']['token'] ?? '';

echo "\n=== 9. Token lama seharusnya tidak valid ===\n";
$r = http('validate', [], 'GET', $tokenA);
check('token lama ditolak',     $r['success'] === false);

echo "\n=== 10. Token baru valid ===\n";
$r = http('validate', [], 'GET', $tokenA_new);
check('token baru valid',       $r['success'] === true);

echo "\n=== 11. changePassword ===\n";
$r = http('changePassword', ['username'=>'Group A','oldPass'=>'12345','newPass'=>'baru123']);
check('success = true',         $r['success'] === true);
$r = http('login', ['username'=>'Group A','password'=>'baru123','machine'=>'Mesin Test2']);
check('login dengan pass baru', $r['success'] === true);
// Kembalikan password
http('changePassword', ['username'=>'Group A','oldPass'=>'baru123','newPass'=>'12345']);
http('logout', ['username'=>'Group A','machine'=>'Mesin Test2']);

echo "\n=== 12. forceLogin PIN salah ===\n";
$r = http('forceLogin', ['username'=>'Group A','machine'=>'Mesin Test','pin'=>'wrong']);
check('ditolak PIN salah',      $r['success'] === false);

echo "\n=== 13. forceLogin PIN benar ===\n";
$r = http('forceLogin', ['username'=>'Group A','machine'=>'Mesin Test','pin'=>'DAM!@#123']);
check('success = true',         $r['success'] === true);
$tokenForce = $r['data']['token'] ?? '';

echo "\n=== 14. Logout ===\n";
$r = http('logout', ['username'=>'Group A','machine'=>'Mesin Test','token'=>$tokenForce]);
check('success = true',         $r['success'] === true);
$r = http('validate', [], 'GET', $tokenForce);
check('token setelah logout tidak valid', $r['success'] === false);

echo "\n=== 15. Action tidak dikenal ===\n";
$r = http('tidakAda', []);
check('success = false',        $r['success'] === false);

echo "\n══════════════════════════════════════\n";
echo "Hasil: PASS=$PASS  FAIL=$FAIL\n";
echo ($FAIL === 0 ? "✅ Semua tes lulus!\n" : "❌ Ada tes gagal — periksa output di atas.\n");
