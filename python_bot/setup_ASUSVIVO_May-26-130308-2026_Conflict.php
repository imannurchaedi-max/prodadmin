<?php
/**
 * setup.php — Portal Migrasi Lengkap ProdAdmin
 * Satu halaman untuk semua: schema DB, import Excel, download foto GDrive.
 *
 * Akses: http://[host]/ProdAdmin/tools/setup.php
 * Ubah SETUP_PASSWORD sebelum deploy!
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

define('SETUP_PASSWORD', 'setup2024');
define('APP_ROOT',       dirname(__DIR__));
define('SCHEMA_FILE',    APP_ROOT . '/sql/setup_fresh.sql');
define('UPLOAD_BASE',    APP_ROOT . '/uploads/labels/migrated/');
define('UPLOAD_URL',     'migrated/');
define('BATCH_SIZE',     40);
define('DL_URL',         'https://drive.google.com/uc?export=download&id=');

session_start();
if (!is_dir(UPLOAD_BASE)) mkdir(UPLOAD_BASE, 0755, true);

// ─────────────────────────────────────────────────────────────────────────────
// AUTH
// ─────────────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['setup_auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['setup_pass'] ?? '') === SETUP_PASSWORD) {
        $_SESSION['setup_auth'] = true;
    } else {
        $err = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'Password salah.' : null;
        loginPage($err); exit;
    }
}

function loginPage(?string $err): void { ?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
<title>Setup ProdAdmin</title>
<link rel="stylesheet" href="../assets/bootstrap.min.css">
</head><body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow" style="width:360px"><div class="card-body p-4">
    <h5 class="fw-bold mb-1">🔧 Setup ProdAdmin</h5>
    <p class="text-muted small mb-3">Masukkan password setup untuk melanjutkan.</p>
    <?php if($err): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <form method="post">
        <input type="password" name="setup_pass" class="form-control mb-2" placeholder="Password setup" autofocus>
        <button class="btn btn-primary w-100">Masuk</button>
    </form>
</div></div></body></html>
<?php }

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────
function jsonOut(mixed $d): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d); exit;
}

function dbCfg(): array {
    return $_SESSION['db_config'] ?? ['host'=>'localhost','port'=>5432,'dbname'=>'prod_admin','user'=>'postgres','pass'=>''];
}

function makeDb(?array $cfg = null): PDO {
    $c = $cfg ?? dbCfg();
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['dbname']};options=--search_path=public";
    $db = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $db->exec("SET timezone='Asia/Jakarta'");
    return $db;
}

function toNum(mixed $v, float $d = 0): float {
    $s = trim(str_replace(',', '.', (string)$v), " \t%");
    return is_numeric($s) ? (float)$s : $d;
}

function toInt2(mixed $v, int $d = 0): int { return (int)toNum($v, $d); }

function toDate(mixed $v): string {
    $s = trim((string)$v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s, 0, 10);
    if (is_numeric($s) && (float)$s > 1000) return date('Y-m-d', (int)(((float)$s - 25569) * 86400));
    foreach (['d/m/Y','m/d/Y','d-m-Y','Y/m/d'] as $fmt) {
        $dt = \DateTime::createFromFormat($fmt, $s);
        if ($dt) return $dt->format('Y-m-d');
    }
    return date('Y-m-d');
}

function isUuid(string $s): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($s));
}

function isGdriveId(string $s): bool {
    $s = trim($s);
    return $s !== '' && strpos($s, '/') === false && strlen($s) >= 10
        && (bool)preg_match('/^[A-Za-z0-9_-]+$/', $s);
}

// ─────────────────────────────────────────────────────────────────────────────
// XLSX READER (pure PHP, no library)
// ─────────────────────────────────────────────────────────────────────────────
function xlsxSharedStrings(ZipArchive $zip): array {
    $shared = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if (!$xml) return $shared;
    $ss = new SimpleXMLElement($xml);
    foreach ($ss->si as $si) {
        if (isset($si->t))      $shared[] = (string)$si->t;
        elseif (isset($si->r)) { $t = ''; foreach ($si->r as $r) $t .= (string)($r->t ?? ''); $shared[] = $t; }
        else                    $shared[] = '';
    }
    return $shared;
}

function xlsxSheetFile(ZipArchive $zip, string $name): ?string {
    $wb = $zip->getFromName('xl/workbook.xml');
    if (!$wb) return null;
    $wbXml = new SimpleXMLElement($wb);
    $wbXml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rels = new SimpleXMLElement($zip->getFromName('xl/_rels/workbook.xml.rels'));
    foreach ($wbXml->xpath('//x:sheet') as $s) {
        if ((string)$s->attributes()['name'] !== $name) continue;
        $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $r) {
            if ((string)$r['Id'] === $rId)
                return 'xl/' . ltrim((string)$r['Target'], '/');
        }
    }
    return null;
}

function colIdx(string $col): int {
    $n = 0;
    foreach (str_split(strtoupper($col)) as $c) $n = $n * 26 + ord($c) - 64;
    return $n - 1;
}

function readSheet(string $xlsxPath, string $sheetName): array {
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) throw new \Exception("Tidak bisa buka XLSX.");
    $shared   = xlsxSharedStrings($zip);
    $sheetFile = xlsxSheetFile($zip, $sheetName);
    if (!$sheetFile) { $zip->close(); return []; }
    $wsXml = $zip->getFromName($sheetFile);
    $zip->close();
    if (!$wsXml) return [];

    $ws = new SimpleXMLElement($wsXml);
    $ws->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = [];
    foreach ($ws->xpath('//x:row') as $row) {
        $rd = [];
        foreach ($row->c as $cell) {
            $attr = $cell->attributes();
            $ref  = (string)$attr['r'];
            $type = (string)($attr['t'] ?? '');
            $ci   = colIdx(preg_replace('/[0-9]/', '', $ref));
            $val  = '';
            if (isset($cell->v)) {
                $raw = (string)$cell->v;
                $val = $type === 's' ? ($shared[(int)$raw] ?? '') : $raw;
            } elseif ($type === 'inlineStr') {
                $val = (string)($cell->is->t ?? '');
            }
            $rd[$ci] = $val;
        }
        if (!empty($rd)) { ksort($rd); $rows[] = $rd; }
    }
    return $rows;
}

function toAssoc(array $rows): array {
    if (empty($rows)) return [];
    $headers = $rows[0];
    $out = [];
    for ($i = 1; $i < count($rows); $i++) {
        $rec = [];
        foreach ($headers as $ci => $h) $rec[trim((string)$h)] = trim((string)($rows[$i][$ci] ?? ''));
        if (array_filter($rec, fn($v) => $v !== '')) $out[] = $rec;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — test koneksi DB
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'test_db') {
    $c = [
        'host'   => trim($_POST['host']   ?? 'localhost'),
        'port'   => (int)($_POST['port']  ?? 5432),
        'dbname' => trim($_POST['dbname'] ?? 'prod_admin'),
        'user'   => trim($_POST['user']   ?? 'postgres'),
        'pass'   => $_POST['pass']        ?? '',
    ];
    try {
        $db = makeDb($c); $db->query("SELECT 1");
        $_SESSION['db_config'] = $c;
        jsonOut(['ok'=>true, 'message'=>'Koneksi OK ke '.$c['dbname']]);
    } catch(\Exception $e) { jsonOut(['ok'=>false,'message'=>$e->getMessage()]); }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — jalankan schema SQL
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'run_schema') {
    try {
        $db = makeDb();
        try { $db->exec("CREATE EXTENSION IF NOT EXISTS pgcrypto"); } catch(\Exception $e) {}
        if (!file_exists(SCHEMA_FILE)) jsonOut(['ok'=>false,'message'=>'setup_fresh.sql tidak ditemukan di '.SCHEMA_FILE]);
        $sql   = preg_replace('/--[^\n]*/', '', file_get_contents(SCHEMA_FILE));
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($stmts as $s) if ($s) $db->exec($s);
        $t = (int)$db->query("SELECT COUNT(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn();
        jsonOut(['ok'=>true,'message'=>"Schema selesai. $t tabel terbentuk."]);
    } catch(\Exception $e) { jsonOut(['ok'=>false,'message'=>$e->getMessage()]); }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — seed users
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'seed_users') {
    try {
        $db    = makeDb();
        $users = [['Group A','12345','user'],['Group B','12345','user'],
                  ['Group C','12345','user'],['Group D','12345','user'],['Admin','DAM!@#123','admin']];
        $stmt  = $db->prepare("INSERT INTO users (username,password_hash,role) VALUES (:u,:h,:r)
                               ON CONFLICT (username) DO UPDATE SET password_hash=EXCLUDED.password_hash,role=EXCLUDED.role");
        foreach ($users as [$u,$p,$r]) $stmt->execute([':u'=>$u,':h'=>password_hash($p,PASSWORD_BCRYPT),':r'=>$r]);
        jsonOut(['ok'=>true,'message'=>count($users).' user dibuat (bcrypt).']);
    } catch(\Exception $e) { jsonOut(['ok'=>false,'message'=>$e->getMessage()]); }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — scan folder: cocokkan file yang sudah ada vs photo_migration
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'photo_scan') {
    try {
        $db = makeDb();
        $db->query("SELECT 1 FROM photo_migration LIMIT 1"); // tabel harus ada

        // Scan semua file di folder migrated/
        $files = glob(UPLOAD_BASE . '*.*') ?: [];
        $matched = $skipped = $alreadyDone = 0;

        $stmt = $db->prepare(
            "UPDATE photo_migration
             SET status='DONE', local_path=:p, updated_at=NOW()
             WHERE gdrive_id=:g AND status != 'DONE'"
        );

        foreach ($files as $fpath) {
            $fname  = basename($fpath);
            $gid    = pathinfo($fname, PATHINFO_FILENAME); // nama file tanpa ekstensi = gdrive_id
            $lpath  = UPLOAD_URL . $fname;

            $stmt->execute([':p' => $lpath, ':g' => $gid]);
            if ($stmt->rowCount() > 0) {
                $matched++;
            } else {
                // Cek apakah memang sudah DONE sebelumnya
                $chk = $db->prepare("SELECT status FROM photo_migration WHERE gdrive_id=:g");
                $chk->execute([':g' => $gid]);
                $row = $chk->fetch();
                if ($row && $row['status'] === 'DONE') $alreadyDone++;
                else $skipped++; // file ada tapi ID-nya tidak terdaftar di migration table
            }
        }

        $pending = (int)$db->query("SELECT COUNT(*) FROM photo_migration WHERE status='PENDING'")->fetchColumn();
        $done    = (int)$db->query("SELECT COUNT(*) FROM photo_migration WHERE status='DONE'")->fetchColumn();

        jsonOut([
            'ok'          => true,
            'files_found' => count($files),
            'matched'     => $matched,
            'already_done'=> $alreadyDone,
            'unregistered'=> $skipped,
            'pending_left'=> $pending,
            'done_total'  => $done,
            'message'     => count($files).' file ditemukan di folder. '
                           . $matched.' di-mark DONE baru, '
                           . $alreadyDone.' sudah DONE sebelumnya, '
                           . $pending.' masih perlu didownload.',
        ]);
    } catch(\Exception $e) { jsonOut(['ok'=>false,'message'=>$e->getMessage()]); }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — upload + import Excel
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'import_excel') {
    $sheet   = $_GET['sheet'] ?? '';
    $tmpKey  = 'xlsx_tmp';

    // Upload
    if (!empty($_FILES['excel']) && $_FILES['excel']['error'] === 0) {
        $tmp = sys_get_temp_dir() . '/prodadmin_' . session_id() . '.xlsx';
        move_uploaded_file($_FILES['excel']['tmp_name'], $tmp);
        $_SESSION[$tmpKey] = $tmp;
        jsonOut(['ok'=>true,'uploaded'=>true,'message'=>'File diupload.']);
    }

    $xls = $_SESSION[$tmpKey] ?? null;
    if (!$xls || !file_exists($xls)) jsonOut(['ok'=>false,'message'=>'Upload Excel dulu.']);

    try {
        $db = makeDb();

        if ($sheet === 'used') {
            $rows = array_merge(toAssoc(readSheet($xls,'Database_Used')), toAssoc(readSheet($xls,'Database_Used_Archive')));
            $txn = $mat = $hr = 0;
            $seen = [];
            foreach ($rows as $r) {
                $uuid = strtolower(trim($r['record_uuid']??'')); $rev = toInt2($r['revision_count']??'');
                if (!isUuid($uuid)||isset($seen["$uuid|$rev"])) continue; $seen["$uuid|$rev"]=true;
                $st = strtoupper(trim($r['status']??'')); $st = in_array($st,['FINAL','HISTORY','SUPERSEDED'])?$st:'DRAFT';
                $db->prepare("INSERT INTO transactions(uuid,created_at,production_date,shift_id,machine_id,product_size,revision_count,created_by,status)
                    VALUES(:u,:ca,:pd,:sh,:mid,:ps,:rev,:cb,:st) ON CONFLICT(uuid,revision_count) DO NOTHING")
                   ->execute([':u'=>$uuid,':ca'=>$r['created_at']?:date('Y-m-d H:i:s'),':pd'=>toDate($r['production_date']??''),
                              ':sh'=>max(1,min(3,toInt2($r['shift_id']??'1'))),':mid'=>$r['machine_id']?:'Unknown',
                              ':ps'=>$r['product_size']?:'-',':rev'=>$rev,':cb'=>$r['created_by']?:'Import',':st'=>$st]);
                $txn++;
            }
            $map = [];
            foreach ($db->query("SELECT id,uuid::text,revision_count FROM transactions")->fetchAll() as $t)
                $map[strtolower($t['uuid']).'|'.$t['revision_count']] = (int)$t['id'];

            foreach ($rows as $r) {
                $uuid = strtolower(trim($r['record_uuid']??'')); $rev = toInt2($r['revision_count']??'');
                $tid  = $map["$uuid|$rev"] ?? null; if (!$tid) continue;
                $stmt = $db->prepare("INSERT INTO transaction_materials(transaction_uuid,transaction_id,material_name,supplier_name,
                    stock_initial,stock_in,stock_return,reject_amount,production_amount,total_usage,stock_final,label_photos)
                    VALUES(:u,:tid,:mn,:sn,:si,:in,:ret,:rej,:pa,:tu,:sf,:lp) RETURNING id");
                $stmt->execute([':u'=>$uuid,':tid'=>$tid,':mn'=>$r['material_name']?:'UNKNOWN',':sn'=>$r['supplier_name']??'',
                    ':si'=>toNum($r['stock_initial']??''),':in'=>toNum($r['stock_in']??''),':ret'=>toNum($r['stock_return']??''),
                    ':rej'=>toNum($r['reject_amount']??''),':pa'=>toNum($r['production_amount']??''),
                    ':tu'=>toNum($r['total_usage']??''),':sf'=>toNum($r['stock_final']??''),':lp'=>$r['label_photos']??'']);
                $tmId = (int)$stmt->fetchColumn(); $mat++;
                for ($h=0;$h<24;$h++) {
                    $a = toNum($r[sprintf('usage_%02d',$h)]??''); if ($a<=0) continue;
                    $db->prepare("INSERT INTO material_hourly_usage(transaction_material_id,hour_idx,amount) VALUES(:t,:h,:a) ON CONFLICT DO NOTHING")
                       ->execute([':t'=>$tmId,':h'=>$h,':a'=>$a]); $hr++;
                }
            }
            jsonOut(['ok'=>true,'message'=>"$txn transaksi, $mat material, $hr hourly usage."]);
        }

        if ($sheet === 'output') {
            $rows = array_merge(toAssoc(readSheet($xls,'Database_Production_Output')),toAssoc(readSheet($xls,'Database_Output_Archive')));
            $n=0;
            foreach ($rows as $r) {
                $uuid = strtolower(trim($r['Transaction ID']??'')); if (!isUuid($uuid)) continue;
                $db->prepare("INSERT INTO production_outputs(transaction_uuid,production_date,mid,product_name,qty_box,category_bag,
                    category_box,counter_pcs,total_weight_kg,loss_kg,revision_count,status)
                    VALUES(:u,:pd,:mid,:pn,:qb,:cb,:cx,:cp,:tw,:lk,:rev,:st)")
                   ->execute([':u'=>$uuid,':pd'=>toDate($r['Date']??''),':mid'=>$r['MID']?:'?',
                    ':pn'=>$r['Product Name']?:'-',':qb'=>toInt2($r['Qty Box']??''),':cb'=>$r['Category Bag']??'',
                    ':cx'=>$r['Category Box']??'',':cp'=>toInt2($r['Counter (Pcs)']??''),
                    ':tw'=>toNum($r['Total Berat (Kg)']??''),':lk'=>toNum($r['Loss (Kg)']??''),
                    ':rev'=>toInt2($r['Revision']??''),
                    ':st'=>strtoupper($r['Status']??'')==='HISTORY'?'HISTORY':'ACTIVE']); $n++;
            }
            jsonOut(['ok'=>true,'message'=>"$n production outputs."]);
        }

        if ($sheet === 'report') {
            $rows = array_merge(toAssoc(readSheet($xls,'Database_Production_Report')),toAssoc(readSheet($xls,'Database_Report_Archive')));
            $n=0;
            foreach ($rows as $r) {
                $uuid = strtolower(trim($r['Transaction ID']??'')); if (!isUuid($uuid)) continue;
                $db->prepare("INSERT INTO production_reports(transaction_uuid,production_date,shift_id,machine_id,
                    total_output_kg,total_loss_kg,avg_loss_pct,downtime_min,downtime_pct,speed_ppm,
                    trouble_logs,near_miss,notes,reject_printing_kg,revision_count,status)
                    VALUES(:u,:pd,:sh,:mid,:tok,:tlk,:alp,:dm,:dp,:sp,:tl,:nm,:nt,:rk,:rev,:st)")
                   ->execute([':u'=>$uuid,':pd'=>toDate($r['Date']??''),':sh'=>max(1,min(3,toInt2($r['Shift']??'1'))),
                    ':mid'=>$r['Machine']?:'Unknown',':tok'=>toNum($r['Total Output (Kg)']??''),
                    ':tlk'=>toNum($r['Total Loss (Kg)']??''),':alp'=>toNum($r['Avg Loss (%)']??''),
                    ':dm'=>toInt2($r['Downtime (Min)']??''),':dp'=>toNum($r['Downtime (%)']??''),
                    ':sp'=>toInt2($r['Speed (ppm)']??''),':tl'=>$r['Trouble Logs']??'',
                    ':nm'=>$r['Near Miss']??'',':nt'=>$r['Notes']??'',
                    ':rk'=>toNum($r['Reject Printing (Kg)']??''),':rev'=>toInt2($r['Revision']??''),
                    ':st'=>strtoupper($r['Status']??'')==='HISTORY'?'HISTORY':'ACTIVE']); $n++;
            }
            jsonOut(['ok'=>true,'message'=>"$n production reports."]);
        }

        if ($sheet === 'supplier') {
            $rows=toAssoc(readSheet($xls,'Database_Supplier')); $mn=$sn=0;
            foreach ($rows as $i=>$r) {
                $mat=trim(array_values($r)[0]??''); if(!$mat) continue;
                $db->prepare("INSERT INTO suppliers(material_name,display_order) VALUES(:m,:o) ON CONFLICT(material_name) DO NOTHING")
                   ->execute([':m'=>$mat,':o'=>$i]); $mn++;
                foreach ($r as $k=>$v) {
                    if(!str_starts_with(strtoupper($k),'SUPPLIER')||!trim($v)) continue;
                    $db->prepare("INSERT INTO material_suppliers(material_name,supplier_name,display_order) VALUES(:m,:s,:o) ON CONFLICT DO NOTHING")
                       ->execute([':m'=>$mat,':s'=>trim($v),':o'=>$sn++]);
                }
            }
            jsonOut(['ok'=>true,'message'=>"$mn material, $sn supplier entries."]);
        }

        if ($sheet === 'conversion') {
            $rows=toAssoc(readSheet($xls,'Database_Conversion')); $n=0;
            foreach ($rows as $r) {
                $mid=trim($r['Mid']??$r['MID']??''); if(!$mid) continue;
                $db->prepare("INSERT INTO conversions(mid,item_name,cat_bag,cat_box,ratio,weight_grams)
                    VALUES(:m,:i,:cb,:cx,:r,:w) ON CONFLICT(mid) DO UPDATE
                    SET item_name=EXCLUDED.item_name,cat_bag=EXCLUDED.cat_bag,
                        cat_box=EXCLUDED.cat_box,ratio=EXCLUDED.ratio,weight_grams=EXCLUDED.weight_grams")
                   ->execute([':m'=>$mid,':i'=>$r['Item']??'',':cb'=>$r['Kategori Kantong']??'',
                    ':cx'=>$r['Kategori Kardus']??'',':r'=>toNum($r['Bag/Box']??''),
                    ':w'=>toNum($r['Berat/pcs (gram)']??'')]); $n++;
            }
            jsonOut(['ok'=>true,'message'=>"$n conversions."]);
        }

    } catch(\Exception $e) { jsonOut(['ok'=>false,'message'=>$e->getMessage()]); }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — setup photo migration tables + mapping dari Excel
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'setup_photos') {
    $xls = $_SESSION['xlsx_tmp'] ?? null;
    if (!$xls || !file_exists($xls)) jsonOut(['ok'=>false,'message'=>'Upload Excel dulu (Step 5).']);
    try {
        $db = makeDb();

        // Buat tabel
        $db->exec("CREATE TABLE IF NOT EXISTS photo_migration (
            id        SERIAL      PRIMARY KEY,
            gdrive_id VARCHAR(200) NOT NULL UNIQUE,
            local_path VARCHAR(500),
            mime_type  VARCHAR(100),
            file_size  BIGINT,
            status     VARCHAR(20) NOT NULL DEFAULT 'PENDING',
            attempts   SMALLINT   NOT NULL DEFAULT 0,
            error_msg  TEXT,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pm_status ON photo_migration(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pm_gdid   ON photo_migration(gdrive_id)");
        $db->exec("CREATE TABLE IF NOT EXISTS photo_migration_map (
            id        SERIAL  PRIMARY KEY,
            tm_id     INT     NOT NULL REFERENCES transaction_materials(id) ON DELETE CASCADE,
            gdrive_id VARCHAR(200) NOT NULL,
            position  SMALLINT NOT NULL DEFAULT 0
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pmm_tm   ON photo_migration_map(tm_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pmm_gdid ON photo_migration_map(gdrive_id)");

        // Reset jika ada data lama
        $db->exec("DELETE FROM photo_migration_map");
        $db->exec("DELETE FROM photo_migration");

        // Baca GDrive IDs dari Excel
        $rows = array_merge(
            toAssoc(readSheet($xls,'Database_Used')),
            toAssoc(readSheet($xls,'Database_Used_Archive'))
        );

        // Insert unique GDrive IDs
        $allIds = [];
        foreach ($rows as $r) {
            $lp = trim($r['label_photos']??''); if (!$lp) continue;
            foreach (explode(',', $lp) as $id) {
                $id = trim($id);
                if (isGdriveId($id)) $allIds[$id] = true;
            }
        }
        $stmt = $db->prepare("INSERT INTO photo_migration(gdrive_id) VALUES(:g) ON CONFLICT(gdrive_id) DO NOTHING");
        foreach (array_keys($allIds) as $gid) $stmt->execute([':g'=>$gid]);

        // Build tm lookup: (uuid|mat) -> tm_id
        $tmMap = [];
        foreach ($db->query("SELECT tm.id, t.uuid::text, tm.material_name FROM transaction_materials tm JOIN transactions t ON t.id=tm.transaction_id")->fetchAll() as $t)
            $tmMap[strtolower($t['uuid']).'|'.trim($t['material_name'])] = (int)$t['id'];

        // Insert mappings
        $mapStmt = $db->prepare("INSERT INTO photo_migration_map(tm_id,gdrive_id,position) VALUES(:t,:g,:p)");
        $maps = 0;
        foreach ($rows as $r) {
            $uuid = strtolower(trim($r['record_uuid']??'')); if (!isUuid($uuid)) continue;
            $mat  = trim($r['material_name']??'') ?: 'UNKNOWN';
            $tmId = $tmMap["$uuid|$mat"] ?? null; if (!$tmId) continue;
            $lp   = trim($r['label_photos']??''); if (!$lp) continue;
            foreach (explode(',', $lp) as $pos=>$id) {
                $id = trim($id); if (!isGdriveId($id)) continue;
                $mapStmt->execute([':t'=>$tmId,':g'=>$id,':p'=>$pos]); $maps++;
            }
        }

        $total = count($allIds);
        jsonOut(['ok'=>true,'message'=>"$total GDrive IDs terdaftar, $maps mappings dibuat."]);
    } catch(\Exception $e) { jsonOut(['ok'=>false,'message'=>$e->getMessage()]); }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — stats foto
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'photo_stats') {
    try {
        $db  = makeDb();
        $db->query("SELECT 1 FROM photo_migration LIMIT 1");
        $rows = $db->query("SELECT status, COUNT(*) AS n FROM photo_migration GROUP BY status")->fetchAll();
        $s = [];
        foreach ($rows as $r) $s[$r['status']] = (int)$r['n'];
        jsonOut(['ok'=>true,'stats'=>$s,'total'=>array_sum($s)]);
    } catch(\Exception $e) { jsonOut(['ok'=>true,'stats'=>[],'total'=>0]); }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — test download 1 foto
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'photo_test') {
    try {
        $db  = makeDb();
        $row = $db->query("SELECT gdrive_id FROM photo_migration WHERE status='PENDING' LIMIT 1")->fetch();
        if (!$row) jsonOut(['ok'=>false,'message'=>'Tidak ada file PENDING.']);
        $res = downloadGdrive($row['gdrive_id']);
        jsonOut(['ok'=>$res['ok'],'gdrive'=>$row['gdrive_id'],
                 'size_kb'=>isset($res['size'])?round($res['size']/1024,1):null,
                 'mime'=>$res['mime']??null,'message'=>$res['ok']
                    ? "Berhasil: {$res['size']} bytes ({$res['mime']})"
                    : "Gagal: {$res['error']}"]);
    } catch(\Exception $e) { jsonOut(['ok'=>false,'message'=>$e->getMessage()]); }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — retry failed
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'photo_retry') {
    $db = makeDb();
    $n  = $db->exec("UPDATE photo_migration SET status='PENDING',attempts=0,error_msg=NULL,updated_at=NOW() WHERE status IN ('FAILED','SKIPPED')");
    jsonOut(['ok'=>true,'reset'=>$n]);
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — download batch
// ─────────────────────────────────────────────────────────────────────────────
function downloadGdrive(string $gid): array {
    $url = DL_URL . urlencode($gid);
    $ch  = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>5,CURLOPT_TIMEOUT=>30,CURLOPT_USERAGENT=>'Mozilla/5.0',
        CURLOPT_COOKIEFILE=>'',CURLOPT_COOKIEJAR=>'',
        CURLOPT_HTTPHEADER=>['Accept: image/*,*/*']]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code !== 200 || !$body) return ['ok'=>false,'error'=>"HTTP $code"];

    // Virus-scan page (file besar)
    if (str_contains($type,'text/html') && str_contains((string)$body,'confirm=')) {
        preg_match('/confirm=([^&"\']+)/',(string)$body,$m);
        $ch2 = curl_init($url.'&confirm='.($m[1]??'t'));
        curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERAGENT=>'Mozilla/5.0']);
        $body = curl_exec($ch2); $code=(int)curl_getinfo($ch2,CURLINFO_HTTP_CODE); $type=(string)curl_getinfo($ch2,CURLINFO_CONTENT_TYPE); curl_close($ch2);
        if ($code!==200||!$body||str_contains($type,'text/html')) return ['ok'=>false,'error'=>'File terlalu besar atau tidak public'];
    }

    $ext = 'jpg';
    if (str_contains($type,'png')) $ext='png'; elseif(str_contains($type,'gif')) $ext='gif';
    elseif(str_contains($type,'webp')) $ext='webp'; elseif(str_contains($type,'pdf')) $ext='pdf';

    return ['ok'=>true,'data'=>$body,'ext'=>$ext,'mime'=>$type,'size'=>strlen((string)$body)];
}

if (($_GET['action'] ?? '') === 'photo_batch') {
    $db   = makeDb();
    $rows = $db->query("SELECT id,gdrive_id FROM photo_migration WHERE status='PENDING' ORDER BY id LIMIT ".BATCH_SIZE)->fetchAll();

    if (empty($rows)) {
        // Selesai — update label_photos
        $db->exec("UPDATE transaction_materials tm SET label_photos=sub.paths
            FROM (SELECT pmm.tm_id, STRING_AGG(pm.local_path,',' ORDER BY pmm.position) AS paths
                  FROM photo_migration_map pmm
                  JOIN photo_migration pm ON pm.gdrive_id=pmm.gdrive_id
                  WHERE pm.status='DONE' GROUP BY pmm.tm_id) sub
            WHERE tm.id=sub.tm_id");
        jsonOut(['ok'=>true,'done'=>true,'message'=>'Semua foto selesai. label_photos di DB sudah diupdate.']);
    }

    $ok = $fail = 0;
    foreach ($rows as $row) {
        $gid = $row['gdrive_id']; $pmId = (int)$row['id'];
        $existing = glob(UPLOAD_BASE.$gid.'.*');
        if ($existing) {
            $db->prepare("UPDATE photo_migration SET status='DONE',local_path=:p,updated_at=NOW() WHERE id=:id")
               ->execute([':p'=>UPLOAD_URL.basename($existing[0]),':id'=>$pmId]);
            $ok++; continue;
        }
        $res = downloadGdrive($gid);
        if (!$res['ok']) {
            $db->prepare("UPDATE photo_migration SET status='FAILED',error_msg=:e,attempts=attempts+1,updated_at=NOW() WHERE id=:id")
               ->execute([':e'=>$res['error'],':id'=>$pmId]);
            $fail++; continue;
        }
        $fname = $gid.'.'.$res['ext'];
        file_put_contents(UPLOAD_BASE.$fname, $res['data']);
        $db->prepare("UPDATE photo_migration SET status='DONE',local_path=:p,mime_type=:m,file_size=:s,updated_at=NOW() WHERE id=:id")
           ->execute([':p'=>UPLOAD_URL.$fname,':m'=>$res['mime'],':s'=>$res['size'],':id'=>$pmId]);
        $ok++;
    }
    $remaining = (int)$db->query("SELECT COUNT(*) FROM photo_migration WHERE status='PENDING'")->fetchColumn();
    jsonOut(['ok'=>true,'done'=>false,'downloaded'=>$ok,'failed'=>$fail,'remaining'=>$remaining]);
}

// ─────────────────────────────────────────────────────────────────────────────
// STATUS untuk tampilan
// ─────────────────────────────────────────────────────────────────────────────
$cfg = dbCfg();
$dbOk = $tableOk = $userOk = $dataOk = $photoSetupOk = false;
$tableCount = $userCount = $txnCount = $photoTotal = $photoDone = $photoFailed = $photoPending = 0;

try {
    $db      = makeDb();
    $dbOk    = true;
    $tableCount = (int)$db->query("SELECT COUNT(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn();
    $tableOk = $tableCount >= 10;
    try { $userCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(); $userOk = $userCount > 0; } catch(\Exception $e) {}
    try { $txnCount  = (int)$db->query("SELECT COUNT(*) FROM transactions")->fetchColumn(); $dataOk = $txnCount > 0; } catch(\Exception $e) {}
    try {
        $pRows = $db->query("SELECT status,COUNT(*) AS n FROM photo_migration GROUP BY status")->fetchAll();
        foreach ($pRows as $r) {
            $photoTotal += (int)$r['n'];
            if ($r['status']==='DONE')    $photoDone    = (int)$r['n'];
            if ($r['status']==='PENDING') $photoPending = (int)$r['n'];
            if ($r['status']==='FAILED')  $photoFailed  = (int)$r['n'];
        }
        $photoSetupOk = $photoTotal > 0;
    } catch(\Exception $e) {}
} catch(\Exception $e) {}

$photoPct = $photoTotal > 0 ? round($photoDone/$photoTotal*100,1) : 0;

$checks = ['PDO PostgreSQL'=>extension_loaded('pdo_pgsql'),'ZipArchive'=>class_exists('ZipArchive'),
           'SimpleXML'=>extension_loaded('simplexml'),'cURL'=>extension_loaded('curl')];
$allOk  = !in_array(false,$checks,true);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup ProdAdmin</title>
<link rel="stylesheet" href="../assets/bootstrap.min.css">
<style>
body{background:#f0f2f5}
.card{border-radius:12px;border:none;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.snum{width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0}
.log{background:#1a1a2e;color:#00ff88;font-family:monospace;font-size:.75rem;height:80px;overflow-y:auto;padding:8px 10px;border-radius:6px;margin-top:8px;white-space:pre-wrap}
.log.tall{height:150px}
</style>
</head>
<body>
<div class="container py-4" style="max-width:800px">
<h4 class="fw-bold mb-1">🔧 Setup Portal — ProdAdmin</h4>
<p class="text-muted small mb-4">Jalankan semua langkah dari atas ke bawah. Setiap langkah aman dijalankan ulang.</p>

<!-- ── STEP 1: Requirements ── -->
<div class="card mb-3">
<div class="card-body">
  <h6 class="fw-bold mb-3">
    <span class="snum <?= $allOk?'bg-success':'bg-danger' ?> text-white me-2">1</span>
    System Requirements
  </h6>
  <div class="row g-2">
  <?php foreach($checks as $n=>$ok): ?>
  <div class="col-6 col-sm-3">
    <div class="rounded p-2 text-center small <?= $ok?'bg-success bg-opacity-10 text-success':'bg-danger bg-opacity-10 text-danger' ?>">
      <?= $ok?'✅':'❌' ?> <?= $n ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php if(!$allOk): ?><div class="alert alert-danger small mt-2 mb-0">Aktifkan extension yang kurang di php.ini lalu restart Apache.</div><?php endif; ?>
</div>
</div>

<!-- ── STEP 2: DB Config ── -->
<div class="card mb-3">
<div class="card-body">
  <h6 class="fw-bold mb-2">
    <span class="snum <?= $dbOk?'bg-success':'bg-secondary' ?> text-white me-2">2</span>
    Koneksi Database
    <?= $dbOk?"<span class='badge bg-success ms-1'>✓ Connected</span>":'' ?>
  </h6>
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="small text-muted">Host</label><input id="dbHost" class="form-control form-control-sm" value="<?= htmlspecialchars($cfg['host']) ?>"></div>
    <div class="col-md-2"><label class="small text-muted">Port</label><input id="dbPort" class="form-control form-control-sm" value="<?= $cfg['port'] ?>"></div>
    <div class="col-md-3"><label class="small text-muted">Database</label><input id="dbName" class="form-control form-control-sm" value="<?= htmlspecialchars($cfg['dbname']) ?>"></div>
    <div class="col-md-2"><label class="small text-muted">User</label><input id="dbUser" class="form-control form-control-sm" value="<?= htmlspecialchars($cfg['user']) ?>"></div>
    <div class="col-md-2"><label class="small text-muted">Password</label><input id="dbPass" type="password" class="form-control form-control-sm" placeholder="****"></div>
    <div class="col-auto mt-2"><button class="btn btn-sm btn-primary" onclick="act('test_db')">Test & Simpan</button></div>
  </div>
  <div id="log_db" class="log" style="height:40px">-</div>
</div>
</div>

<!-- ── STEP 3: Schema ── -->
<div class="card mb-3">
<div class="card-body">
  <h6 class="fw-bold mb-2">
    <span class="snum <?= $tableOk?'bg-success':'bg-secondary' ?> text-white me-2">3</span>
    Buat Schema DB
    <?= $tableOk?"<span class='badge bg-success ms-1'>✓ $tableCount tabel</span>":'' ?>
  </h6>
  <p class="small text-muted mb-2">Jalankan <code>setup_fresh.sql</code> — drop + create semua tabel bersih.</p>
  <button class="btn btn-sm btn-outline-primary" onclick="act('run_schema')">▶ Jalankan Schema</button>
  <div id="log_schema" class="log">-</div>
</div>
</div>

<!-- ── STEP 4: Users ── -->
<div class="card mb-3">
<div class="card-body">
  <h6 class="fw-bold mb-2">
    <span class="snum <?= $userOk?'bg-success':'bg-secondary' ?> text-white me-2">4</span>
    Seed Users
    <?= $userOk?"<span class='badge bg-success ms-1'>✓ $userCount users</span>":'' ?>
  </h6>
  <p class="small text-muted mb-2">Buat 5 user default (Group A–D + Admin) dengan password bcrypt.</p>
  <button class="btn btn-sm btn-outline-primary" onclick="act('seed_users')">▶ Buat Users</button>
  <div id="log_users" class="log">-</div>
</div>
</div>

<!-- ── STEP 5: Import Excel ── -->
<div class="card mb-3">
<div class="card-body">
  <h6 class="fw-bold mb-2">
    <span class="snum <?= $dataOk?'bg-success':'bg-secondary' ?> text-white me-2">5</span>
    Import Data Excel
    <?= $dataOk?"<span class='badge bg-success ms-1'>✓ $txnCount transaksi</span>":'' ?>
  </h6>
  <p class="small text-muted mb-2">Upload <code>data_lama.xlsx</code> lalu import semua sheet sekaligus.</p>
  <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
    <input type="file" id="xlsxFile" class="form-control form-control-sm" accept=".xlsx" style="max-width:320px">
    <button class="btn btn-sm btn-success fw-bold" onclick="importAll()">▶ Import Semua Sheet</button>
  </div>
  <div id="uploadStatus" class="small text-muted mb-1"></div>
  <div id="log_import" class="log tall">-</div>
</div>
</div>

<!-- ── STEP 6: Setup Photo Mapping ── -->
<div class="card mb-3">
<div class="card-body">
  <h6 class="fw-bold mb-2">
    <span class="snum <?= $photoSetupOk?'bg-success':'bg-secondary' ?> text-white me-2">6</span>
    Setup Mapping Foto GDrive
    <?= $photoSetupOk?"<span class='badge bg-success ms-1'>✓ $photoTotal IDs</span>":'' ?>
  </h6>
  <p class="small text-muted mb-2">
    Baca GDrive IDs dari Excel (file yang sudah diupload di Step 5), daftarkan ke tabel <code>photo_migration</code>
    dan buat mapping ke setiap material.
  </p>
  <button class="btn btn-sm btn-outline-primary" onclick="act('setup_photos','log_photos_setup')">▶ Setup Mapping</button>
  <div id="log_photos_setup" class="log">-</div>
</div>
</div>

<!-- ── STEP 7: Download Foto ── -->
<div class="card mb-3">
<div class="card-body">
  <h6 class="fw-bold mb-2">
    <span class="snum <?= $photoDone>0?'bg-success':($photoSetupOk?'bg-warning':'bg-secondary') ?> text-white me-2">7</span>
    Download Foto dari Google Drive
  </h6>

  <div class="mb-3">
    <div class="d-flex justify-content-between small text-muted mb-1">
      <span>Progress: <strong id="photoDone"><?= number_format($photoDone) ?></strong> / <?= number_format($photoTotal) ?> foto</span>
      <span><strong id="photoPct"><?= $photoPct ?>%</strong></span>
    </div>
    <div class="progress mb-1" style="height:16px;border-radius:8px">
      <div id="photoBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:<?= $photoPct ?>%"></div>
    </div>
    <div class="d-flex gap-3 small text-muted flex-wrap">
      <span>✅ <span id="pDone"><?= number_format($photoDone) ?></span></span>
      <span>⏳ <span id="pPending"><?= number_format($photoPending) ?></span> pending</span>
      <span>❌ <span id="pFailed"><?= number_format($photoFailed) ?></span> gagal</span>
      <span class="ms-auto" id="etaLabel"></span>
    </div>
  </div>

  <p class="small text-muted mb-2">
    Jika folder <code>uploads/labels/migrated/</code> sudah berisi foto dari migrasi sebelumnya,
    klik <strong>Scan Folder</strong> dulu — file yang sudah ada akan di-skip otomatis tanpa download ulang.
  </p>
  <div class="d-flex gap-2 flex-wrap mb-2">
    <button class="btn btn-sm btn-outline-secondary" onclick="photoScan()" <?= !$photoSetupOk?'disabled':'' ?>>📂 Scan Folder</button>
    <button class="btn btn-sm btn-outline-info"      onclick="photoTest()" <?= !$photoSetupOk?'disabled':'' ?>>🔍 Test 1 Foto</button>
    <button id="btnStart" class="btn btn-sm btn-success fw-bold" onclick="photoStart()" <?= (!$photoSetupOk||$photoPending===0)?'disabled':'' ?>>▶ Mulai Download</button>
    <button id="btnPause" class="btn btn-sm btn-warning fw-bold d-none" onclick="photoPause()">⏸ Pause</button>
    <button class="btn btn-sm btn-outline-warning" onclick="photoRetry()" <?= $photoFailed===0?'disabled':'' ?>>🔄 Retry Gagal (<?= $photoFailed ?>)</button>
  </div>
  <div id="log_photos" class="log tall">Klik Test dulu, lalu Mulai Download...</div>

  <?php if($photoPending===0 && $photoDone>0): ?>
  <div class="alert alert-success mt-2 mb-0 small">
    🎉 Semua <strong><?= number_format($photoDone) ?></strong> foto berhasil didownload ke
    <code>uploads/labels/migrated/</code> dan kolom <code>label_photos</code> di database
    sudah diupdate dengan path lokal.
  </div>
  <?php endif; ?>
</div>
</div>

<!-- ── DONE ── -->
<?php if($dataOk && $userOk && $tableOk): ?>
<div class="card mb-3 border-success">
<div class="card-body text-center py-3">
  <h5 class="fw-bold text-success mb-1">✅ Database & Data Siap!</h5>
  <p class="text-muted small mb-2">Langkah 3–5 selesai. <?= $photoPending>0?"Lanjutkan download foto (Step 7).":'Semua selesai termasuk foto.' ?></p>
  <a href="../" class="btn btn-primary">Buka Aplikasi →</a>
</div>
</div>
<?php endif; ?>

</div><!-- /container -->
<script src="../assets/bootstrap.bundle.min.js"></script>
<script>
let photoRunning=false, photoStart_=0, photoDoneAt=0;

function lg(id,msg,c='#00ff88'){
    const el=document.getElementById(id); if(!el) return;
    const ts=new Date().toLocaleTimeString('id-ID');
    el.innerHTML+=`<span style="color:${c}">[${ts}] ${msg}\n</span>`;
    el.scrollTop=el.scrollHeight;
}

async function act(action, logId){
    const lid = logId || 'log_'+action.replace('_','').replace('run','schema').replace('seed','users').replace('test','db');
    const body = action==='test_db' ? {
        host:document.getElementById('dbHost').value,
        port:document.getElementById('dbPort').value,
        dbname:document.getElementById('dbName').value,
        user:document.getElementById('dbUser').value,
        pass:document.getElementById('dbPass').value,
    } : {};
    lg(lid,'Memproses...','#ffcc00');
    const fd=new FormData(); Object.entries(body).forEach(([k,v])=>fd.append(k,v));
    const d=await fetch('?action='+action,{method:'POST',body:fd}).then(r=>r.json()).catch(e=>({ok:false,message:e.message}));
    lg(lid, d.message||(d.ok?'OK':'Gagal'), d.ok?'#00ff88':'#ff4444');
}

let xlsUploaded=false;
async function ensureUpload(){
    if(xlsUploaded) return true;
    const f=document.getElementById('xlsxFile').files[0];
    if(!f){alert('Pilih file data_lama.xlsx dulu!');return false;}
    document.getElementById('uploadStatus').textContent='Mengupload...';
    const fd=new FormData(); fd.append('excel',f);
    const d=await fetch('?action=import_excel&sheet=upload',{method:'POST',body:fd}).then(r=>r.json());
    if(d.ok){xlsUploaded=true;document.getElementById('uploadStatus').textContent='✅ File siap diproses.';}
    else{document.getElementById('uploadStatus').textContent='❌ '+d.message;}
    return d.ok;
}

async function importAll(){
    if(!await ensureUpload()) return;
    const sheets=[['used','Transaksi + Material'],['output','Production Output'],['report','Production Report'],['supplier','Supplier'],['conversion','Konversi']];
    for(const [s,label] of sheets){
        lg('log_import',`--- ${label} ---`,'#88aaff');
        const d=await fetch('?action=import_excel&sheet='+s,{method:'POST'}).then(r=>r.json()).catch(e=>({ok:false,message:e.message}));
        lg('log_import',d.message||(d.ok?'OK':'Error'),d.ok?'#00ff88':'#ff4444');
        if(!d.ok) break;
    }
    lg('log_import','Import selesai!');
}

async function photoScan(){
    lg('log_photos','Scanning folder uploads/labels/migrated/ ...','#ffcc00');
    const d=await fetch('?action=photo_scan').then(r=>r.json()).catch(e=>({ok:false,message:e.message}));
    if(!d.ok){ lg('log_photos','Error: '+d.message,'#ff4444'); return; }
    lg('log_photos', d.message, '#00ff88');
    lg('log_photos',
        `  Folder: ${d.files_found} file  |  Baru di-mark DONE: ${d.matched}  |  Sudah DONE: ${d.already_done}  |  Sisa download: ${d.pending_left}`,
        '#88aaff');
    photoStats();
    // Enable/disable tombol Start sesuai pending
    document.getElementById('btnStart').disabled = d.pending_left === 0;
}

async function photoTest(){
    lg('log_photos','Test download 1 foto...','#ffcc00');
    const d=await fetch('?action=photo_test').then(r=>r.json()).catch(e=>({ok:false,message:e.message}));
    lg('log_photos',d.message,d.ok?'#00ff88':'#ff4444');
}

async function photoStart(){
    if(photoRunning) return;
    photoRunning=true; photoStart_=Date.now();
    photoDoneAt=parseInt(document.getElementById('pDone').textContent.replace(/\D/g,''))||0;
    document.getElementById('btnStart').classList.add('d-none');
    document.getElementById('btnPause').classList.remove('d-none');
    lg('log_photos','Mulai download...');
    await photoBatch();
}

function photoPause(){
    photoRunning=false;
    document.getElementById('btnPause').classList.add('d-none');
    document.getElementById('btnStart').classList.remove('d-none');
    document.getElementById('btnStart').disabled=false;
    lg('log_photos','Dijeda.','#ffcc00');
}

async function photoBatch(){
    if(!photoRunning) return;
    const d=await fetch('?action=photo_batch').then(r=>r.json()).catch(e=>({ok:false,message:e.message}));
    if(!d.ok){lg('log_photos','Error: '+(d.message||'unknown'),'#ff4444');photoRunning=false;return;}
    if(d.done){lg('log_photos','🎉 SELESAI! Semua foto didownload dan DB diupdate.');photoRunning=false;photoStats();return;}
    lg('log_photos',`+${d.downloaded} OK  ${d.failed} gagal  ${d.remaining} tersisa`);
    photoStats();
    setTimeout(photoBatch,200);
}

async function photoRetry(){
    const d=await fetch('?action=photo_retry').then(r=>r.json());
    lg('log_photos','Reset '+d.reset+' gagal -> PENDING','#ffcc00');
    photoStats();
}

function photoStats(){
    fetch('?action=photo_stats').then(r=>r.json()).then(d=>{
        const total=d.total||1, done=d.stats['DONE']||0, pending=d.stats['PENDING']||0, failed=d.stats['FAILED']||0;
        const pct=Math.round(done/total*1000)/10;
        document.getElementById('photoBar').style.width=pct+'%';
        document.getElementById('photoPct').textContent=pct+'%';
        document.getElementById('photoDone').textContent=done.toLocaleString('id-ID');
        document.getElementById('pDone').textContent=done.toLocaleString('id-ID');
        document.getElementById('pPending').textContent=pending.toLocaleString('id-ID');
        document.getElementById('pFailed').textContent=failed.toLocaleString('id-ID');
        if(photoStart_&&photoDoneAt!==null){
            const elapsed=(Date.now()-photoStart_)/1000, dlCount=done-photoDoneAt;
            if(elapsed>5&&dlCount>0){
                const spd=dlCount/elapsed;
                const eta=Math.round(pending/spd);
                const etaStr=eta>3600?Math.round(eta/3600)+' jam':eta>60?Math.round(eta/60)+' menit':eta+' detik';
                document.getElementById('etaLabel').textContent=`${spd.toFixed(1)} foto/s · ETA ${etaStr}`;
            }
        }
    });
}
setInterval(()=>{ if(photoRunning) photoStats(); },8000);
</script>
</body></html>
