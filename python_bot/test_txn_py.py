import urllib.request
import json

BASE = 'http://127.0.0.1:8769/api'
PASS = FAIL = 0

def http(path, data=None, method='POST', token=''):
    url = f"{BASE}/{path}"
    body = json.dumps(data or {}).encode()
    req = urllib.request.Request(url, body if method != 'GET' else None, method=method)
    req.add_header('Content-Type', 'application/json')
    if token: req.add_header('Authorization', f'Bearer {token}')
    if method == 'GET' and data:
        from urllib.parse import urlencode
        url += ('&' if '?' in url else '?') + urlencode(data)
        req = urllib.request.Request(url, method='GET')
        req.add_header('Content-Type', 'application/json')
        if token: req.add_header('Authorization', f'Bearer {token}')
    try:
        with urllib.request.urlopen(req, timeout=5) as r:
            return json.loads(r.read())
    except Exception as e:
        return {'success': False, 'message': str(e)}

def chk(label, cond):
    global PASS, FAIL
    if cond: print(f'  ✅ {label}'); PASS += 1
    else:    print(f'  ❌ {label}'); FAIL += 1

# Login
print('\n=== Login ===')
r = http('auth.php?action=logout', {'username':'Group A','machine':'Mesin BHP 1'})
r = http('auth.php?action=login',  {'username':'Group A','password':'12345','machine':'Mesin BHP 1'})
chk('login ok', r.get('success'))
TOKEN = r.get('data',{}).get('token','')

# Init
print('\n=== init.php ===')
r = http('init.php', method='GET', token=TOKEN)
chk('success', r.get('success'))
chk('suppliers.order', isinstance(r.get('data',{}).get('suppliers',{}).get('order'), list))
chk('conversion list', isinstance(r.get('data',{}).get('conversion'), list))
chk('config.mesin',    isinstance(r.get('data',{}).get('config',{}).get('mesin'), list))

# Submit
print('\n=== submit ===')
materials = [{'name':'MAT PY','supplier':'','stockAwal':100,'masuk':50,'retur':0,'reject':2,
              'hours':[10,10,10,10,10,10,10,10],'photos':[]}]
r = http('transactions.php?action=submit', {
    'tanggal':'2026-05-23','shift':'1','mesin':'Mesin BHP 1','size':'Size M',
    'materialsJson': json.dumps(materials),
    'outputsJson': '[]', 'reportJson': '',
}, token=TOKEN)
chk('success', r.get('success'))
chk('uuid ada', bool(r.get('data',{}).get('uuid','')))
chk('rev=0', r.get('data',{}).get('rev') == 0)
UUID = r.get('data',{}).get('uuid','')
print(f'  UUID: {UUID[:8]}...')

# History check
print('\n=== history ===')
r = http('history.php?action=paged', {'page':1,'limit':10,'startDate':'2026-05-23','endDate':'2026-05-23'}, method='GET', token=TOKEN)
chk('success', r.get('success'))
chk('total >= 1', (r.get('data',{}).get('total',0) or 0) >= 1)
found = any(d.get('id') == UUID for d in r.get('data',{}).get('data',[]))
chk('UUID ada di history', found)

# Revisi
print('\n=== revise ===')
materials[0]['stockAwal'] = 120
r = http('transactions.php?action=revise', {
    'uuid': UUID,'tanggal':'2026-05-23','shift':'1','mesin':'Mesin BHP 1','size':'Size M',
    'materialsJson': json.dumps(materials),
    'outputsJson':'[]','reportJson':'',
}, token=TOKEN)
chk('success', r.get('success'))
chk('rev=1', r.get('data',{}).get('rev') == 1)

# Revisi kedua (rev=2)
materials[0]['stockAwal'] = 130
r = http('transactions.php?action=revise', {
    'uuid':UUID,'tanggal':'2026-05-23','shift':'1','mesin':'Mesin BHP 1','size':'Size M',
    'materialsJson':json.dumps(materials),'outputsJson':'[]','reportJson':'',
}, token=TOKEN)
chk('rev=2 ok', r.get('success') and r.get('data',{}).get('rev') == 2)

# History setelah revisi — hanya 1 transaksi aktif
r = http('history.php?action=paged', {'page':1,'limit':10,'startDate':'2026-05-23','endDate':'2026-05-23'}, method='GET', token=TOKEN)
active = [d for d in r.get('data',{}).get('data',[]) if d.get('id') == UUID]
chk('hanya 1 entry aktif di history', len(active) == 1)
if active: chk('revision=2 di history', active[0].get('revision') == 2)

# Diff
print('\n=== diff ===')
r = http(f'transactions.php?action=diff', {'uuid':UUID}, method='GET', token=TOKEN)
chk('success', r.get('success'))
chk('ada 3 versi (rev 0,1,2)', len(r.get('data',{})) == 3)

# Finalize
print('\n=== finalize ===')
r = http('transactions.php?action=finalize', {'uuid':UUID}, token=TOKEN)
chk('success', r.get('success'))

# previousStock (shift 2 = ambil stock dari shift 1 hari ini)
print('\n=== previousStock ===')
r = http('transactions.php?action=previousStock', {'mesin':'Mesin BHP 1','shift':'2','date':'2026-05-23'}, method='GET', token=TOKEN)
chk('success', r.get('success'))
chk('MAT PY ada', 'MAT PY' in (r.get('data') or {}))

# Delete
print('\n=== delete ===')
r = http('transactions.php?action=delete', {'id':UUID}, token=TOKEN)
chk('success', r.get('success'))

# Logout
http('auth.php?action=logout', {'username':'Group A','machine':'Mesin BHP 1'}, token=TOKEN)

print(f'\n{"═"*40}')
print(f'Hasil: PASS={PASS}  FAIL={FAIL}')
print('✅ Semua tes lulus!' if FAIL==0 else '❌ Ada tes gagal.')
