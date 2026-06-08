<?php
declare(strict_types=1);
// Tes fungsional langsung via PHP CLI — tanpa web server.
// Mensimulasikan request ke api/auth.php dengan menginclude langsung.

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

// ── Helper CLI ───────────────────────────────────────────────────────────────

function callAuth(string $action, array $params = [], string $method = 'POST'): array {
    // Reset globals untuk simulasi request baru
    $GLOBALS['_BODY']     = $params;
    $_GET                 = ['action' => $action];
    $_POST                = [];
    $_SERVER['REQUEST_METHOD']    = $method;
    $_SERVER['HTTP_AUTHORIZATION'] = '';

    ob_start();
    include __DIR__ . '/../api/auth.php';
    $out = ob_get_clean();

    $data = json_decode($out, true);
    if ($data === null) {
        echo "  ⚠️  Output bukan JSON: " . substr($out, 0, 200) . "\n";
        return [];
    }
    return $data;
}

$PASS = 0;
$FAIL = 0;

function check(string $label, bool $cond): void {
    global $PASS, $FAIL;
    if ($cond) { echo "  ✅ $label\n"; $PASS++; }
    else        { echo "  ❌ $label\n"; $FAIL++; }
}

// ── Tests ────────────────────────────────────────────────────────────────────

echo "\n=== TEST: Login salah password ===\n";
$r = callAuth('login', ['username'=>'Group A','password'=>'wrong','machine'=>'Mesin Test']);
check('success = false',   $r['success'] === false);
check('ada message',        !empty($r['message']));

echo "\n=== TEST: Login sukses Group A ===\n";
$r = callAuth('login', ['username'=>'Group A','password'=>'12345','machine'=>'Mesin Test']);
check('success = true',    $r['success'] === true);
check('ada token',         !empty($r['data']['token'] ?? ''));
check('username cocok',    ($r['data']['username'] ?? '') === 'Group A');
$tokenA = $r['data']['token'] ?? '';

echo "\n=== TEST: Validate token valid ===\n";
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $tokenA";
$r = callAuth('validate', [], 'GET');
check('success = true',    $r['success'] === true);
check('username = Group A',($r['data']['username'] ?? '') === 'Group A');
check('isAdmin = false',   ($r['data']['isAdmin'] ?? true) === false);

echo "\n=== TEST: Validate token palsu ===\n";
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 00000000-0000-0000-0000-000000000000';
$r = callAuth('validate', [], 'GET');
check('success = false',   $r['success'] === false);

echo "\n=== TEST: Login ulang mesin sama → WAITING_APPROVAL ===\n";
$r = callAuth('login', ['username'=>'Group A','password'=>'12345','machine'=>'Mesin Test']);
check('success = false',       $r['success'] === false);
check('status WAITING',        ($r['status'] ?? '') === 'WAITING_APPROVAL');
check('ada tempToken',         !empty($r['tempToken'] ?? ''));
$tempToken = $r['tempToken'] ?? '';

echo "\n=== TEST: checkTakeover — user aktif lihat ada request ===\n";
$r = callAuth('checkTakeover', ['username'=>'Group A','machine'=>'Mesin Test','token'=>$tokenA], 'GET');
check('hasRequest = true',     ($r['data']['hasRequest'] ?? false) === true);

echo "\n=== TEST: takeoverDecision — setujui ===\n";
$r = callAuth('takeoverDecision', ['username'=>'Group A','machine'=>'Mesin Test','approved'=>true,'token'=>$tokenA]);
check('success = true',        $r['success'] === true);

echo "\n=== TEST: checkTakeoverStatus — penantang cek status ===\n";
$r = callAuth('takeoverStatus', ['username'=>'Group A','machine'=>'Mesin Test','tempToken'=>$tempToken], 'GET');
check('status SUCCESS',        ($r['data']['status'] ?? '') === 'SUCCESS');
check('token = tempToken',     ($r['data']['token'] ?? '') === $tempToken);
$tokenA2 = $r['data']['token'] ?? $tokenA;

echo "\n=== TEST: Token lama tidak valid lagi ===\n";
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $tokenA";
$r = callAuth('validate', [], 'GET');
check('token lama ditolak',    $r['success'] === false);

echo "\n=== TEST: Token baru valid ===\n";
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $tokenA2";
$r = callAuth('validate', [], 'GET');
check('token baru valid',      $r['success'] === true);

echo "\n=== TEST: changePassword ===\n";
$r = callAuth('changePassword', ['username'=>'Group A','oldPass'=>'12345','newPass'=>'baru123']);
check('success = true',        $r['success'] === true);

$r = callAuth('login', ['username'=>'Group A','password'=>'baru123','machine'=>'Mesin Test2']);
check('login dengan pass baru', $r['success'] === true);

// Kembalikan password semula
callAuth('changePassword', ['username'=>'Group A','oldPass'=>'baru123','newPass'=>'12345']);

echo "\n=== TEST: forceLogin dengan PIN salah ===\n";
$r = callAuth('forceLogin', ['username'=>'Group A','machine'=>'Mesin Test','pin'=>'wrong']);
check('ditolak PIN salah',     $r['success'] === false);

echo "\n=== TEST: forceLogin dengan PIN benar ===\n";
$pin = 'DAM!@#123';
$r = callAuth('forceLogin', ['username'=>'Group A','machine'=>'Mesin Test','pin'=>$pin]);
check('success = true',        $r['success'] === true);
check('ada token',             !empty($r['data']['token'] ?? ''));

echo "\n=== TEST: Logout ===\n";
$token = $r['data']['token'] ?? '';
$r = callAuth('logout', ['username'=>'Group A','machine'=>'Mesin Test','token'=>$token]);
check('success = true',        $r['success'] === true);

$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";
$r = callAuth('validate', [], 'GET');
check('token setelah logout tidak valid', $r['success'] === false);

// Logout Mesin Test2 juga (cleanup)
callAuth('logout', ['username'=>'Group A','machine'=>'Mesin Test2']);

echo "\n══════════════════════════════════════\n";
echo "Hasil: PASS=$PASS  FAIL=$FAIL\n";
echo ($FAIL === 0 ? "✅ Semua tes lulus!\n" : "❌ Ada tes gagal.\n");
