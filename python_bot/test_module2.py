import urllib.request, json, base64

BASE = 'http://127.0.0.1:8770/api'
PASS = FAIL = 0

def http(path, data=None, method='POST', token=''):
    url = f"{BASE}/{path}"
    body = json.dumps(data or {}).encode()
    headers = {'Content-Type': 'application/json'}
    if token: headers['Authorization'] = f'Bearer {token}'
    if method == 'GET' and data:
        from urllib.parse import urlencode
        sep = '&' if '?' in url else '?'
        url += sep + urlencode(data)
        body = None
    req = urllib.request.Request(url, body if method != 'GET' else None, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=5) as r:
            return json.loads(r.read())
    except Exception as e:
        return {'success': False, 'message': str(e)}

def chk(label, cond):
    global PASS, FAIL
    sym = 'OK' if cond else 'XX'
    if cond: print(f'  [OK] {label}'); PASS += 1
    else:    print(f'  [XX] {label}'); FAIL += 1

# Login
print('\n=== Setup: Login ===')
http('auth.php?action=logout', {'username':'Group A','machine':'TestM'})
r = http('auth.php?action=login', {'username':'Group A','password':'12345','machine':'TestM'})
chk('login', r.get('success'))
TOKEN = r.get('data',{}).get('token','')

# ── Materials ──────────────────────────────────────────────────────────────────
print('\n=== Materials ===')
r = http('materials.php', method='GET', token=TOKEN)
chk('GET list ok', r.get('success'))
chk('has order', isinstance(r.get('data',{}).get('order'), list))

# Tambah material baru
r = http('materials.php?action=update', {'oldName':'', 'newName':'TEST MATERIAL', 'suppliers':['Sup A','Sup B']}, token=TOKEN)
chk('update (insert) ok', r.get('success'))

# Edit material
r = http('materials.php?action=update', {'oldName':'TEST MATERIAL', 'newName':'TEST MATERIAL EDITED', 'suppliers':['Sup C']}, token=TOKEN)
chk('update (edit) ok', r.get('success'))

# Save list order
r = http('materials.php?action=saveList', {'order':['TEST MATERIAL EDITED']}, token=TOKEN)
chk('saveList ok', r.get('success'))

# Delete material
r = http('materials.php?action=delete', {'name':'TEST MATERIAL EDITED'}, token=TOKEN)
chk('delete ok', r.get('success'))

# ── Conversions ────────────────────────────────────────────────────────────────
print('\n=== Conversions ===')
r = http('conversions.php', method='GET', token=TOKEN)
chk('GET list ok', r.get('success'))

# Insert
r = http('conversions.php?action=save', {'oldMid':'','mid':'TEST01','name':'TEST PRODUK','weight':250,'ratio':1.5,'catBag':'MAT A','catBox':'MAT B'}, token=TOKEN)
chk('save (insert) ok', r.get('success'))

# Duplicate MID should fail
r = http('conversions.php?action=save', {'oldMid':'','mid':'TEST01','name':'LAIN','weight':0,'ratio':0,'catBag':'','catBox':''}, token=TOKEN)
chk('duplicate MID rejected', not r.get('success') and 'sudah' in r.get('message',''))

# Edit
r = http('conversions.php?action=save', {'oldMid':'TEST01','mid':'TEST01','name':'TEST PRODUK EDIT','weight':300,'ratio':2,'catBag':'','catBox':''}, token=TOKEN)
chk('save (edit) ok', r.get('success'))

# Delete
r = http('conversions.php?action=delete', {'mid':'TEST01'}, token=TOKEN)
chk('delete ok', r.get('success'))

# ── Admin stats ────────────────────────────────────────────────────────────────
print('\n=== Admin Stats ===')
r = http('admin.php?action=stats', method='GET', token=TOKEN)
chk('stats ok', r.get('success'))
chk('has totalToday', 'totalToday' in r.get('data',{}))
chk('has chartData', 'chartData' in r.get('data',{}))

# ── verifyAdmin ────────────────────────────────────────────────────────────────
print('\n=== verifyAdmin ===')
r = http('admin.php?action=verifyAdmin', {'pin':'WRONG'}, token=TOKEN)
chk('wrong PIN rejected', r.get('success') and not r.get('data'))
r = http('admin.php?action=verifyAdmin', {'pin':'DAM!@#123'}, token=TOKEN)
chk('correct PIN accepted', r.get('success') and r.get('data'))

# ── Settings ────────────────────────────────────────────────────────────────────
print('\n=== Settings ===')
r = http('settings.php', method='GET', token=TOKEN)
chk('GET ok', r.get('success'))
chk('has enableHandover', 'enableHandover' in r.get('data',{}))

r = http('settings.php', {'broadcastMsg':'Test broadcast','broadcastActive':True}, token=TOKEN)
chk('POST save ok', r.get('success'))

r = http('settings.php', method='GET', token=TOKEN)
chk('broadcast tersimpan', r.get('data',{}).get('broadcastMsg') == 'Test broadcast')

# Reset
http('settings.php', {'broadcastMsg':'','broadcastActive':False}, token=TOKEN)

# ── Photos upload ───────────────────────────────────────────────────────────────
print('\n=== Photos Upload ===')
# Buat data dummy base64 (1x1 PNG)
png1x1_b64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
r = http('photos.php', {'files':[{'base64': png1x1_b64, 'name':'test.png'}]}, token=TOKEN)
chk('upload ok', r.get('success'))
chk('fileIds returned', len(r.get('data',{}).get('fileIds',[])) > 0)
file_id = r.get('data',{}).get('fileIds',[''])[0]
print(f'  fileId: {file_id}')

# ── Config ──────────────────────────────────────────────────────────────────────
print('\n=== Config ===')
r = http('config.php', method='GET', token=TOKEN)
chk('GET ok', r.get('success'))
chk('mesin list', isinstance(r.get('data',{}).get('mesin'), list))

# Cleanup
http('auth.php?action=logout', {'username':'Group A','machine':'TestM'}, token=TOKEN)

print(f'\n{"="*40}')
print(f'Hasil: PASS={PASS}  FAIL={FAIL}')
print('Semua tes lulus!' if FAIL==0 else 'Ada tes gagal.')
