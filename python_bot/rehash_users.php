<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// Password asli dari GAS (plaintext) → akan di-hash bcrypt
// Jalankan sekali saja. Aman dijalankan ulang (idempoten via ON CONFLICT).
$users = [
    ['username' => 'Group A', 'password' => '12345',      'role' => 'user'],
    ['username' => 'Group B', 'password' => '12345',      'role' => 'user'],
    ['username' => 'Group C', 'password' => '12345',      'role' => 'user'],
    ['username' => 'Group D', 'password' => '12345',      'role' => 'user'],
    ['username' => 'Admin',   'password' => 'DAM!@#123',  'role' => 'admin'],
];

$db  = getDb();
$sql = 'INSERT INTO users (username, password_hash, role)
        VALUES (:username, :hash, :role)
        ON CONFLICT (username) DO UPDATE
            SET password_hash = EXCLUDED.password_hash,
                role          = EXCLUDED.role';

$stmt = $db->prepare($sql);

foreach ($users as $u) {
    $hash = password_hash($u['password'], PASSWORD_BCRYPT);
    $stmt->execute([
        ':username' => $u['username'],
        ':hash'     => $hash,
        ':role'     => $u['role'],
    ]);
    echo "OK: {$u['username']} ({$u['role']})\n";
}

echo "\nSelesai. Password sudah di-hash bcrypt.\n";
