<?php
/**
 * run_migration_v2.php — Jalankan migration_v2_scale100.sql sekali.
 * Akses via browser: http://[server]/ProdAdmin/tools/run_migration_v2.php
 * Aman dijalankan ulang (semua langkah idempotent).
 */
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

$sqlFile = __DIR__ . '/../sql/migration_v2_scale100.sql';
$ran     = false;
$output  = [];
$error   = null;

if (isset($_POST['run'])) {
    try {
        $db  = getDb();
        $sql = file_get_contents($sqlFile);
        if (!$sql) throw new Exception('File SQL tidak ditemukan: ' . $sqlFile);

        // PDO tidak support multiple statements langsung —
        // split per statement dan jalankan satu per satu
        // Tapi DO $$ ... $$ block harus tetap utuh, jadi kita pakai exec langsung
        // via pgsql native jika tersedia, atau jalankan as-is via PDO exec
        // Cara paling reliable: jalankan via PDO dengan statement splitting sederhana

        // Coba jalankan via pg_query jika ada koneksi native
        $dsn = null;
        // Pakai PDO exec — akan jalan untuk PostgreSQL multi-statement
        $db->exec($sql);

        // Verifikasi hasil
        $sesUniq = $db->query(
            "SELECT COUNT(*) FROM pg_constraint
             WHERE conname = 'sessions_machine_id_key'
               AND conrelid = 'sessions'::regclass"
        )->fetchColumn();

        $takUniq = $db->query(
            "SELECT COUNT(*) FROM pg_constraint
             WHERE conname = 'takeover_requests_username_machine_id_requester_token_key'
               AND conrelid = 'takeover_requests'::regclass"
        )->fetchColumn();

        $idxActor = $db->query(
            "SELECT COUNT(*) FROM pg_indexes
             WHERE indexname = 'idx_audit_logs_actor'"
        )->fetchColumn();

        $output = [
            'sessions.machine_id UNIQUE'    => (int)$sesUniq > 0 ? 'OK' : 'MISSING',
            'takeover_requests UNIQUE'       => (int)$takUniq > 0 ? 'OK' : 'MISSING',
            'idx_audit_logs_actor (index)'   => (int)$idxActor > 0 ? 'OK' : 'MISSING',
        ];
        $ran = true;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Migration v2 — ProdAdmin</title>
<link rel="stylesheet" href="../assets/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:600px">

  <h4 class="fw-bold mb-1">Migration v2 — Scale 100 Users</h4>
  <p class="text-muted small mb-4">
    Menambahkan UNIQUE constraint dan index yang dibutuhkan untuk takeover flow dan performa query.
    Aman dijalankan ulang — semua langkah idempotent.
  </p>

  <?php if ($ran): ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <h6 class="fw-bold mb-3 text-success">Migration Selesai</h6>
        <table class="table table-sm mb-0">
          <?php foreach ($output as $label => $status): ?>
          <tr>
            <td><?= htmlspecialchars($label) ?></td>
            <td>
              <?php if ($status === 'OK'): ?>
                <span class="badge bg-success">OK</span>
              <?php else: ?>
                <span class="badge bg-danger">MISSING</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
    <p class="text-muted small">
      Semua OK? Migration berhasil. File ini bisa dihapus dari server.
    </p>

  <?php elseif ($error): ?>
    <div class="alert alert-danger">
      <strong>Error:</strong><br>
      <code><?= htmlspecialchars($error) ?></code>
    </div>

  <?php else: ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <p class="mb-3">Yang akan dijalankan:</p>
        <ul class="small mb-0">
          <li>Hapus duplikat session per mesin (jika ada)</li>
          <li>Tambah <code>UNIQUE</code> constraint pada <code>sessions.machine_id</code></li>
          <li>Tambah <code>UNIQUE</code> constraint pada <code>takeover_requests</code></li>
          <li>Tambah index <code>idx_audit_logs_actor</code> dan <code>idx_audit_logs_action</code></li>
          <li>Bersihkan takeover_requests lama (>30 hari) dan session expired</li>
        </ul>
      </div>
    </div>

  <?php endif; ?>

  <?php if (!$ran): ?>
  <form method="POST">
    <button name="run" value="1" class="btn btn-primary fw-bold px-4">
      Jalankan Migration
    </button>
  </form>
  <?php else: ?>
    <?php if (!in_array('MISSING', $output)): ?>
    <a href="../index.php" class="btn btn-success fw-bold px-4">Buka Aplikasi</a>
    <?php else: ?>
    <form method="POST">
      <button name="run" value="1" class="btn btn-warning fw-bold px-4">Coba Lagi</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>

</div>
</body>
</html>
