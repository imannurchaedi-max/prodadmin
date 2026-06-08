"""
Tes End-to-End FASE 4:
login -> init -> submit (2 material) -> history -> revisi -> finalize -> logout
"""
import urllib.request, json, sys

BASE  = 'http://127.0.0.1:8771/api'
PASS  = FAIL = 0

def api(path, data=None, method='POST', token=''):
    url = f"{BASE}/{path}"
    body = json.dumps(data or {}).encode() if method != 'GET' else None
    if method == 'GET' and data:
        from urllib.parse import urlencode
        url += ('&' if '?' in url else '?') + urlencode(data)
    req = urllib.request.Request(url, body, method=method)
    req.add_header('Content-Type', 'application/json')
    if token: req.add_header('Authorization', f'Bearer {token}')
    try:
        with urllib.request.urlopen(req, timeout=8) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        # Server returns 401 for invalid tokens — treat as success:false
        try: return json.loads(e.read())
        except: return {'success': False, 'message': f'HTTP {e.code}'}

def chk(label, cond, msg=''):
    global PASS, FAIL
    if cond:
        print(f'  [OK] {label}'); PASS += 1
    else:
        print(f'  [FAIL] {label}' + (f' — {msg}' if msg else '')); FAIL += 1

TODAY = __import__('datetime').date.today().isoformat()

print('\n' + '='*50)
print('TES END-TO-END PRODADMIN PHP')
print('='*50)

# ── 1. LOGIN ──────────────────────────────────────────────────────────────────
print('\n[1] LOGIN')
api('auth.php?action=logout', {'username':'Group B','machine':'Mesin BHP 2'})
r = api('auth.php?action=login', {'username':'Group B','password':'12345','machine':'Mesin BHP 2'})
chk('Login Group B berhasil', r.get('success'), r.get('message',''))
TOKEN = r.get('data',{}).get('token','')
chk('Token diterima', bool(TOKEN))

# ── 2. VALIDATE SESSION ───────────────────────────────────────────────────────
print('\n[2] VALIDATE SESSION')
r = api('auth.php?action=validate', method='GET', token=TOKEN)
chk('Session valid', r.get('success'))
chk('Username = Group B', r.get('data',{}).get('username') == 'Group B')
chk('Bukan admin', not r.get('data',{}).get('isAdmin', True))

# ── 3. INIT DATA ──────────────────────────────────────────────────────────────
print('\n[3] INIT DATA (suppliers + conversion + config)')
r = api('init.php', method='GET', token=TOKEN)
chk('Init berhasil', r.get('success'))
d = r.get('data', {})
chk('Suppliers ada', isinstance(d.get('suppliers',{}).get('order'), list))
chk('Conversion ada', isinstance(d.get('conversion'), list))
chk('Config.mesin ada', isinstance(d.get('config',{}).get('mesin'), list))
n_mesin = len(d.get('config',{}).get('mesin', []))
chk(f'Mesin >= 1 (ada {n_mesin})', n_mesin >= 1)

# ── 4. SUBMIT TRANSAKSI (2 material, dengan output & report) ──────────────────
print('\n[4] SUBMIT TRANSAKSI (2 material)')
materials = [
    {
        'name':'POLIPROPILENA','supplier':'PT Suplier A',
        'stockAwal':500,'masuk':200,'retur':10,'reject':5,
        'hours':[20,25,22,18,24,23,21,19],'photos':[]
    },
    {
        'name':'POLYETHYLENE','supplier':'',
        'stockAwal':300,'masuk':0,'retur':0,'reject':2,
        'hours':[15,15,15,15,15,15,15,15],'photos':[]
    },
]
outputs = [{'mid':'E2E01','name':'PRODUK E2E','catBag':'POLIPROPILENA','catBox':'POLYETHYLENE','qtyBox':50,'counterPcs':5000,'totalKg':125.0,'lossKg':3.5}]
report  = {'counterKg':'125.00','lossKg':'3.50','lossPct':'2.80','rejectPrintingKg':1,'speed':480,'downtimeMin':15,'downtimePct':'3.13%','trouble':'Tidak ada','nearMiss':'','notes':'Tes e2e FASE 4','itemsDetail':[{'mid':'E2E01','counterPcs':5000,'totalKg':125.0,'lossKg':3.5}]}

r = api('transactions.php?action=submit', {
    'tanggal': TODAY, 'shift':'2', 'mesin':'Mesin BHP 2', 'size':'Size L',
    'materialsJson': json.dumps(materials),
    'outputsJson':   json.dumps(outputs),
    'reportJson':    json.dumps(report),
}, token=TOKEN)
chk('Submit berhasil', r.get('success'), r.get('message',''))
chk('UUID dikembalikan', bool(r.get('data',{}).get('uuid','')))
chk('Rev = 0', r.get('data',{}).get('rev') == 0)
UUID = r.get('data',{}).get('uuid','')
print(f'  UUID: {UUID[:8]}...')

# ── 5. PREVIOUS STOCK (shift 3 ambil dari shift 2 = yang baru disubmit) ──────
print('\n[5] PREVIOUS STOCK (shift 3 lihat stok dari shift 2)')
r = api('transactions.php?action=previousStock', {'mesin':'Mesin BHP 2','shift':'3','date':TODAY}, method='GET', token=TOKEN)
chk('Previous stock berhasil', r.get('success'))
stock = r.get('data', {})
chk('Ada data POLIPROPILENA', 'POLIPROPILENA' in stock)
if 'POLIPROPILENA' in stock:
    # stock_final = 500+200-10-(172+5) = 513
    expected = 500 + 200 - 10 - (sum([20,25,22,18,24,23,21,19]) + 5)
    chk(f'Stock POLIPROPILENA = {expected} (kalkulasi server)', abs(float(stock['POLIPROPILENA']) - expected) < 0.01)

# ── 6. HISTORY — UUID muncul ─────────────────────────────────────────────────
print('\n[6] HISTORY — transaksi muncul di paged view')
r = api('history.php?action=paged', {'page':1,'limit':20,'startDate':TODAY,'endDate':TODAY}, method='GET', token=TOKEN)
chk('History berhasil', r.get('success'))
items = r.get('data',{}).get('data',[])
found = next((x for x in items if x.get('id') == UUID), None)
chk('UUID muncul di history', found is not None)
if found:
    chk('Status = DRAFT', found.get('status') == 'DRAFT')
    chk('Shift = 2', found.get('shift') == '2')
    chk('Owner = Group B', found.get('owner') == 'Group B')
    chk('2 items material', len(found.get('items',[])) == 2)
    chk('Ada output produk', len(found.get('outputs',[])) > 0)
    chk('Ada logs report', found.get('logs') is not None)

# ── 7. REVISI (ubah stock awal material pertama) ───────────────────────────────
print('\n[7] REVISI — DRAFT lama jadi HISTORY, DRAFT baru rev=1')
materials[0]['stockAwal'] = 520  # ubah
r = api('transactions.php?action=revise', {
    'uuid': UUID, 'tanggal': TODAY, 'shift':'2', 'mesin':'Mesin BHP 2', 'size':'Size L',
    'materialsJson': json.dumps(materials),
    'outputsJson':   json.dumps(outputs),
    'reportJson':    json.dumps(report),
}, token=TOKEN)
chk('Revisi berhasil', r.get('success'), r.get('message',''))
chk('Rev = 1', r.get('data',{}).get('rev') == 1)

# History setelah revisi — hanya 1 entry aktif
r = api('history.php?action=paged', {'page':1,'limit':20,'startDate':TODAY,'endDate':TODAY}, method='GET', token=TOKEN)
items2 = r.get('data',{}).get('data',[])
active = [x for x in items2 if x.get('id') == UUID]
chk('Hanya 1 entry aktif (HISTORY lama tersembunyi)', len(active) == 1)
if active:
    chk('Revision = 1 di history', active[0].get('revision') == 1)

# ── 8. DIFF — lihat semua revisi ──────────────────────────────────────────────
print('\n[8] DIFF — semua versi transaksi')
r = api('transactions.php?action=diff', {'uuid': UUID}, method='GET', token=TOKEN)
chk('Diff berhasil', r.get('success'))
versions = r.get('data', {})
chk('Ada 2 versi (rev 0 dan 1)', len(versions) == 2)

# ── 9. FINALIZE ───────────────────────────────────────────────────────────────
print('\n[9] FINALIZE — ubah status ke FINAL')
r = api('transactions.php?action=finalize', {'uuid': UUID}, token=TOKEN)
chk('Finalize berhasil', r.get('success'))

# Cek di history
r = api('history.php?action=paged', {'page':1,'limit':20,'startDate':TODAY,'endDate':TODAY}, method='GET', token=TOKEN)
items3 = [x for x in r.get('data',{}).get('data',[]) if x.get('id') == UUID]
chk('Status FINAL di history', items3 and items3[0].get('status') == 'FINAL')

# ── 10. ADMIN STATS ───────────────────────────────────────────────────────────
print('\n[10] ADMIN STATS — data hari ini terhitung')
r = api('admin.php?action=stats', method='GET', token=TOKEN)
chk('Stats berhasil', r.get('success'))
chk('totalToday > 0', (r.get('data',{}).get('totalToday') or 0) > 0)

# ── 11. LOGOUT ────────────────────────────────────────────────────────────────
print('\n[11] LOGOUT')
r = api('auth.php?action=logout', {'username':'Group B','machine':'Mesin BHP 2'}, token=TOKEN)
chk('Logout berhasil', r.get('success'))

# Token tidak valid lagi
r = api('auth.php?action=validate', method='GET', token=TOKEN)
chk('Token tidak valid setelah logout', not r.get('success'))

# Cleanup — hapus data tes
try:
    r2 = api('auth.php?action=login', {'username':'Group B','password':'12345','machine':'Mesin BHP 2'})
    t2 = r2.get('data',{}).get('token','')
    if t2:
        api('transactions.php?action=delete', {'id': UUID}, token=t2)
        api('auth.php?action=logout', {'username':'Group B','machine':'Mesin BHP 2'}, token=t2)
except: pass

# ── HASIL ─────────────────────────────────────────────────────────────────────
print(f'\n{"="*50}')
print(f'Hasil: PASS={PASS}  FAIL={FAIL}')
if FAIL == 0:
    print('SEMUA TES END-TO-END LULUS!')
    sys.exit(0)
else:
    print('ADA TES GAGAL.')
    sys.exit(1)
