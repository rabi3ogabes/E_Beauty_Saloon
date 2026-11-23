/**
 * Luxe Salon OS — POS offline mode.
 *
 *  - registers the /pos service worker (app shell + catalog cache),
 *  - keeps a copy of the catalog in IndexedDB,
 *  - when the connection drops, shows a minimal cash / card-terminal sell screen and queues each sale
 *    in IndexedDB with a client-generated ULID idempotency key,
 *  - when back online, POSTs the queue to /pos/sync. The server dedupes by key (a sale is never booked twice)
 *    and returns a stock-conflict report.
 *
 * Vanilla JS on purpose: it must work without Livewire round-trips.
 */

const DB_NAME = 'luxe-pos';
const DB_VERSION = 1;

// ─── ULID (Crockford base32, time-sortable) ────────────────────────────────
const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
export function ulid(now = Date.now()) {
    let time = '';
    for (let i = 9; i >= 0; i--) {
        time = ENCODING[now % 32] + time;
        now = Math.floor(now / 32);
    }
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    let rand = '';
    for (let i = 0; i < 16; i++) rand += ENCODING[bytes[i] % 32];
    return time + rand;
}

// ─── IndexedDB helpers ─────────────────────────────────────────────────────
function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', { keyPath: 'idempotency_key' });
            if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv');
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function tx(store, mode, fn) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const t = db.transaction(store, mode);
        const result = fn(t.objectStore(store));
        t.oncomplete = () => resolve(result && 'result' in result ? result.result : result);
        t.onerror = () => reject(t.error);
    });
}

export const queue = {
    all: () => tx('queue', 'readonly', (s) => s.getAll()),
    put: (sale) => tx('queue', 'readwrite', (s) => s.put(sale)),
    remove: (key) => tx('queue', 'readwrite', (s) => s.delete(key)),
    count: () => tx('queue', 'readonly', (s) => s.count()),
};
const kv = {
    get: (key) => tx('kv', 'readonly', (s) => s.get(key)),
    set: (key, value) => tx('kv', 'readwrite', (s) => s.put(value, key)),
};

// ─── Money & tax (mirrors App\Domain\POS\Support\TicketCalculator) ─────────
const fmt = (minor, currency, locale) => {
    const n = (minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return locale === 'ar' ? `${n} ${currency === 'QAR' ? 'ر.ق' : currency}` : `${currency} ${n}`;
};

export function ticketTotal(lines, taxPercent, inclusive) {
    const bp = Math.round(taxPercent * 100);
    return lines.reduce((sum, l) => {
        const net = l.price * l.qty;
        return sum + (bp > 0 && !inclusive ? net + Math.round((net * bp) / 10000) : net);
    }, 0);
}

// ─── Offline sell screen ───────────────────────────────────────────────────
const state = { root: null, cfg: null, catalog: [], ticket: [], method: 'cash', syncing: false, timer: null };

const el = (tag, attrs = {}, children = []) => {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => (k === 'class' ? (node.className = v) : k.startsWith('on') ? node.addEventListener(k.slice(2), v) : node.setAttribute(k, v)));
    [].concat(children).forEach((c) => node.append(c instanceof Node ? c : document.createTextNode(String(c))));
    return node;
};

function t(key) {
    return (state.cfg?.i18n || {})[key] || key;
}

function renderOverlay() {
    const { root, cfg } = state;
    const panel = root.querySelector('[data-offline-panel]');
    if (!panel) return;
    panel.innerHTML = '';
    const total = ticketTotal(state.ticket, cfg.taxPercent, cfg.taxInclusive);

    const search = el('input', { class: 'pos-input', type: 'search', placeholder: t('search'), 'aria-label': t('search') });
    const list = el('div', { class: 'pos-offline-list' });
    const draw = () => {
        const q = search.value.trim().toLowerCase();
        list.innerHTML = '';
        state.catalog
            .filter((i) => !q || i.name.toLowerCase().includes(q) || i.barcode === q || i.sku === q)
            .slice(0, 60)
            .forEach((item) =>
                list.append(el('button', { type: 'button', class: 'pos-tile', onclick: () => addLine(item) }, [
                    el('span', { class: 'pos-tile-name' }, item.name),
                    el('span', { class: 'pos-tile-price' }, fmt(item.price, cfg.currency, cfg.locale)),
                ])),
            );
        if (!list.children.length) list.append(el('p', { class: 'pos-muted' }, t('no_catalog')));
    };
    search.addEventListener('input', draw);
    search.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        const hit = state.catalog.find((i) => i.barcode === search.value.trim() || i.sku === search.value.trim());
        if (hit) { addLine(hit); search.value = ''; draw(); }
    });
    draw();

    const ticket = el('div', { class: 'pos-offline-ticket' });
    state.ticket.forEach((line, idx) =>
        ticket.append(el('div', { class: 'pos-line' }, [
            el('span', { class: 'pos-line-name' }, `${line.qty} × ${line.name}`),
            el('span', {}, fmt(line.price * line.qty, cfg.currency, cfg.locale)),
            el('button', { type: 'button', class: 'pos-icon-btn', 'aria-label': t('remove'), onclick: () => { state.ticket.splice(idx, 1); renderOverlay(); } }, '×'),
        ])),
    );

    const tendered = el('input', { class: 'pos-input', inputmode: 'decimal', placeholder: t('tendered') });
    const reference = el('input', { class: 'pos-input', placeholder: t('reference') });
    const methodBtn = (m) => el('button', { type: 'button', class: `pos-chip ${state.method === m ? 'is-active' : ''}`, onclick: () => { state.method = m; renderOverlay(); } }, t(m));
    const save = el('button', { type: 'button', class: 'pos-primary', onclick: () => saveSale(total, tendered.value, reference.value) }, `${t('save')} · ${fmt(total, cfg.currency, cfg.locale)}`);
    if (!state.ticket.length) save.setAttribute('disabled', 'disabled');

    panel.append(
        el('div', { class: 'pos-offline-grid' }, [
            el('div', {}, [search, list]),
            el('div', { class: 'pos-card' }, [
                el('h3', { class: 'pos-h3' }, t('ticket')),
                ticket,
                el('div', { class: 'pos-total' }, [el('span', {}, t('total')), el('strong', {}, fmt(total, cfg.currency, cfg.locale))]),
                el('div', { class: 'pos-chips' }, [methodBtn('cash'), methodBtn('card_terminal')]),
                state.method === 'cash' ? tendered : reference,
                save,
            ]),
        ]),
    );
}

function addLine(item) {
    const existing = state.ticket.find((l) => l.type === item.type && l.id === item.id);
    existing ? existing.qty++ : state.ticket.push({ ...item, qty: 1 });
    renderOverlay();
}

async function saveSale(total, tenderedInput, reference) {
    const { cfg } = state;
    const tendered = Math.round(parseFloat(String(tenderedInput || '').replace(',', '.')) * 100) || total;
    if (state.method === 'cash' && tendered < total) {
        toast(t('underpaid'));
        return;
    }
    const sale = {
        idempotency_key: ulid(),
        branch_id: cfg.branchId,
        register_id: cfg.registerId,
        shift_id: cfg.shiftId,
        lines: state.ticket.map((l) => ({ type: l.type, id: l.id, qty: l.qty })),
        payments: state.method === 'cash' ? [{ method: 'cash', tendered }] : [{ method: 'card_terminal', amount: total, reference: reference || null }],
        offline_created_at: new Date().toISOString(),
        _total: total,
    };
    await queue.put(sale);
    state.ticket = [];
    toast(state.method === 'cash' && tendered > total ? `${t('saved')} · ${t('change')} ${fmt(tendered - total, cfg.currency, cfg.locale)}` : t('saved'));
    renderOverlay();
    updateStatus();
}

function toast(message) {
    const box = state.root?.querySelector('[data-offline-toast]');
    if (!box) return;
    box.textContent = message;
    box.hidden = false;
    clearTimeout(box._t);
    box._t = setTimeout(() => (box.hidden = true), 5000);
}

async function updateStatus() {
    const count = await queue.count().catch(() => 0);
    document.querySelectorAll('[data-pos-sync-status]').forEach((node) => {
        node.hidden = count === 0 && navigator.onLine;
        node.textContent = navigator.onLine ? t('pending').replace(':count', count) : `${t('offline')} · ${t('pending').replace(':count', count)}`;
    });
}

// ─── Sync ──────────────────────────────────────────────────────────────────
export async function sync() {
    if (state.syncing || !navigator.onLine || !state.cfg) return;
    const sales = await queue.all();
    if (!sales.length) return updateStatus();
    state.syncing = true;
    try {
        const response = await fetch(state.cfg.syncUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': state.cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ sales: sales.map(({ _total, _error, ...s }) => s) }),
        });
        if (response.status === 419 || response.status === 401) {
            toast(t('sign_in_again'));
            return;
        }
        if (!response.ok) return;
        const report = await response.json();
        for (const result of report.results || []) {
            if (result.status === 'created' || result.status === 'duplicate') {
                await queue.remove(result.idempotency_key);
            } else {
                const sale = sales.find((s) => s.idempotency_key === result.idempotency_key);
                if (sale) await queue.put({ ...sale, _error: result.error });
            }
        }
        const synced = (report.results || []).filter((r) => r.status === 'created').length;
        if (synced) toast(t('synced').replace(':count', synced));
        if ((report.conflicts || []).length) {
            const lines = report.conflicts.map((c) => `${c.name}: ${c.balance_after}`).join(' · ');
            toast(`${t('conflicts')} ${lines}`);
            window.dispatchEvent(new CustomEvent('pos-sync-conflicts', { detail: report.conflicts }));
        }
        window.dispatchEvent(new CustomEvent('pos-synced', { detail: report }));
    } catch (e) {
        // still offline / server unreachable: keep the queue
    } finally {
        state.syncing = false;
        updateStatus();
    }
}

async function refreshCatalog() {
    if (!state.cfg) return;
    if (navigator.onLine) {
        try {
            const res = await fetch(state.cfg.catalogUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (res.ok) {
                const data = await res.json();
                await kv.set('catalog', data);
                state.catalog = data.items || [];
                return;
            }
        } catch (e) { /* fall back to the stored copy */ }
    }
    const stored = await kv.get('catalog').catch(() => null);
    state.catalog = stored?.items || [];
}

function setOnline(online) {
    const overlay = state.root?.querySelector('[data-offline-overlay]');
    document.documentElement.classList.toggle('pos-is-offline', !online);
    if (overlay) overlay.hidden = online;
    if (!online) {
        refreshCatalog().then(renderOverlay);
    } else {
        sync();
    }
    updateStatus();
}

// ─── Boot ──────────────────────────────────────────────────────────────────
function boot() {
    const root = document.querySelector('[data-pos-offline]');
    if (!root) return;
    state.root = root;
    state.cfg = JSON.parse(root.dataset.posOffline || '{}');

    if ('serviceWorker' in navigator && state.cfg.swUrl) {
        navigator.serviceWorker.register(state.cfg.swUrl, { scope: '/pos/' }).catch(() => null);
    }
    refreshCatalog();
    setOnline(navigator.onLine);
    clearInterval(state.timer);
    state.timer = setInterval(sync, 30000);
}

if (!window.__luxePosOffline) {
    window.__luxePosOffline = true;
    window.addEventListener('online', () => setOnline(true));
    window.addEventListener('offline', () => setOnline(false));
    document.addEventListener('livewire:navigated', boot);
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

window.LuxePos = { sync, queue, ulid };
