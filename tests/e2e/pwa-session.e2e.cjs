/*
 * PWA session isolation e2e (service worker, history, offline queue). Not part of `npm test`.
 *
 * Requirements: `npm run build` done, PostgreSQL up, Playwright + a Chromium available:
 *   npm i --no-save playwright            (or PLAYWRIGHT_MODULE=/path/to/node_modules/playwright)
 *   CHROMIUM_PATH=/path/to/chrome.exe     (optional, else Playwright's own browser)
 *
 * Run:  node tests/e2e/pwa-session.e2e.cjs [scenario ...]
 *   scenarios: bug01 warm bug02 switch offline idb back pending offlinelogout upgrade (default: all)
 *   E2E_PORT (8765), E2E_OUT (screenshots/results dir, default os tmp).
 *
 * The script starts its own `php -S` with APP_URL/ASSET_URL pointing to it (.env is not touched),
 * creates two temporary superadmin users and deletes them, their sessions/audit rows and the guest
 * sessions of its own browser (tagged user agent) at the end.
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const ROOT = path.resolve(__dirname, '..', '..');
const PORT = Number(process.env.E2E_PORT || 8765);
const BASE = `http://127.0.0.1:${PORT}`;
const RUN = `pwa-e2e-${Date.now()}`;
const UA = `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 ${RUN}`;
const OUT = process.env.E2E_OUT || path.join(os.tmpdir(), RUN);
const SW_OVERRIDE = path.join(OUT, 'sw-v17.js');
const PASS = 'QaPwaE2e-2026!';
const A = { email: `${RUN}-a@test.local`, name: 'QA Alfa Pwae2e' };
const B = { email: `${RUN}-b@test.local`, name: 'QA Beta Pwae2e' };
const MUNICIPIO = process.env.E2E_MUNICIPIO_NAME || 'Bucaramanga';
const ALL = ['bug01', 'warm', 'bug02', 'switch', 'offline', 'idb', 'back', 'pending', 'offlinelogout', 'upgrade'];
const wanted = process.argv.slice(2).length ? process.argv.slice(2) : ALL;

fs.mkdirSync(OUT, { recursive: true });
const lines = [];
const failures = [];
const log = (s) => { console.log(s); lines.push(s); };
const check = (name, ok, detail = '') => { log(`  ${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`); if (!ok) failures.push(name); };
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const pathOf = (page) => new URL(page.url()).pathname;

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8' }).trim();
}

function createUsers() {
    const out = tinker(`
        $role = \\App\\Models\\Role::where('code', 'R01_SUPERADMIN')->value('id');
        foreach ([['${A.email}', '${A.name}'], ['${B.email}', '${B.name}']] as [$e, $n]) {
            \\App\\Models\\User::create(['name' => $n, 'email' => $e, 'password' => bcrypt('${PASS}'), 'role_id' => $role, 'is_active' => true, 'must_change_password' => false]);
        }
        echo \\App\\Models\\User::where('email', 'like', '${RUN}-%')->count();`);
    if (!out.endsWith('2')) throw new Error(`Could not create QA users: ${out}`);
}

function cleanup() {
    return tinker(`
        $db = \\Illuminate\\Support\\Facades\\DB::class;
        $ids = $db::table('users')->where('email', 'like', '${RUN}-%')->pluck('id');
        $a = $db::table('audit_logs')->whereIn('user_id', $ids)->delete();
        $s = $db::table('sessions')->whereIn('user_id', $ids)->orWhere('user_agent', 'like', '%${RUN}%')->delete();
        $u = $db::table('users')->whereIn('id', $ids)->delete();
        echo "audit_logs=$a sessions=$s users=$u";`);
}

function startServer() {
    const proc = spawn('php', ['-S', `127.0.0.1:${PORT}`, path.join(ROOT, 'tests', 'e2e', 'router.php')], {
        cwd: path.join(ROOT, 'public'),
        env: { ...process.env, APP_URL: BASE, ASSET_URL: BASE, SW_OVERRIDE_FILE: SW_OVERRIDE },
        stdio: 'ignore',
    });
    return proc;
}

async function waitForServer() {
    for (let i = 0; i < 50; i++) {
        try { if ((await fetch(`${BASE}/login`)).ok) return; } catch { /* not up yet */ }
        await sleep(200);
    }
    throw new Error('php -S did not start');
}

// ─── Browser helpers ───

async function newPage(browser, tag) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: 1366, height: 900 } });
    const page = await ctx.newPage();
    page.dialogs = [];
    page.dialogAnswer = true;
    page.on('dialog', d => { page.dialogs.push(d.message()); (page.dialogAnswer ? d.accept() : d.dismiss()).catch(() => {}); });
    page.on('pageerror', e => log(`  [pageerror ${tag}] ${e.message}`));
    page.on('console', m => {
        if (m.type() === 'error' && /react|hook|key/i.test(m.text())) log(`  [react ${tag}] ${m.text().slice(0, 200)}`);
    });
    return { ctx, page };
}

async function login(page, user, { fast = false } = {}) {
    let location = null;
    const onResponse = r => { if (r.request().method() === 'POST' && new URL(r.url()).pathname === '/login') location = r.headers().location || `status ${r.status()}`; };
    page.on('response', onResponse);
    const t0 = Date.now();
    await page.goto(`${BASE}/login`, { waitUntil: fast ? 'load' : 'networkidle' });
    await page.fill('input[type=email]', user.email);
    await page.fill('input[type=password]', PASS);
    await page.click('button[type=submit]');
    const elapsed = Date.now() - t0;
    await page.waitForURL(u => !u.pathname.startsWith('/login'), { timeout: 20000 });
    await page.waitForLoadState('networkidle').catch(() => {});
    page.off('response', onResponse);
    return { elapsed, target: location ? new URL(location, BASE).pathname : null };
}

const swReady = (page) => page.waitForFunction(() => navigator.serviceWorker?.controller, null, { timeout: 20000 });

async function goto(page, p, waitUntil = 'networkidle') {
    try { await page.goto(`${BASE}${p}`, { waitUntil }); } catch { await page.waitForLoadState('load').catch(() => {}); }
}

async function logoutViaUi(page, user, { expectLogin = true } = {}) {
    if (!pathOf(page).startsWith('/perfil')) await goto(page, '/perfil');
    await page.hover(`text=${user.name}`).catch(() => {});
    await page.getByRole('button', { name: 'Cerrar sesión' }).first().click({ force: true });
    if (expectLogin) {
        await page.waitForURL(u => u.pathname.startsWith('/login'), { timeout: 20000 });
        await page.waitForLoadState('networkidle').catch(() => {});
    }
}

const text = (page) => page.evaluate(() => document.body?.innerText || '').catch(() => '');
const leaks = async (page, user) => { const t = await text(page); return t.includes(user.name) || t.includes(user.email); };
const shot = (page, name) => page.screenshot({ path: path.join(OUT, `${name}.png`) }).catch(() => {});

const cacheDump = (page) => page.evaluate(async () => {
    const out = {};
    for (const k of await caches.keys()) out[k] = (await (await caches.open(k)).keys()).map(r => new URL(r.url).pathname).filter(p => !p.startsWith('/build/'));
    return out;
});

const idbRows = (page, store) => page.evaluate((storeName) => new Promise((resolve) => {
    const req = indexedDB.open('ElectoralOffline');
    req.onsuccess = () => {
        const dbh = req.result;
        if (!dbh.objectStoreNames.contains(storeName)) { dbh.close(); return resolve([]); }
        const all = dbh.transaction(storeName).objectStore(storeName).getAll();
        all.onsuccess = () => { dbh.close(); resolve(all.result); };
    };
    req.onerror = () => resolve([]);
}), store);

const pageUserKey = (page, id) => page.evaluate(async (uid) => {
    const d = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(`electoral:${uid}`));
    return Array.from(new Uint8Array(d), b => b.toString(16).padStart(2, '0')).join('');
}, id);

async function authUserId(page) {
    const r = await page.request.get(`${BASE}/perfil`, { headers: { 'X-Inertia': 'true', 'X-Requested-With': 'XMLHttpRequest' } });
    try { return (await r.json()).props?.auth?.user?.id ?? null; } catch { return null; }
}

// ─── Scenarios ───

const scenarios = {
    async bug01(browser) {
        log('== BUG-01: 5 fast logins in a clean browser ==');
        for (let i = 1; i <= 5; i++) {
            const { ctx, page } = await newPage(browser, `bug01-${i}`);
            const { elapsed, target } = await login(page, A, { fast: true });
            const landed = pathOf(page);
            await sleep(10000);
            await goto(page, '/perfil');
            check(`bug01 #${i}`, target === '/' && landed === '/mapa-politico' && pathOf(page) === '/perfil' && (await leaks(page, A)),
                `submit ${elapsed}ms, login→${target}, landed ${landed}, /perfil at +10s → ${pathOf(page)}`);
            await ctx.close();
        }
    },

    async warm(browser) {
        log('== BUG-01 with the SW already installed: 3 logout + fast login cycles ==');
        const { ctx, page } = await newPage(browser, 'warm');
        await login(page, A);
        await swReady(page);
        for (let i = 1; i <= 3; i++) {
            await logoutViaUi(page, A);
            const { elapsed, target } = await login(page, A, { fast: true });
            await sleep(10000);
            await goto(page, '/perfil');
            check(`warm #${i}`, target === '/' && pathOf(page) === '/perfil', `submit ${elapsed}ms, login→${target}, /perfil → ${pathOf(page)}`);
        }
        await ctx.close();
    },

    async bug02(browser) {
        log('== BUG-02: A browses, logs out, goes offline ==');
        const { ctx, page } = await newPage(browser, 'bug02');
        await login(page, A);
        await swReady(page);
        for (const p of ['/perfil', '/gobernador', '/mapa-politico']) await goto(page, p);
        await sleep(1500);
        await logoutViaUi(page, A);
        await sleep(1000);
        log(`  caches after logout: ${JSON.stringify(await cacheDump(page))}`);
        await ctx.setOffline(true);
        for (const p of ['/', '/perfil', '/gobernador', '/mapa-politico']) {
            await goto(page, p, 'load');
            check(`bug02 offline ${p}`, !(await leaks(page, A)), `title "${await page.title()}"`);
        }
        await shot(page, 'bug02-offline');
        await ctx.close();
    },

    async switch(browser) {
        log('== A logout → B login in the same browser ==');
        const { ctx, page } = await newPage(browser, 'switch');
        await login(page, A);
        await swReady(page);
        await goto(page, '/perfil');
        await goto(page, '/gobernador');
        await logoutViaUi(page, A);
        await login(page, B);
        await sleep(3000);
        for (const p of ['/perfil', '/gobernador']) {
            await goto(page, p);
            check(`switch online ${p}`, !(await leaks(page, A)));
        }
        await ctx.setOffline(true);
        for (const p of ['/perfil', '/gobernador', '/mapa-politico']) {
            await goto(page, p, 'load');
            const t = await text(page);
            check(`switch offline ${p}`, !(await leaks(page, A)), `B visible=${t.includes(B.name)}`);
        }
        await shot(page, 'switch-b-offline');
        await ctx.close();
    },

    async offline(browser) {
        log('== Logged-in user keeps the offline mode ==');
        const { ctx, page } = await newPage(browser, 'offline');
        await login(page, A);
        await swReady(page);
        await goto(page, '/perfil');
        await sleep(3000);
        log(`  caches: ${JSON.stringify(await cacheDump(page))}`);
        await ctx.setOffline(true);
        for (const p of ['/perfil', '/gobernador', '/mapa-politico']) {
            await goto(page, p, 'load');
            await sleep(500);
            check(`offline logged-in ${p}`, (await leaks(page, A)) && !(await page.title()).startsWith('Sin conexión'), `title "${await page.title()}"`);
        }
        await goto(page, '/analisis', 'load');
        check('offline never-visited /analisis shows the offline page', (await page.title()).startsWith('Sin conexión'));
        await shot(page, 'offline-loggedin');
        await ctx.setOffline(false);
        await ctx.close();
    },

    async idb(browser) {
        log('== Offline download (IndexedDB), then logout ==');
        const { ctx, page } = await newPage(browser, 'idb');
        await login(page, A);
        await swReady(page);
        const munId = tinker(`echo \\Illuminate\\Support\\Facades\\DB::table('geographic_units')->where('type', 'municipality')->whereRaw('canonical_name ilike ?', ['${MUNICIPIO}'])->value('id');`);
        await goto(page, `/mapa-politico?municipio=${munId}`);
        await page.getByRole('button', { name: 'Guardar sin internet' }).click();
        await page.getByText('Guardado', { exact: true }).waitFor({ timeout: 60000 });
        const downloaded = (await idbRows(page, 'downloads')).length;
        await ctx.setOffline(true);
        await goto(page, '/mapa-politico', 'load');
        await page.getByText('Mostrando datos guardados').waitFor({ timeout: 10000 }).catch(() => {});
        check('idb offline shows saved data', downloaded === 1 && (await text(page)).includes('Mostrando datos guardados'));
        await shot(page, 'idb-offline-loggedin');
        await ctx.setOffline(false);
        await logoutViaUi(page, A);
        check('idb downloads purged on logout', (await idbRows(page, 'downloads')).length === 0 && (await idbRows(page, 'personas')).length === 0);
        await ctx.close();
    },

    async back(browser) {
        log('== P-1: Back after logout must not restore A\'s pages ==');
        for (const offline of [false, true]) {
            const { ctx, page } = await newPage(browser, `back-${offline ? 'offline' : 'online'}`);
            await login(page, A);
            await swReady(page);
            await page.hover(`text=${A.name}`);
            await page.getByRole('link', { name: 'Mi perfil y WhatsApp' }).first().click({ force: true });
            await page.waitForURL(u => u.pathname === '/perfil');
            await page.waitForLoadState('networkidle').catch(() => {});
            const state = await page.evaluate(() => JSON.stringify(window.history.state || {}));
            check(`back(${offline ? 'offline' : 'online'}) history.state is encrypted`, !state.includes(A.email) && !state.includes(A.name));
            await logoutViaUi(page, A);
            if (offline) await ctx.setOffline(true);
            await page.goBack({ waitUntil: 'load' }).catch(() => {});
            await sleep(2500);
            await page.waitForLoadState('load').catch(() => {});
            check(`back(${offline ? 'offline' : 'online'}) shows nothing of A`, !(await leaks(page, A)), `now at ${pathOf(page)} "${await page.title()}"`);
            await shot(page, `back-${offline ? 'offline' : 'online'}`);
            await ctx.close();
        }
    },

    async pending(browser) {
        log('== P-2: queued offline edits stay with their owner ==');
        // B must never send nor see A's queued edit.
        const { ctx, page } = await newPage(browser, 'pending-b');
        await login(page, B);
        await swReady(page);
        const idA = tinker(`echo \\App\\Models\\User::where('email', '${A.email}')->value('id');`);
        const keyA = await pageUserKey(page, idA);
        await page.evaluate((owner) => new Promise((resolve) => {
            const req = indexedDB.open('ElectoralOffline');
            req.onsuccess = () => {
                const tx = req.result.transaction('pendingChanges', 'readwrite');
                tx.objectStore('pendingChanges').add({ type: 'persona', action: 'update', data: { id: 'qa-e2e-a-only', telefono: '3000000000' }, owner, synced: 0, createdAt: new Date().toISOString() });
                tx.oncomplete = () => { req.result.close(); resolve(); };
            };
        }), keyA);
        const sent = [];
        page.on('request', r => { if (r.url().includes('qa-e2e-a-only')) sent.push(r.method()); });
        await goto(page, '/perfil');
        await sleep(3000);
        check('pending: B never sends A\'s edit', sent.length === 0, `requests=${JSON.stringify(sent)}`);
        check('pending: A\'s edit is discarded when B logs in', (await idbRows(page, 'pendingChanges')).length === 0);
        await ctx.close();

        // A with an edit that cannot sync: logout asks first.
        const second = await newPage(browser, 'pending-a');
        const pa = second.page;
        await login(pa, A);
        await swReady(pa);
        const ownKey = await pageUserKey(pa, idA);
        await second.ctx.setOffline(true);
        await pa.evaluate((owner) => new Promise((resolve) => {
            const req = indexedDB.open('ElectoralOffline');
            req.onsuccess = () => {
                const tx = req.result.transaction('pendingChanges', 'readwrite');
                tx.objectStore('pendingChanges').add({ type: 'persona', action: 'update', data: { id: 'qa-e2e-missing-person', nombre: 'x' }, owner, synced: 0, createdAt: new Date().toISOString() });
                tx.oncomplete = () => { req.result.close(); resolve(); };
            };
        }), ownKey);
        await second.ctx.setOffline(false);
        await sleep(1500);
        pa.dialogAnswer = false;
        await logoutViaUi(pa, A, { expectLogin: false });
        await sleep(2500);
        check('pending: logout with unsynced edits asks first', pa.dialogs.some(m => m.includes('sin sincronizar')), JSON.stringify(pa.dialogs));
        check('pending: cancel keeps the session and the edit', pathOf(pa) !== '/login' && (await idbRows(pa, 'pendingChanges')).length === 1);
        pa.dialogAnswer = true;
        await logoutViaUi(pa, A);
        check('pending: confirm logs out and discards the edit', pathOf(pa) === '/login' && (await idbRows(pa, 'pendingChanges')).length === 0);
        await second.ctx.close();
    },

    async offlinelogout(browser) {
        log('== P-3: logout without network ==');
        const { ctx, page } = await newPage(browser, 'offlinelogout');
        await login(page, A);
        await swReady(page);
        await goto(page, '/perfil');
        await ctx.setOffline(true);
        await logoutViaUi(page, A, { expectLogin: false });
        await sleep(3000);
        check('offline logout warns the user', page.dialogs.some(m => m.includes('No se pudo cerrar la sesión en el servidor')), JSON.stringify(page.dialogs));
        check('offline logout leaves no A content on screen', !(await leaks(page, A)), `"${await page.title()}"`);
        check('offline logout purged the cached pages', !(await cacheDump(page))['electoral-pages-v18']);
        await shot(page, 'offline-logout');
        await ctx.setOffline(false);
        await goto(page, '/');
        const closed = await page.waitForURL(u => u.pathname.startsWith('/login'), { timeout: 20000 }).then(() => true, () => false);
        await shot(page, 'offline-logout-back-online');
        check('back online the pending logout closes the server session', closed && (await authUserId(page)) === null, `at ${pathOf(page)}`);
        await ctx.close();
    },

    async upgrade(browser) {
        log('== Client with SW v17 installed receives the current SW ==');
        fs.writeFileSync(SW_OVERRIDE, execFileSync('git', ['show', '9e0e3af^:public/sw.js'], { cwd: ROOT }));
        fs.writeFileSync(`${SW_OVERRIDE}.active`, '');
        const { ctx, page } = await newPage(browser, 'upgrade');
        try {
            await login(page, A);
            await swReady(page);
            for (const p of ['/perfil', '/gobernador', '/mapa-politico']) await goto(page, p);
            await sleep(1500);
            log(`  v17 caches: ${JSON.stringify(await cacheDump(page))}`);
            await logoutViaUi(page, A);
        } finally {
            fs.rmSync(`${SW_OVERRIDE}.active`, { force: true });
        }
        await goto(page, '/login');
        await page.evaluate(() => navigator.serviceWorker.getRegistration().then(r => r && r.update()));
        for (let i = 0; i < 40 && (await page.evaluate(() => caches.keys())).includes('electoral-v17'); i++) await sleep(500);
        log(`  after deploy: ${JSON.stringify(await cacheDump(page))}`);
        await ctx.setOffline(true);
        for (const p of ['/perfil', '/gobernador', '/mapa-politico']) {
            await goto(page, p, 'load');
            check(`upgrade offline ${p}`, !(await leaks(page, A)), `"${await page.title()}"`);
        }
        await ctx.close();
    },
};

(async () => {
    const server = startServer();
    let browser;
    try {
        await waitForServer();
        createUsers();
        browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
        for (const name of wanted) {
            try {
                await scenarios[name](browser);
            } catch (e) {
                check(`${name} crashed`, false, e.message.split('\n')[0]);
            }
        }
    } finally {
        if (browser) await browser.close();
        try { log(`cleanup: ${cleanup()}`); } catch (e) { log(`cleanup FAILED: ${e.message}`); failures.push('cleanup'); }
        server.kill();
        log(`\n${failures.length ? `FAILED (${failures.length}): ${failures.join(', ')}` : 'ALL PASSED'} — output in ${OUT}`);
        fs.writeFileSync(path.join(OUT, 'results.txt'), lines.join('\n'));
        process.exitCode = failures.length ? 1 : 0;
    }
})();
