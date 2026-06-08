"""
setup_photo_migration.py
Baca data_lama.xlsx, populate tabel photo_migration + photo_migration_map.
Jalankan SEKALI sebelum membuka migrate_photos.php di browser.

Cara pakai:
    python seeds/setup_photo_migration.py
"""

import sys, os, re
import pandas as pd
import psycopg2
from psycopg2.extras import execute_batch
import json
from datetime import datetime
from db_config import load_db_config

PROGRESS_FILE = os.path.join(os.path.dirname(__file__), 'migration_progress.json')

def write_progress(step, current, total, status='running', message=''):
    try:
        with open(PROGRESS_FILE, 'w') as f:
            json.dump({
                'script': 'setup_photo_migration',
                'step': step,
                'current': current,
                'total': total,
                'status': status,
                'message': message,
                'timestamp': datetime.now().isoformat()
            }, f)
    except:
        pass

DB   = load_db_config()
XLS  = os.path.join(os.path.dirname(__file__), '..', 'data_lama.xlsx')
PASS = FAIL = 0

def ok(msg):  global PASS; PASS += 1; print(f'  [OK] {msg}')
def err(msg): global FAIL; FAIL += 1; print(f'  [FAIL] {msg}')

def is_gdrive_id(s):
    s = str(s).strip()
    return bool(s and '/' not in s and len(s) > 10 and re.match(r'^[A-Za-z0-9_-]+$', s))

# ── Baca Excel ────────────────────────────────────────────────────────────────
write_progress('setup_read', 0, 100, 'running', 'Membaca file Excel...')
print('\nMembaca data_lama.xlsx...')
df = pd.concat([
    pd.read_excel(XLS, sheet_name='Database_Used'),
    pd.read_excel(XLS, sheet_name='Database_Used_Archive'),
], ignore_index=True)
df.columns = [str(c).strip() for c in df.columns]
print(f'  {len(df)} baris total')

# ── Koneksi DB ────────────────────────────────────────────────────────────────
conn = psycopg2.connect(**DB)
conn.autocommit = False
cur  = conn.cursor()

# ── Buat tabel jika belum ada ─────────────────────────────────────────────────
print('\nMembuat tabel photo_migration...')
cur.execute("""
    CREATE TABLE IF NOT EXISTS photo_migration (
        id          SERIAL       PRIMARY KEY,
        gdrive_id   VARCHAR(200) NOT NULL UNIQUE,
        local_path  VARCHAR(500),
        mime_type   VARCHAR(100),
        file_size   BIGINT,
        status      VARCHAR(20)  NOT NULL DEFAULT 'PENDING',
        attempts    SMALLINT     NOT NULL DEFAULT 0,
        error_msg   TEXT,
        created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
        updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
    )
""")
cur.execute("CREATE INDEX IF NOT EXISTS idx_pm_status ON photo_migration (status)")
cur.execute("CREATE INDEX IF NOT EXISTS idx_pm_gdrive ON photo_migration (gdrive_id)")
cur.execute("""
    CREATE TABLE IF NOT EXISTS photo_migration_map (
        id        SERIAL  PRIMARY KEY,
        tm_id     INT     NOT NULL REFERENCES transaction_materials(id) ON DELETE CASCADE,
        gdrive_id VARCHAR(200) NOT NULL,
        position  SMALLINT NOT NULL DEFAULT 0
    )
""")
cur.execute("CREATE INDEX IF NOT EXISTS idx_pmm_tm   ON photo_migration_map (tm_id)")
cur.execute("CREATE INDEX IF NOT EXISTS idx_pmm_gdid ON photo_migration_map (gdrive_id)")
conn.commit()
ok('Tabel siap')

# Cek kondisi tabel
cur.execute("SELECT COUNT(*) FROM photo_migration")
existing_ids = cur.fetchone()[0]
cur.execute("SELECT COUNT(*) FROM photo_migration_map")
existing_map = cur.fetchone()[0]

# ── Populate photo_migration (unique GDrive IDs) ──────────────────────────────
# Di web UI, kita asumsikan selalu overwrite/clear jika dijalankan
if existing_ids > 0 or existing_map > 0:
    write_progress('setup_init', 0, 100, 'running', 'Menghapus data mapping lama...')
    cur.execute("DELETE FROM photo_migration_map")
    cur.execute("DELETE FROM photo_migration")
    conn.commit()
    existing_ids = 0
    print('  Data lama dihapus.')

if existing_ids == 0:
    print('\nMengumpulkan GDrive IDs unik...')
    total_rows = len(df)
    write_progress('setup_ids', 0, total_rows, 'running', 'Mengumpulkan GDrive IDs...')
    processed = 0

    for val in df['label_photos'].dropna():
        processed += 1
        if processed % 100 == 0:
            write_progress('setup_ids', processed, total_rows, 'running', 'Mengumpulkan GDrive IDs...')
        
        val = str(val).strip()
        if not val: continue
        for part in val.split(','):
            part = part.strip()
            if is_gdrive_id(part):
                all_ids.add(part)
    print(f'  {len(all_ids)} unique GDrive IDs ditemukan')
    batch = [(gid,) for gid in all_ids]
    execute_batch(cur, "INSERT INTO photo_migration (gdrive_id) VALUES (%s) ON CONFLICT (gdrive_id) DO NOTHING",
                  batch, page_size=500)
    conn.commit()
    ok(f'{len(all_ids)} IDs dimasukkan ke photo_migration')
    write_progress('setup_ids', total_rows, total_rows, 'running', 'GDrive IDs dikumpulkan')
else:
    print(f'\n  photo_migration sudah berisi {existing_ids} IDs, lanjut ke mapping...')

# ── Populate photo_migration_map ──────────────────────────────────────────────
print('\nMembuat mapping GDrive ID -> transaction_materials...')

# Build lookup: (uuid_lower, material_name) → tm_id
cur.execute("""
    SELECT tm.id, t.uuid, tm.material_name
    FROM transaction_materials tm
    JOIN transactions t ON t.id = tm.transaction_id
""")
tm_lookup = {}
for row in cur.fetchall():
    key = (str(row[1]).lower(), str(row[2]).strip())
    tm_lookup[key] = row[0]

map_rows   = []
skipped    = 0
total_maps = 0

total_rows = len(df)
write_progress('setup_map', 0, total_rows, 'running', 'Membuat mapping foto ke material...')
processed_map = 0

for _, row in df.iterrows():
    processed_map += 1
    if processed_map % 100 == 0:
        write_progress('setup_map', processed_map, total_rows, 'running', 'Membuat mapping foto ke material...')

    uuid_val     = str(row.get('record_uuid', '') or '').strip().lower()
    material_val = str(row.get('material_name', '') or '').strip()
    photos_val   = str(row.get('label_photos', '') or '').strip()

    if not uuid_val or not photos_val:
        continue

    tm_id = tm_lookup.get((uuid_val, material_val))
    if not tm_id:
        tm_id = tm_lookup.get((uuid_val, 'UNKNOWN'))
    if not tm_id:
        skipped += 1
        continue

    for pos, part in enumerate(photos_val.split(',')):
        part = part.strip()
        if is_gdrive_id(part):
            map_rows.append((tm_id, part, pos))
            total_maps += 1

    if len(map_rows) >= 1000:
        execute_batch(cur, """
            INSERT INTO photo_migration_map (tm_id, gdrive_id, position) VALUES (%s, %s, %s)
        """, map_rows, page_size=500)
        conn.commit()
        map_rows = []

if map_rows:
    execute_batch(cur, """
        INSERT INTO photo_migration_map (tm_id, gdrive_id, position) VALUES (%s, %s, %s)
    """, map_rows, page_size=500)
    conn.commit()

ok(f'{total_maps} mapping diinsert')
if skipped:
    print(f'  (Dilewati karena tm_id tidak ditemukan: {skipped})')

# ── Ringkasan ──────────────────────────────────────────────────────────────────
cur.execute("SELECT status, COUNT(*) FROM photo_migration GROUP BY status ORDER BY status")
print('\n=== STATUS MIGRASI ===')
for r in cur.fetchall():
    print(f'  {r[0]:<12} {r[1]:>8} files')

cur.execute("SELECT COUNT(*) FROM photo_migration_map")
print(f'  Total mappings: {cur.fetchone()[0]}')

cur.close(); conn.close()
print(f'\nSetup selesai. PASS={PASS}  FAIL={FAIL}')
write_progress('done', 100, 100, 'done', 'Mapping Foto Selesai')
print('\nLangkah selanjutnya:')
print('  1. Buka http://localhost/ProdAdmin/tools/migrate_photos.php')
print('  2. Masukkan Google OAuth Client ID & Secret')
print('  3. Authorize dan mulai download')
