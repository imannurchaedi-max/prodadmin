"""
import_excel.py — Import data_lama.xlsx langsung ke tabel normalized PostgreSQL.
Jalankan: python seeds/import_excel.py

Sheet yang diimport:
  Database_Used + Database_Used_Archive -> transactions + transaction_materials + material_hourly_usage
  Database_Production_Output + Archive  -> production_outputs
  Database_Production_Report + Archive  -> production_reports
  Database_Supplier                     -> suppliers + material_suppliers
  Database_Conversion                   -> conversions
  Database_Logs                         -> audit_logs
"""

import sys, os, re
import pandas as pd
import psycopg2
from psycopg2.extras import execute_batch
from datetime import date, datetime
from decimal import Decimal, InvalidOperation
import json
from db_config import load_db_config

PROGRESS_FILE = os.path.join(os.path.dirname(__file__), 'migration_progress.json')

def write_progress(step, current, total, status='running', message=''):
    try:
        with open(PROGRESS_FILE, 'w') as f:
            json.dump({
                'script': 'import_excel',
                'step': step,
                'current': current,
                'total': total,
                'status': status,
                'message': message,
                'timestamp': datetime.now().isoformat()
            }, f)
    except:
        pass

# ── Koneksi ──────────────────────────────────────────────────────────────────
DB = load_db_config()
EXCEL = os.path.join(os.path.dirname(__file__), '..', 'data_lama.xlsx')

PASS = FAIL = 0

def ok(msg):
    global PASS; PASS += 1; print(f'  [OK] {msg}')

def fail(msg):
    global FAIL; FAIL += 1; print(f'  [FAIL] {msg}')

# ── Type helpers ──────────────────────────────────────────────────────────────
def to_str(v, default=''):
    if v is None or (isinstance(v, float) and str(v) == 'nan'): return default
    return str(v).strip()

def to_num(v, default=0):
    s = to_str(v)
    if not s: return default
    s = s.rstrip('%').strip()
    try: return float(s)
    except: return default

def to_int(v, default=0):
    return int(to_num(v, default))

def to_date(v):
    if v is None or (isinstance(v, float) and str(v) == 'nan'): return date.today()
    if isinstance(v, (date, datetime)): return v.date() if isinstance(v, datetime) else v
    s = str(v).strip()
    for fmt in ('%Y-%m-%d', '%d/%m/%Y', '%m/%d/%Y', '%Y/%m/%d'):
        try: return datetime.strptime(s[:10], fmt).date()
        except: pass
    return date.today()

def to_ts(v):
    if v is None or (isinstance(v, float) and str(v) == 'nan'): return datetime.now()
    if isinstance(v, datetime): return v
    if isinstance(v, date): return datetime(v.year, v.month, v.day)
    s = str(v).strip()
    try: return datetime.fromisoformat(s[:19])
    except: return datetime.now()

def is_valid_uuid(s):
    return bool(s and re.match(r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', str(s).strip().lower()))

# ── Baca sheet ────────────────────────────────────────────────────────────────
write_progress('reading_excel', 0, 100, 'running', 'Membaca file Excel...')
print('\nMembaca Excel (mungkin perlu beberapa detik)...')
try:
    xls = pd.ExcelFile(EXCEL)
    def read(name):
        if name in xls.sheet_names:
            df = xls.parse(name)
            df.columns = [str(c).strip() for c in df.columns]
            return df
        return pd.DataFrame()

    df_used     = pd.concat([read('Database_Used'), read('Database_Used_Archive')], ignore_index=True)
    df_output   = pd.concat([read('Database_Production_Output'), read('Database_Output_Archive')], ignore_index=True)
    df_report   = pd.concat([read('Database_Production_Report'), read('Database_Report_Archive')], ignore_index=True)
    df_supplier = read('Database_Supplier')
    df_conv     = read('Database_Conversion')
    df_logs     = read('Database_Logs')
    print(f'  Database_Used (+Archive):   {len(df_used)} baris')
    print(f'  Database_Production_Output: {len(df_output)} baris')
    print(f'  Database_Production_Report: {len(df_report)} baris')
    print(f'  Database_Supplier:          {len(df_supplier)} baris')
    print(f'  Database_Conversion:        {len(df_conv)} baris')
    print(f'  Database_Logs:              {len(df_logs)} baris')
except Exception as e:
    print(f'GAGAL membaca Excel: {e}'); sys.exit(1)

conn = psycopg2.connect(**DB)
conn.autocommit = False
cur  = conn.cursor()

# ============================================================
# 1. SUPPLIERS + MATERIAL_SUPPLIERS
# ============================================================
print('\n=== 1. Suppliers ===')
try:
    total_supp = len(df_supplier)
    write_progress('suppliers', 0, total_supp, 'running', 'Migrasi Suppliers...')
    supplier_cols = [c for c in df_supplier.columns if c.upper().startswith('SUPPLIER') and c != 'SUPPLIER 0']
    mat_col = df_supplier.columns[0]  # 'Material'
    ins_mat  = 0
    ins_sup  = 0
    for _, row in df_supplier.iterrows():
        mat = to_str(row.get(mat_col))
        if not mat: continue
        cur.execute(
            'INSERT INTO suppliers (material_name, display_order) VALUES (%s, %s) ON CONFLICT (material_name) DO NOTHING',
            (mat, ins_mat)
        )
        ins_mat += 1
        for i, scol in enumerate(supplier_cols):
            sup = to_str(row.get(scol))
            if not sup: continue
            cur.execute(
                'INSERT INTO material_suppliers (material_name, supplier_name, display_order) VALUES (%s, %s, %s) ON CONFLICT (material_name, supplier_name) DO NOTHING',
                (mat, sup, i)
            )
            ins_sup += 1
        
        if ins_mat % 10 == 0:
            write_progress('suppliers', ins_mat, total_supp, 'running', 'Migrasi Suppliers...')

    conn.commit()
    ok(f'{ins_mat} materials, {ins_sup} supplier entries')
    write_progress('suppliers', total_supp, total_supp, 'running', 'Suppliers selesai')
except Exception as e:
    conn.rollback(); fail(f'Suppliers: {e}')

# ============================================================
# 2. CONVERSIONS
# ============================================================
print('\n=== 2. Conversions ===')
try:
    total_conv = len(df_conv)
    write_progress('conversions', 0, total_conv, 'running', 'Migrasi Conversions...')
    count = 0
    for idx, row in df_conv.iterrows():
        mid = to_str(row.get('Mid') or row.get('MID') or row.get('mid'))
        if not mid: continue
        cur.execute('''
            INSERT INTO conversions (mid, item_name, cat_bag, cat_box, ratio, weight_grams)
            VALUES (%s,%s,%s,%s,%s,%s)
            ON CONFLICT (mid) DO UPDATE
                SET item_name=EXCLUDED.item_name, cat_bag=EXCLUDED.cat_bag,
                    cat_box=EXCLUDED.cat_box, ratio=EXCLUDED.ratio, weight_grams=EXCLUDED.weight_grams
        ''', (
            mid,
            to_str(row.get('Item') or row.get('item_name')),
            to_str(row.get('Kategori Kantong') or row.get('cat_bag')),
            to_str(row.get('Kategori Kardus') or row.get('cat_box')),
            to_num(row.get('Bag/Box') or row.get('ratio')),
            to_num(row.get('Berat/pcs (gram)') or row.get('weight_grams'))
        ))
        count += 1
        if count % 10 == 0:
            write_progress('conversions', count, total_conv, 'running', 'Migrasi Conversions...')
    conn.commit()
    ok(f'{count} conversions')
    write_progress('conversions', total_conv, total_conv, 'running', 'Conversions selesai')
except Exception as e:
    conn.rollback(); fail(f'Conversions: {e}')

# ============================================================
# 3. TRANSACTIONS (dari Database_Used)
# ============================================================
print('\n=== 3. Transactions (header) ===')
# Kumpulkan UUID unik — ambil revisi tertinggi sebagai header aktif
usage_cols = [f'usage_{i:02d}' for i in range(24)]

# Group by uuid: ambil semua revisi, simpan semua
df_used_valid = df_used[df_used['record_uuid'].apply(lambda x: is_valid_uuid(x))].copy()
df_used_valid['record_uuid']    = df_used_valid['record_uuid'].astype(str).str.strip().str.lower()
df_used_valid['revision_count'] = df_used_valid['revision_count'].apply(lambda x: to_int(x, 0))

# Untuk tiap (uuid, revision_count) yang unik — ambil baris pertama
df_txn_header = df_used_valid.drop_duplicates(subset=['record_uuid','revision_count'], keep='first')

total_txns = len(df_txn_header)
write_progress('transactions', 0, total_txns, 'running', 'Migrasi Transactions...')

txn_inserted = 0
txn_skipped  = 0
# Map uuid -> {rev -> id}
uuid_rev_to_id = {}
processed_txn = 0

for _, row in df_txn_header.iterrows():
    processed_txn += 1
    if processed_txn % 50 == 0:
        write_progress('transactions', processed_txn, total_txns, 'running', 'Migrasi Transactions...')

    uuid = to_str(row.get('record_uuid')).lower()
    rev  = to_int(row.get('revision_count'), 0)
    raw_status = to_str(row.get('status')).upper()
    if raw_status == 'FINAL':
        status = 'FINAL'
    elif raw_status in ('HISTORY','SUPERSEDED'):
        status = raw_status
    else:
        status = 'DRAFT'
    try:
        cur.execute('''
            INSERT INTO transactions (uuid, created_at, production_date, shift_id, machine_id,
                                      product_size, revision_count, created_by, status)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON CONFLICT (uuid, revision_count) DO NOTHING
            RETURNING id
        ''', (
            uuid,
            to_ts(row.get('created_at')),
            to_date(row.get('production_date')),
            max(1, min(3, to_int(row.get('shift_id'), 1))),
            to_str(row.get('machine_id'), 'Unknown'),
            to_str(row.get('product_size'), '-'),
            rev,
            to_str(row.get('created_by'), 'Import'),
            status,
        ))
        result = cur.fetchone()
        if result:
            uuid_rev_to_id[(uuid, rev)] = result[0]
            txn_inserted += 1
        else:
            # Sudah ada — ambil id-nya
            cur.execute('SELECT id FROM transactions WHERE uuid=%s AND revision_count=%s', (uuid, rev))
            r2 = cur.fetchone()
            if r2: uuid_rev_to_id[(uuid, rev)] = r2[0]
            txn_skipped += 1
    except Exception as e:
        conn.rollback()
        fail(f'Transaction {uuid}/{rev}: {e}')
        continue

conn.commit()
ok(f'{txn_inserted} transaksi diinsert, {txn_skipped} sudah ada')
write_progress('transactions', total_txns, total_txns, 'running', 'Transactions selesai')

# ============================================================
# 4. TRANSACTION_MATERIALS + MATERIAL_HOURLY_USAGE
# ============================================================
print('\n=== 4. Transaction Materials + Hourly Usage ===')
mat_inserted = 0
hour_inserted = 0
total_mats = len(df_used_valid)
write_progress('transaction_materials', 0, total_mats, 'running', 'Migrasi Material Usage...')
processed_mats = 0

for _, row in df_used_valid.iterrows():
    processed_mats += 1
    if processed_mats % 100 == 0:
        write_progress('transaction_materials', processed_mats, total_mats, 'running', 'Migrasi Material Usage...')

    uuid = to_str(row.get('record_uuid')).lower()
    rev  = to_int(row.get('revision_count'), 0)
    txn_id = uuid_rev_to_id.get((uuid, rev))
    if not txn_id:
        continue

    mat_name = to_str(row.get('material_name'), 'UNKNOWN') or 'UNKNOWN'
    try:
        cur.execute('''
            INSERT INTO transaction_materials
                (transaction_uuid, transaction_id, material_name, supplier_name,
                 stock_initial, stock_in, stock_return, reject_amount,
                 production_amount, total_usage, stock_final, label_photos)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            RETURNING id
        ''', (
            uuid, txn_id, mat_name,
            to_str(row.get('supplier_name')),
            to_num(row.get('stock_initial')),
            to_num(row.get('stock_in')),
            to_num(row.get('stock_return')),
            to_num(row.get('reject_amount')),
            to_num(row.get('production_amount')),
            to_num(row.get('total_usage')),
            to_num(row.get('stock_final')),
            to_str(row.get('label_photos')),
        ))
        tm_id = cur.fetchone()[0]
        mat_inserted += 1

        # Hourly usage
        for h in range(24):
            col = f'usage_{h:02d}'
            amt = to_num(row.get(col), 0)
            if amt > 0:
                cur.execute('''
                    INSERT INTO material_hourly_usage (transaction_material_id, hour_idx, amount)
                    VALUES (%s,%s,%s)
                    ON CONFLICT (transaction_material_id, hour_idx) DO NOTHING
                ''', (tm_id, h, amt))
                hour_inserted += 1

    except Exception as e:
        conn.rollback()
        fail(f'Material {uuid}/{mat_name}: {e}')
        continue

conn.commit()
ok(f'{mat_inserted} materials diinsert')
ok(f'{hour_inserted} hourly usage entries')
write_progress('transaction_materials', total_mats, total_mats, 'running', 'Material Usage selesai')

# ============================================================
# 5. PRODUCTION_OUTPUTS
# ============================================================
print('\n=== 5. Production Outputs ===')
out_inserted = 0
out_skipped  = 0
total_outputs = len(df_output)
write_progress('production_outputs', 0, total_outputs, 'running', 'Migrasi Production Outputs...')
processed_out = 0

# Kolom Excel: 'Transaction ID','Timestamp','Date','MID','Product Name',
#              'Qty Box','Category Bag','Category Box','Counter (Pcs)',
#              'Total Berat (Kg)','Loss (Kg)','Revision','Status'
for _, row in df_output.iterrows():
    uuid = to_str(row.get('Transaction ID') or row.get('transaction_id'))
    if not is_valid_uuid(uuid): out_skipped += 1; continue
    uuid = uuid.strip().lower()

    raw_status = to_str(row.get('Status') or row.get('status')).upper()
    status = 'HISTORY' if raw_status == 'HISTORY' else 'ACTIVE'

    try:
        cur.execute('''
            INSERT INTO production_outputs
                (transaction_uuid, production_date, mid, product_name, qty_box,
                 category_bag, category_box, counter_pcs, total_weight_kg, loss_kg,
                 revision_count, status)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
        ''', (
            uuid,
            to_date(row.get('Date') or row.get('date')),
            to_str(row.get('MID') or row.get('mid'), '?'),
            to_str(row.get('Product Name') or row.get('product_name'), '-'),
            to_int(row.get('Qty Box') or row.get('qty_box')),
            to_str(row.get('Category Bag') or row.get('category_bag')),
            to_str(row.get('Category Box') or row.get('category_box')),
            to_int(row.get('Counter (Pcs)') or row.get('counter_pcs')),
            to_num(row.get('Total Berat (Kg)') or row.get('total_weight_kg')),
            to_num(row.get('Loss (Kg)') or row.get('loss_kg')),
            to_int(row.get('Revision') or row.get('revision_count')),
            status,
        ))
        out_inserted += 1
    except Exception as e:
        conn.rollback()
        fail(f'Output {uuid}: {e}')
        continue

conn.commit()
ok(f'{out_inserted} production_outputs diinsert, {out_skipped} UUID invalid dilewati')
write_progress('production_outputs', total_outputs, total_outputs, 'running', 'Production Outputs selesai')

# ============================================================
# 6. PRODUCTION_REPORTS
# ============================================================
print('\n=== 6. Production Reports ===')
rep_inserted = 0
rep_skipped  = 0
total_reports = len(df_report)
write_progress('production_reports', 0, total_reports, 'running', 'Migrasi Production Reports...')
processed_rep = 0

# Kolom: 'Transaction ID','Timestamp','Date','Shift','Machine',
#        'Counter (Pcs) [N/A]','Total Output (Kg)','Total Loss (Kg)',
#        'Avg Loss (%)','Downtime (Min)','Downtime (%)','Speed (ppm)',
#        'Trouble Logs','Near Miss','Notes','Reject Printing (Kg)','Revision','Status'
for _, row in df_report.iterrows():
    processed_rep += 1
    if processed_rep % 50 == 0:
        write_progress('production_reports', processed_rep, total_reports, 'running', 'Migrasi Production Reports...')

    uuid = to_str(row.get('Transaction ID') or row.get('transaction_id'))
    if not is_valid_uuid(uuid): rep_skipped += 1; continue
    uuid = uuid.strip().lower()

    raw_status = to_str(row.get('Status') or row.get('status')).upper()
    status = 'HISTORY' if raw_status == 'HISTORY' else 'ACTIVE'
    shift_raw = to_str(row.get('Shift') or row.get('shift_id'))
    try:
        shift = max(1, min(3, int(float(shift_raw)))) if shift_raw.isdigit() or (shift_raw.replace('.','',1).isdigit()) else 1
    except:
        shift = 1

    try:
        cur.execute('''
            INSERT INTO production_reports
                (transaction_uuid, production_date, shift_id, machine_id,
                 total_output_kg, total_loss_kg, avg_loss_pct,
                 downtime_min, downtime_pct, speed_ppm,
                 trouble_logs, near_miss, notes, reject_printing_kg,
                 revision_count, status)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
        ''', (
            uuid,
            to_date(row.get('Date') or row.get('date')),
            shift,
            to_str(row.get('Machine') or row.get('machine_id'), 'Unknown'),
            to_num(row.get('Total Output (Kg)') or row.get('total_output_kg')),
            to_num(row.get('Total Loss (Kg)') or row.get('total_loss_kg')),
            to_num(row.get('Avg Loss (%)') or row.get('avg_loss_pct')),
            to_int(row.get('Downtime (Min)') or row.get('downtime_min')),
            to_num(row.get('Downtime (%)') or row.get('downtime_pct')),
            to_int(row.get('Speed (ppm)') or row.get('speed_ppm')),
            to_str(row.get('Trouble Logs') or row.get('trouble_logs')),
            to_str(row.get('Near Miss') or row.get('near_miss')),
            to_str(row.get('Notes') or row.get('notes')),
            to_num(row.get('Reject Printing (Kg)') or row.get('reject_printing_kg')),
            to_int(row.get('Revision') or row.get('revision_count')),
            status,
        ))
        rep_inserted += 1
    except Exception as e:
        conn.rollback()
        fail(f'Report {uuid}: {e}')
        continue

conn.commit()
ok(f'{rep_inserted} production_reports diinsert, {rep_skipped} UUID invalid dilewati')
write_progress('production_reports', total_reports, total_reports, 'running', 'Production Reports selesai')

# ============================================================
# 7. AUDIT_LOGS
# ============================================================
print('\n=== 7. Audit Logs ===')
log_inserted = 0
total_logs = len(df_logs)
write_progress('audit_logs', 0, total_logs, 'running', 'Migrasi Audit Logs...')
processed_logs = 0

for _, row in df_logs.iterrows():
    processed_logs += 1
    if processed_logs % 100 == 0:
        write_progress('audit_logs', processed_logs, total_logs, 'running', 'Migrasi Audit Logs...')

    actor  = to_str(row.get('Actor') or row.get('actor'))
    action = to_str(row.get('Action') or row.get('action'))
    if not actor and not action: continue
    try:
        cur.execute(
            'INSERT INTO audit_logs (timestamp, actor, action, details) VALUES (%s,%s,%s,%s)',
            (to_ts(row.get('Timestamp') or row.get('timestamp')), actor or '-', action or '-',
             to_str(row.get('Details') or row.get('details')))
        )
        log_inserted += 1
    except Exception as e:
        conn.rollback()
        fail(f'Log: {e}')
        continue

conn.commit()
ok(f'{log_inserted} audit logs')
write_progress('audit_logs', total_logs, total_logs, 'running', 'Audit Logs selesai')

# ============================================================
# Ringkasan akhir
# ============================================================
cur.execute('''
    SELECT 'transactions' AS t, COUNT(*) FROM transactions
    UNION ALL SELECT 'transaction_materials', COUNT(*) FROM transaction_materials
    UNION ALL SELECT 'material_hourly_usage', COUNT(*) FROM material_hourly_usage
    UNION ALL SELECT 'production_outputs',    COUNT(*) FROM production_outputs
    UNION ALL SELECT 'production_reports',    COUNT(*) FROM production_reports
    UNION ALL SELECT 'suppliers',             COUNT(*) FROM suppliers
    UNION ALL SELECT 'material_suppliers',    COUNT(*) FROM material_suppliers
    UNION ALL SELECT 'conversions',           COUNT(*) FROM conversions
    UNION ALL SELECT 'audit_logs',            COUNT(*) FROM audit_logs
    ORDER BY t
''')
print('\n=== RINGKASAN DB ===')
for row in cur.fetchall():
    print(f'  {row[0]:<28} {row[1]:>6} baris')

cur.close(); conn.close()
print(f'\nHasil: PASS={PASS}  FAIL={FAIL}')
if FAIL == 0:
    print('Import selesai tanpa error.')
    write_progress('done', 100, 100, 'done', 'Import Excel Selesai')
    sys.exit(0)
else:
    print('Ada error — lihat baris [FAIL] di atas.')
    write_progress('done', 100, 100, 'done', 'Import selesai dengan beberapa pesan error')
    sys.exit(1)
