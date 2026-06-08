<?php
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProdAdmin Smoke Test</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --panel: #ffffff;
            --line: #d9e2ec;
            --text: #102a43;
            --muted: #627d98;
            --ok: #1f9d55;
            --bad: #d64545;
            --warn: #c27c0e;
            --accent: #0f6cbd;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: linear-gradient(180deg, #eef4fa 0%, var(--bg) 100%);
            color: var(--text);
        }
        .wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px;
        }
        .hero, .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        }
        .hero { padding: 24px; margin-bottom: 20px; }
        .panel { padding: 18px; }
        h1, h2, h3, p { margin: 0; }
        h1 { font-size: 28px; margin-bottom: 8px; }
        p.sub { color: var(--muted); line-height: 1.5; }
        .grid {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 20px;
        }
        .stack { display: grid; gap: 16px; }
        label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 6px;
        }
        input {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            font: inherit;
        }
        button {
            border: 0;
            border-radius: 10px;
            padding: 12px 14px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            background: var(--accent);
            color: #fff;
        }
        button.secondary {
            background: #eef4fb;
            color: var(--accent);
            border: 1px solid #c9ddf3;
        }
        .row { display: grid; gap: 12px; }
        .actions { display: grid; gap: 10px; margin-top: 14px; }
        .summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .card {
            background: #f8fbff;
            border: 1px solid #dbe8f5;
            border-radius: 12px;
            padding: 14px;
        }
        .card small { display: block; color: var(--muted); margin-bottom: 8px; }
        .card strong { font-size: 22px; }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 13px;
            font-weight: 700;
        }
        .status.ok { color: var(--ok); background: #eaf7ef; }
        .status.bad { color: var(--bad); background: #fdecec; }
        .status.warn { color: var(--warn); background: #fff4de; }
        .test-list { display: grid; gap: 10px; }
        .test {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            background: #fff;
        }
        .test-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .test-name { font-weight: 700; }
        .meta {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
            white-space: pre-wrap;
        }
        pre {
            margin: 12px 0 0;
            padding: 12px;
            background: #0b1f33;
            color: #d9e6f2;
            border-radius: 10px;
            overflow: auto;
            font-size: 12px;
        }
        .links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }
        .links a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 700;
        }
        @media (max-width: 900px) {
            .grid, .summary { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <h1>ProdAdmin Local Smoke Test</h1>
        <p class="sub">Halaman ini mengecek runtime lokal `PROD3` langsung dari browser: konfigurasi, login, sesi, init data, history, stats, dan kontrak endpoint utama. Ini dipakai untuk validasi sebelum migrasi foto dijalankan.</p>
        <div class="links">
            <a href="/ProdAdmin/">Root App</a>
            <a href="/ProdAdmin/tools/setup.php">Setup Portal</a>
            <a href="/ProdAdmin/tools/migrate_photos.php">Photo Migration Tool</a>
        </div>
    </div>

    <div class="grid">
        <div class="stack">
            <div class="panel">
                <h3 style="margin-bottom:14px;">Login Test</h3>
                <div class="row">
                    <div>
                        <label for="username">Username</label>
                        <input id="username" value="Group B">
                    </div>
                    <div>
                        <label for="password">Password</label>
                        <input id="password" type="password" value="12345">
                    </div>
                    <div>
                        <label for="machine">Machine</label>
                        <input id="machine" value="Mesin BHP 5">
                    </div>
                </div>
                <div class="actions">
                    <button id="btnRun">Run Smoke Test</button>
                    <button id="btnLogout" class="secondary" type="button">Logout Current Session</button>
                </div>
            </div>

            <div class="panel">
                <h3 style="margin-bottom:10px;">Current Session</h3>
                <div id="sessionStatus" class="status warn">Belum ada token aktif</div>
                <div class="meta" id="sessionMeta" style="margin-top:10px;">Token akan muncul setelah login test berhasil.</div>
            </div>
        </div>

        <div class="stack">
            <div class="panel">
                <div class="summary">
                    <div class="card">
                        <small>Overall</small>
                        <strong id="sumOverall">Idle</strong>
                    </div>
                    <div class="card">
                        <small>Passed</small>
                        <strong id="sumPassed">0</strong>
                    </div>
                    <div class="card">
                        <small>Failed</small>
                        <strong id="sumFailed">0</strong>
                    </div>
                    <div class="card">
                        <small>Warnings</small>
                        <strong id="sumWarn">0</strong>
                    </div>
                </div>
                <div id="overallStatus" class="status warn">Belum dijalankan</div>
            </div>

            <div class="panel">
                <h3 style="margin-bottom:14px;">Checks</h3>
                <div class="test-list" id="tests"></div>
            </div>
        </div>
    </div>
</div>

<script>
const testsEl = document.getElementById('tests');
const overallStatusEl = document.getElementById('overallStatus');
const sessionStatusEl = document.getElementById('sessionStatus');
const sessionMetaEl = document.getElementById('sessionMeta');
let activeToken = '';
let activeUsername = '';
let activeMachine = '';

function renderTests(results) {
    testsEl.innerHTML = '';
    for (const result of results) {
        const box = document.createElement('div');
        box.className = 'test';
        const statusClass = result.level === 'ok' ? 'ok' : (result.level === 'bad' ? 'bad' : 'warn');
        box.innerHTML = `
            <div class="test-head">
                <div class="test-name">${escapeHtml(result.name)}</div>
                <div class="status ${statusClass}">${escapeHtml(result.label)}</div>
            </div>
            <div class="meta">${escapeHtml(result.meta || '')}</div>
            ${result.payload ? `<pre>${escapeHtml(result.payload)}</pre>` : ''}
        `;
        testsEl.appendChild(box);
    }
}

function setSummary(results) {
    const passed = results.filter(r => r.level === 'ok').length;
    const failed = results.filter(r => r.level === 'bad').length;
    const warnings = results.filter(r => r.level === 'warn').length;
    document.getElementById('sumPassed').textContent = String(passed);
    document.getElementById('sumFailed').textContent = String(failed);
    document.getElementById('sumWarn').textContent = String(warnings);
    document.getElementById('sumOverall').textContent = failed ? 'FAIL' : (warnings ? 'WARN' : 'PASS');
    overallStatusEl.className = 'status ' + (failed ? 'bad' : (warnings ? 'warn' : 'ok'));
    overallStatusEl.textContent = failed
        ? 'Ada endpoint/kontrak yang gagal'
        : (warnings ? 'Runtime jalan, masih ada warning' : 'Semua cek utama lolos');
}

function setSession(token, username, machine) {
    activeToken = token || '';
    activeUsername = username || '';
    activeMachine = machine || '';
    if (activeToken) {
        sessionStatusEl.className = 'status ok';
        sessionStatusEl.textContent = 'Session aktif';
        sessionMetaEl.textContent = `${activeUsername} @ ${activeMachine}\nToken: ${activeToken}`;
    } else {
        sessionStatusEl.className = 'status warn';
        sessionStatusEl.textContent = 'Belum ada token aktif';
        sessionMetaEl.textContent = 'Token akan muncul setelah login test berhasil.';
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"]/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'
    }[ch]));
}

async function api(url, options = {}) {
    const headers = Object.assign({}, options.headers || {});
    if (activeToken) headers.Authorization = `Bearer ${activeToken}`;
    const resp = await fetch(url, Object.assign({}, options, { headers }));
    const text = await resp.text();
    let json = null;
    try { json = text ? JSON.parse(text) : null; } catch (_) {}
    return { resp, text, json };
}

function formatPayload(data) {
    try { return JSON.stringify(data, null, 2); } catch (_) { return String(data); }
}

async function runSmokeTest() {
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const machine = document.getElementById('machine').value.trim();
    const results = [];
    setSession('', '', '');
    renderTests([]);
    overallStatusEl.className = 'status warn';
    overallStatusEl.textContent = 'Sedang menjalankan pengecekan...';

    try {
        const cfg = await api('/ProdAdmin/api/config.php?action=get');
        const cfgOk = cfg.resp.ok && cfg.json && cfg.json.success && Array.isArray(cfg.json.data?.mesin);
        results.push({
            name: 'Config bootstrap',
            level: cfgOk ? 'ok' : 'bad',
            label: cfgOk ? 'PASS' : 'FAIL',
            meta: cfgOk
                ? `Daftar mesin: ${cfg.json.data.mesin.length}, size: ${cfg.json.data.size.length}`
                : `HTTP ${cfg.resp.status}`,
            payload: formatPayload(cfg.json || cfg.text)
        });

        const login = await api('/ProdAdmin/api/auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password, machine })
        });
        const loginOk = login.resp.ok && login.json && login.json.success && login.json.data?.token;
        results.push({
            name: 'Login',
            level: loginOk ? 'ok' : 'bad',
            label: loginOk ? 'PASS' : 'FAIL',
            meta: loginOk ? `Login sukses untuk ${username} @ ${machine}` : `HTTP ${login.resp.status}`,
            payload: formatPayload(login.json || login.text)
        });
        if (!loginOk) {
            renderTests(results);
            setSummary(results);
            return;
        }

        setSession(login.json.data.token, username, machine);

        const validate = await api('/ProdAdmin/api/auth.php?action=validate');
        const validateOk = validate.resp.ok && validate.json && validate.json.success;
        results.push({
            name: 'Session validate',
            level: validateOk ? 'ok' : 'bad',
            label: validateOk ? 'PASS' : 'FAIL',
            meta: validateOk ? 'Bearer token dikenali oleh auth layer.' : `HTTP ${validate.resp.status}`,
            payload: formatPayload(validate.json || validate.text)
        });

        const init = await api('/ProdAdmin/api/init.php');
        const initOk = init.resp.ok && init.json && init.json.success;
        const supplierCount = init.json?.data?.suppliers?.order?.length || 0;
        const convCount = init.json?.data?.conversion?.length || 0;
        results.push({
            name: 'Init payload',
            level: initOk ? 'ok' : 'bad',
            label: initOk ? 'PASS' : 'FAIL',
            meta: initOk ? `Material: ${supplierCount}, conversion: ${convCount}` : `HTTP ${init.resp.status}`,
            payload: formatPayload(init.json || init.text)
        });

        const history = await api('/ProdAdmin/api/history.php?action=paged&limit=3&startDate=2026-01-01&endDate=2026-12-31');
        const historyOk = history.resp.ok && history.json && history.json.success && Array.isArray(history.json.data?.data);
        const historyRows = history.json?.data?.data?.length || 0;
        const firstHistory = history.json?.data?.data?.[0] || null;
        const historyWarn = historyOk && (!firstHistory || !Array.isArray(firstHistory.items) || !Array.isArray(firstHistory.outputs));
        results.push({
            name: 'History',
            level: historyOk ? (historyWarn ? 'warn' : 'ok') : 'bad',
            label: historyOk ? (historyWarn ? 'WARN' : 'PASS') : 'FAIL',
            meta: historyOk ? `Rows: ${historyRows}` : `HTTP ${history.resp.status}`,
            payload: formatPayload(history.json || history.text)
        });

        const stats = await api('/ProdAdmin/api/admin.php?action=stats');
        const statsOk = stats.resp.ok && stats.json && stats.json.success;
        results.push({
            name: 'Admin stats contract',
            level: statsOk ? 'ok' : 'bad',
            label: statsOk ? 'PASS' : 'FAIL',
            meta: statsOk ? `Total produksi: ${stats.json.data?.totalToday ?? 0}` : `HTTP ${stats.resp.status}`,
            payload: formatPayload(stats.json || stats.text)
        });

        const settings = await api('/ProdAdmin/api/settings.php');
        const settingsOk = settings.resp.ok && settings.json && settings.json.success;
        results.push({
            name: 'Settings read',
            level: settingsOk ? 'ok' : 'bad',
            label: settingsOk ? 'PASS' : 'FAIL',
            meta: settingsOk ? 'Pengaturan global terbaca.' : `HTTP ${settings.resp.status}`,
            payload: formatPayload(settings.json || settings.text)
        });

        const photoProgress = await api('/ProdAdmin/api/admin.php?action=photoProgress');
        const photoOk = photoProgress.resp.ok && photoProgress.json && photoProgress.json.success;
        results.push({
            name: 'Photo progress tracker',
            level: photoOk ? 'ok' : 'bad',
            label: photoOk ? 'PASS' : 'FAIL',
            meta: photoOk
                ? `Done: ${photoProgress.json.data?.summary?.doneCount ?? 0}, Pending: ${photoProgress.json.data?.summary?.pendingCount ?? 0}`
                : `HTTP ${photoProgress.resp.status}`,
            payload: formatPayload(photoProgress.json || photoProgress.text)
        });
    } catch (error) {
        results.push({
            name: 'Runtime exception',
            level: 'bad',
            label: 'FAIL',
            meta: error.message || String(error)
        });
    }

    renderTests(results);
    setSummary(results);
}

async function logoutCurrent() {
    if (!activeToken || !activeUsername || !activeMachine) {
        alert('Belum ada session aktif dari smoke test ini.');
        return;
    }
    const resp = await api('/ProdAdmin/api/auth.php?action=logout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: activeUsername, machine: activeMachine })
    });
    if (resp.json && resp.json.success) {
        setSession('', '', '');
        alert('Session sudah di-logout.');
    } else {
        alert('Logout gagal. Cek panel hasil.');
    }
}

document.getElementById('btnRun').addEventListener('click', runSmokeTest);
document.getElementById('btnLogout').addEventListener('click', logoutCurrent);
</script>
</body>
</html>
