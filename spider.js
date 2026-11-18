/* ============================================================
   SPIDER UI — shared JS (admin + reseller panel)
   SP.* helpers, dialogs, API client, KeyList component
============================================================ */
(function () {
'use strict';
const SP = window.SP = {};

// ------------------------------------------------------------
// Basics
// ------------------------------------------------------------
SP.esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
SP.$ = (s, r = document) => r.querySelector(s);
SP.$$ = (s, r = document) => Array.from(r.querySelectorAll(s));
SP.cookie = n => { const m = document.cookie.match(new RegExp('(?:^|; )' + n + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; };
SP.debounce = (fn, ms = 350) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

const ERR = {
    AUTH_REQUIRED: 'نشست منقضی شده است؛ دوباره وارد شوید',
    CSRF_INVALID: 'توکن امنیتی نامعتبر است؛ صفحه را رفرش کنید',
    PERMISSION_DENIED: 'شما دسترسی به این بخش را ندارید',
    NOT_FOUND: 'مورد یافت نشد',
    RATE_LIMIT: 'تعداد تلاش بیش از حد مجاز؛ کمی بعد دوباره امتحان کنید',
    NETWORK: 'خطای شبکه؛ اتصال را بررسی کنید',
    SERVER_ERROR: 'خطای داخلی سرور',
    INVALID_CREDENTIALS: 'نام کاربری یا رمز عبور اشتباه است',
    INACTIVE: 'حساب شما غیرفعال است',
    UNKNOWN_ACTION: 'عملیات ناشناخته',
};
SP.errText = r => (r && (r.message || ERR[r.error] || r.error)) || 'خطای ناشناخته';

// ------------------------------------------------------------
// Toast
// ------------------------------------------------------------
SP.toast = (msg, type = '') => {
    let box = document.getElementById('toasts');
    if (!box) { box = document.createElement('div'); box.id = 'toasts'; document.body.appendChild(box); }
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, 3200);
};
SP.ok = m => SP.toast(m, 'ok');
SP.fail = r => SP.toast(typeof r === 'string' ? r : SP.errText(r), 'err');

// ------------------------------------------------------------
// Dialog / confirm
// ------------------------------------------------------------
SP.dialog = (title, html, opts = {}) => {
    const ov = document.createElement('div');
    ov.className = 'modal-overlay open';
    ov.innerHTML = `<div class="modal-box ${opts.wide ? 'wide' : ''}" role="dialog" aria-modal="true">
        <div class="modal-header"><div class="modal-title">${SP.esc(title)}</div>
        <button class="x-btn" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal-body">${html}</div></div>`;
    document.body.appendChild(ov);
    let closed = false;
    const close = () => { if (closed) return; closed = true; ov.remove(); if (opts.onClose) opts.onClose(); };
    ov._close = close;
    ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
    SP.$('[data-close]', ov).onclick = close;
    return { el: ov, body: SP.$('.modal-body', ov), close, $: s => SP.$(s, ov) };
};
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    const all = SP.$$('.modal-overlay.open');
    const top = all[all.length - 1];
    if (top && top._close) top._close();
});

SP.confirm = (msg, o = {}) => new Promise(res => {
    let done = false;
    const fin = v => { if (!done) { done = true; res(v); } };
    const d = SP.dialog(o.title || 'Confirm', `<p style="line-height:1.8;margin-bottom:20px">${SP.esc(msg)}</p>
        <div style="display:flex;gap:10px">
        <button class="btn-sm" style="flex:1;justify-content:center;padding:12px" data-no>Cancel</button>
        <button class="btn-sm ${o.danger ? 'danger' : 'primary'}" style="flex:1;justify-content:center;padding:12px" data-yes>${SP.esc(o.ok || 'Confirm')}</button></div>`,
        { onClose: () => fin(false) });
    SP.$('[data-no]', d.el).onclick = () => d.close();
    SP.$('[data-yes]', d.el).onclick = () => { fin(true); d.close(); };
});

// ------------------------------------------------------------
// Clipboard / download / script loader
// ------------------------------------------------------------
SP.copy = async (text, msg = 'Copied') => {
    try { await navigator.clipboard.writeText(text); }
    catch (e) {
        const t = document.createElement('textarea');
        t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); } catch (_) { /* ignore */ }
        t.remove();
    }
    SP.ok(msg);
};
SP.download = (name, content, mime = 'text/plain') => {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([content], { type: mime + ';charset=utf-8' }));
    a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
};
const _scripts = {};
SP.loadScript = src => _scripts[src] || (_scripts[src] = new Promise((res, rej) => {
    const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error('load failed')); document.head.appendChild(s);
}));
SP.qr = async text => {
    const d = SP.dialog('QR Code', `<div class="qr-box" id="qr-target"></div><div class="key-big">${SP.esc(text)}</div>
        <button class="btn-sm" style="width:100%;justify-content:center;padding:12px" data-copy><i class="fa-regular fa-copy"></i> Copy</button>`);
    SP.$('[data-copy]', d.el).onclick = () => SP.copy(text);
    try {
        await SP.loadScript('https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js');
        new QRCode(d.$('#qr-target'), { text, width: 220, height: 220 });
    } catch (e) { d.$('#qr-target').textContent = 'QR library could not be loaded'; }
};

// ------------------------------------------------------------
// Theme
// ------------------------------------------------------------
SP.initTheme = btn => {
    const paint = () => { btn.innerHTML = document.documentElement.getAttribute('data-theme') === 'dark' ? '<i class="fa-regular fa-moon"></i>' : '<i class="fa-regular fa-sun"></i>'; };
    paint();
    btn.onclick = () => {
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (dark) document.documentElement.removeAttribute('data-theme'); else document.documentElement.setAttribute('data-theme', 'dark');
        try { localStorage.setItem('theme', dark ? 'light' : 'dark'); } catch (e) { /* ignore */ }
        paint();
    };
};

// ------------------------------------------------------------
// Formatting
// ------------------------------------------------------------
SP.fmt = {
    date: ts => ts ? new Date(ts * 1000).toLocaleString() : '—',
    ago: ts => {
        if (!ts) return 'Never';
        const s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (s < 60) return 'Just now';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        if (s < 86400 * 30) return Math.floor(s / 86400) + 'd ago';
        return new Date(ts * 1000).toLocaleDateString();
    },
    dur: s => {
        if (!s || s <= 0) return 'Unlimited';
        const d = s / 86400;
        if (d >= 1) return (Number.isInteger(d) ? d : d.toFixed(1)) + ' Days';
        const h = Math.floor(s / 3600);
        return h >= 1 ? h + ' Hours' : Math.max(1, Math.floor(s / 60)) + ' Min';
    },
    remain: s => {
        if (s < 0) return 'Unlimited';
        if (s === 0) return 'Expired';
        const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
        if (d > 0) return d + 'd ' + h + 'h';
        return h + 'h ' + m + 'm';
    },
    size: b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB',
};
/** زمان باقی‌مانده؛ کلید استفاده‌نشده مدت اعتبارش را نشان می‌دهد */
SP.expText = k => {
    if (k.expires_at > 0) return SP.fmt.remain(k.remaining);
    if (k.duration > 0) return SP.fmt.dur(k.duration) + ' (starts on first use)';
    return 'Unlimited';
};
SP.expShort = k => {
    if (k.expires_at > 0) return SP.fmt.remain(k.remaining);
    return k.duration > 0 ? SP.fmt.dur(k.duration) : 'Unlimited';
};
const STATUS = { active: ['Active', 'green'], unused: ['Unused', 'blue'], expired: ['Expired', 'orange'], banned: ['Banned', 'red'], all: ['All', ''] };
SP.status = s => STATUS[s] || [s ? s[0].toUpperCase() + s.slice(1) : '—', ''];
SP.badge = s => { const [t, c] = SP.status(s); return `<span class="badge ${c}">${SP.esc(t)}</span>`; };

// ------------------------------------------------------------
// API client
// ------------------------------------------------------------
SP.mkApi = (base, csrfName, opts = {}) => async (action, o = {}) => {
    const url = base + '?action=' + encodeURIComponent(action) + (o.qs ? '&' + o.qs : '');
    const body = o.body ? JSON.stringify({ ...o.body, csrf: SP.cookie(csrfName) || opts.csrfFallback || '' }) : null;
    try {
        const res = await fetch(url, {
            method: body ? 'POST' : 'GET',
            headers: body ? { 'Content-Type': 'application/json' } : {},
            body, credentials: 'same-origin',
        });
        if (res.status === 401 && !opts.noReload) { location.reload(); return { ok: false, error: 'AUTH_REQUIRED' }; }
        const j = await res.json().catch(() => null);
        return j || { ok: false, error: 'پاسخ نامعتبر از سرور (HTTP ' + res.status + ')' };
    } catch (e) { return { ok: false, error: 'NETWORK' }; }
};

// ------------------------------------------------------------
// Pager / table helpers
// ------------------------------------------------------------
SP.pager = (el, p, onPage) => {
    if (!p || p.pages <= 1) { el.innerHTML = p && p.total ? `<span>${p.total} total</span>` : ''; return; }
    el.innerHTML = `<button data-p="-1" ${p.page <= 0 ? 'disabled' : ''}><i class="fa-solid fa-chevron-left"></i> Prev</button>
        <span>Page ${p.page + 1} of ${p.pages} · ${p.total} total</span>
        <button data-p="1" ${p.page >= p.pages - 1 ? 'disabled' : ''}>Next <i class="fa-solid fa-chevron-right"></i></button>`;
    SP.$$('button', el).forEach(b => b.onclick = () => onPage(p.page + parseInt(b.dataset.p, 10)));
};
SP.table = (heads, rows) => `<div class="table-wrap"><table class="tbl"><thead><tr>${heads.map(h => `<th>${h}</th>`).join('')}</tr></thead>
    <tbody>${rows.length ? rows.map(r => `<tr>${r.map(c => `<td>${c}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${heads.length}" class="empty">No data</td></tr>`}</tbody></table></div>`;
SP.skeleton = (n = 4) => Array.from({ length: n }, () => '<div class="skel"></div>').join('');

// ------------------------------------------------------------
// Create key dialog (admin + panel)
// opts: { api, products?:[{id,name,slug}], limits?:{maxUsers,maxDays,maxCount}, onDone }
// ------------------------------------------------------------
SP.showKeys = keys => {
    if (keys.length === 1) {
        const d = SP.dialog('✅ Key created', `<div class="key-big">${SP.esc(keys[0])}</div>
            <div style="display:flex;gap:10px"><button class="btn-sm primary" style="flex:1;justify-content:center;padding:12px" data-copy><i class="fa-regular fa-copy"></i> Copy</button>
            <button class="btn-sm" style="flex:1;justify-content:center;padding:12px" data-qr><i class="fa-solid fa-qrcode"></i> QR</button></div>`);
        SP.$('[data-copy]', d.el).onclick = () => SP.copy(keys[0]);
        SP.$('[data-qr]', d.el).onclick = () => SP.qr(keys[0]);
    } else {
        const d = SP.dialog(`✅ ${keys.length} keys created`, `<div class="form-group"><textarea readonly id="ks-text">${SP.esc(keys.join('\n'))}</textarea></div>
            <div style="display:flex;gap:10px"><button class="btn-sm primary" style="flex:1;justify-content:center;padding:12px" data-copy><i class="fa-regular fa-copy"></i> Copy all</button>
            <button class="btn-sm" style="flex:1;justify-content:center;padding:12px" data-dl><i class="fa-solid fa-download"></i> Download .txt</button></div>`);
        SP.$('[data-copy]', d.el).onclick = () => SP.copy(keys.join('\n'), 'All keys copied');
        SP.$('[data-dl]', d.el).onclick = () => SP.download('keys_' + Date.now() + '.txt', keys.join('\n'));
    }
};

SP.createKeyDialog = (opts) => {
    const L = opts.limits || {};
    const prods = opts.products;
    const d = SP.dialog('Create User', `
        ${prods ? `<div class="form-group"><label>Product</label><select id="ck-product">${prods.map(p => `<option value="${p.id}">${SP.esc(p.name)} (${SP.esc(p.slug)})</option>`).join('')}</select></div>` : ''}
        <div class="form-group"><label>Duration in Days (0 = Unlimited)</label>
            <div class="preset-row">${[1, 7, 30, 90, 180, 365].filter(x => !L.maxDays || x <= L.maxDays).map(x => `<button class="chip" type="button" data-d="${x}">${x}d</button>`).join('')}${L.maxDays ? '' : '<button class="chip" type="button" data-d="0">∞</button>'}</div>
            <input type="number" id="ck-days" value="${L.maxDays ? Math.min(30, L.maxDays) : 30}" min="0" ${L.maxDays ? `max="${L.maxDays}"` : ''}>
            ${L.maxDays ? `<div class="form-hint">Maximum allowed: ${L.maxDays} days</div>` : ''}</div>
        <div class="form-row">
            <div class="form-group"><label>Max Users (Devices)</label><input type="number" id="ck-users" value="1" min="1" ${L.maxUsers ? `max="${L.maxUsers}"` : ''}>
                ${L.maxUsers ? `<div class="form-hint">Maximum: ${L.maxUsers}</div>` : ''}</div>
            <div class="form-group"><label>Quantity</label><input type="number" id="ck-count" value="1" min="1" max="${L.maxCount || 100}"></div>
        </div>
        <div class="form-group"><label>Note (optional — e.g. customer name)</label><input type="text" id="ck-note" maxlength="200" placeholder="e.g. Ali – Telegram"></div>
        <button class="btn-submit" id="ck-submit">Create</button>`);
    SP.$$('[data-d]', d.el).forEach(b => b.onclick = () => { d.$('#ck-days').value = b.dataset.d; });
    d.$('#ck-submit').onclick = async () => {
        const days = parseInt(d.$('#ck-days').value, 10);
        const max_users = parseInt(d.$('#ck-users').value, 10);
        const count = parseInt(d.$('#ck-count').value, 10) || 1;
        const product_id = prods ? parseInt(d.$('#ck-product').value, 10) : undefined;
        if (isNaN(days) || days < 0 || isNaN(max_users) || max_users < 1) return SP.fail('لطفاً مقادیر را درست وارد کنید');
        if (L.maxDays && (days === 0 || days > L.maxDays)) return SP.fail('مدت انتخابی از سقف مجاز شما بیشتر است');
        if (L.maxUsers && max_users > L.maxUsers) return SP.fail('تعداد کاربر از سقف مجاز شما بیشتر است');
        if (count < 1 || count > (L.maxCount || 100)) return SP.fail('تعداد باید بین ۱ تا ' + (L.maxCount || 100) + ' باشد');
        if (prods && !product_id) return SP.fail('محصول انتخاب نشده');
        const btn = d.$('#ck-submit'); btn.disabled = true; btn.textContent = 'Creating...';
        const r = await opts.api('create_key', { body: { product_id, duration: days * 86400, max_users, count, note: d.$('#ck-note').value.trim() } });
        btn.disabled = false; btn.textContent = 'Create';
        if (!r.ok) return SP.fail(r);
        d.close();
        if (r.partial) SP.toast((r.message || 'فقط بخشی از کلیدها ساخته شد'), 'err');
        SP.showKeys(r.keys || [r.key]);
        if (opts.onDone) opts.onDone(r);
    };
};

// ------------------------------------------------------------
// KeyList component
// cfg: { root, api, showProduct, can:{renew,ban,reset,delete,edit}, limit, onChange, maxRenewDays }
// ------------------------------------------------------------
SP.KeyList = class {
    constructor(cfg) {
        this.cfg = cfg;
        this.api = cfg.api;
        this.can = Object.assign({ renew: 1, ban: 1, reset: 1, delete: 1, edit: 1 }, cfg.can || {});
        this.root = document.querySelector(cfg.root);
        this.state = { filter: 'all', search: '', sort: 'new', page: 0, product_id: 0, limit: cfg.limit || 15 };
        this.sel = new Set(); this.data = []; this.counts = {}; this.openId = null; this.pages = 1; this.total = 0;
        this.build();
        this.load();
    }

    build() {
        this.root.innerHTML = `
        <div class="toolbar">
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" data-ref="search" placeholder="Search key, ID, note, device, IP..." autocomplete="off"></div>
            ${this.cfg.showProduct ? '<select class="select-sm" data-ref="product"><option value="0">All products</option></select>' : ''}
            <select class="select-sm" data-ref="sort">
                <option value="new">Newest</option><option value="old">Oldest</option>
                <option value="login">Last seen</option><option value="expiry">Expiring soon</option></select>
            <button class="icon-btn" data-ref="export" title="Export keys"><i class="fa-solid fa-file-export"></i></button>
            <button class="icon-btn" data-ref="refresh" title="Refresh"><i class="fa-solid fa-arrows-rotate"></i></button>
        </div>
        <div class="chips" data-ref="chips"></div>
        <div class="bulkbar" data-ref="bulk" hidden>
            <span class="count" data-ref="bulkcount"></span>
            ${this.can.renew ? '<button class="btn-sm" data-bulk="renew"><i class="fa-solid fa-clock-rotate-left"></i> Renew</button>' : ''}
            ${this.can.ban ? '<button class="btn-sm" data-bulk="ban"><i class="fa-solid fa-ban"></i> Revoke</button><button class="btn-sm" data-bulk="unban"><i class="fa-solid fa-rotate-left"></i> Unban</button>' : ''}
            <button class="btn-sm" data-bulk="copy"><i class="fa-regular fa-copy"></i> Copy</button>
            <button class="btn-sm" data-bulk="csv"><i class="fa-solid fa-download"></i> CSV</button>
            ${this.can.delete ? '<button class="btn-sm danger" data-bulk="delete"><i class="fa-solid fa-trash"></i> Delete</button>' : ''}
        </div>
        <div class="key-list">
            <div class="key-header"><div class="col-check"><input type="checkbox" data-ref="all" title="Select page"></div><div class="col-user">Key</div><div style="min-width:110px;text-align:right;padding-right:22px">Usage</div></div>
            <div data-ref="list"></div>
        </div>
        <div class="pager" data-ref="pager"></div>`;
        this.r = {};
        SP.$$('[data-ref]', this.root).forEach(e => this.r[e.dataset.ref] = e);

        this.r.search.addEventListener('input', SP.debounce(() => { this.state.search = this.r.search.value.trim(); this.state.page = 0; this.load(); }));
        this.r.sort.onchange = () => { this.state.sort = this.r.sort.value; this.state.page = 0; this.load(); };
        if (this.r.product) this.r.product.onchange = () => { this.state.product_id = parseInt(this.r.product.value, 10) || 0; this.state.page = 0; this.load(); };
        this.r.refresh.onclick = () => this.load();
        this.r.export.onclick = () => this.exportDialog();
        this.r.all.onchange = () => {
            this.sel.clear();
            if (this.r.all.checked) this.data.forEach(k => this.sel.add(k.id));
            this.render();
        };
        this.r.chips.addEventListener('click', e => {
            const c = e.target.closest('[data-f]'); if (!c) return;
            this.state.filter = c.dataset.f; this.state.page = 0; this.load();
        });
        SP.$$('[data-bulk]', this.root).forEach(b => b.onclick = () => this.bulk(b.dataset.bulk));
        this.r.list.addEventListener('click', e => {
            if (e.target.closest('[data-sel]')) return;
            const a = e.target.closest('[data-act]'); if (!a) return;
            e.stopPropagation();
            this.act(a.dataset.act, parseInt(a.dataset.id, 10));
        });
        this.r.list.addEventListener('change', e => {
            const c = e.target.closest('[data-sel]'); if (!c) return;
            const id = parseInt(c.dataset.sel, 10);
            if (c.checked) this.sel.add(id); else this.sel.delete(id);
            this.updateBulk();
        });
    }

    setProducts(list) {
        if (!this.r.product) return;
        const cur = this.state.product_id;
        this.r.product.innerHTML = '<option value="0">All products</option>' + list.map(p => `<option value="${p.id}">${SP.esc(p.name)}</option>`).join('');
        this.r.product.value = String(cur);
    }
    setProduct(id) {
        this.state.product_id = id; this.state.page = 0;
        if (this.r.product) this.r.product.value = String(id);
        this.load();
    }

    async load() {
        const s = this.state;
        this.r.list.innerHTML = SP.skeleton(Math.min(s.limit, 4));
        const qs = new URLSearchParams({ filter: s.filter, page: s.page, sort: s.sort, limit: s.limit, search: s.search });
        if (s.product_id) qs.set('product_id', s.product_id);
        const r = await this.api('keys', { qs: qs.toString() });
        if (!r.ok) { this.r.list.innerHTML = `<div class="error-box">${SP.esc(SP.errText(r))}</div>`; return; }
        if (r.keys.length === 0 && r.page > 0) { s.page = Math.max(0, r.pages - 1); return this.load(); }
        this.data = r.keys; this.counts = r.counts || {}; this.pages = r.pages; this.total = r.total;
        this.sel.clear();
        this.render();
        if (this.cfg.onLoaded) this.cfg.onLoaded(r);
    }

    changed() { this.load(); if (this.cfg.onChange) this.cfg.onChange(); }

    render() {
        // chips
        const order = ['all', 'active', 'unused', 'expired', 'banned'];
        Object.keys(this.counts).forEach(k => { if (!order.includes(k)) order.push(k); });
        this.r.chips.innerHTML = order.map(f => `<button class="chip ${this.state.filter === f ? 'active' : ''}" data-f="${SP.esc(f)}">${SP.esc(SP.status(f)[0])}<b>${this.counts[f] ?? 0}</b></button>`).join('');
        // list
        if (!this.data.length) {
            this.r.list.innerHTML = `<div class="empty"><i class="fa-regular fa-folder-open" style="font-size:28px;margin-bottom:10px;display:block"></i>${this.state.search || this.state.filter !== 'all' ? 'No matching users' : 'No users yet'}</div>`;
        } else {
            this.r.list.innerHTML = this.data.map(k => this.itemHtml(k)).join('');
        }
        SP.pager(this.r.pager, { page: this.state.page, pages: this.pages, total: this.total }, p => { this.state.page = p; this.load(); });
        this.updateBulk();
    }

    itemHtml(k) {
        const open = this.openId === k.id;
        const pct = Math.min(100, Math.round(k.current_users / k.max_users * 100));
        const cls = pct >= 100 ? 'full' : pct >= 75 ? 'warn' : '';
        const on = k.status === 'active';
        const btn = (act, icon, label, extra = '') => `<button class="btn-sm ${extra}" data-act="${act}" data-id="${k.id}"><i class="${icon}"></i> ${label}</button>`;
        return `<div class="key-item ${open ? 'active' : ''}">
            <div class="key-row" data-act="toggle" data-id="${k.id}">
                <div class="col-check" data-sel-wrap><input type="checkbox" data-sel="${k.id}" ${this.sel.has(k.id) ? 'checked' : ''}></div>
                <div class="col-user">
                    <div class="key-icon ${on ? '' : 'off'}"><i class="fa-solid fa-key"></i></div>
                    <div style="min-width:0"><div class="kname">${SP.esc(k.key_masked)}</div>
                    <div class="knote">${this.cfg.showProduct && k.product ? SP.esc(k.product) + (k.note ? ' · ' : '') : ''}${SP.esc(k.note || '')}</div></div>
                    ${SP.badge(k.status)}
                </div>
                <div class="col-usage"><div><div class="data-text">${k.current_users} / ${k.max_users}</div><div class="data-sub">${SP.esc(SP.expShort(k))}</div></div>
                    <i class="fa-solid fa-chevron-down" style="color:var(--text-mut);margin-top:4px;transition:.3s;${open ? 'transform:rotate(180deg)' : ''}"></i></div>
            </div>
            <div class="key-details">
                <div class="progress-bar-bg"><div class="progress-bar-fill ${cls}" style="width:${pct}%"></div></div>
                <div style="font-size:12.5px;color:var(--text-mut)">Devices: ${k.current_users} of ${k.max_users}</div>
                <div class="detail-info">
                    <div><i class="fa-regular fa-clock"></i> ${SP.esc(SP.expText(k))}</div>
                    <div><i class="fa-solid fa-signal"></i> Last seen: ${SP.esc(SP.fmt.ago(k.last_login))}</div>
                    <div><i class="fa-regular fa-calendar"></i> Created: ${SP.esc(SP.fmt.date(k.created_at))}</div>
                    <div><i class="fa-solid fa-hashtag"></i> ID ${k.id} · ${k.login_count} logins</div>
                </div>
                <div class="card-actions" style="margin-top:0">
                    ${btn('detail', 'fa-solid fa-circle-info', 'Details')}
                    ${btn('copy', 'fa-regular fa-copy', 'Copy')}
                    ${btn('qr', 'fa-solid fa-qrcode', 'QR')}
                    ${this.can.renew ? btn('renew', 'fa-solid fa-clock-rotate-left', 'Renew') : ''}
                    ${this.can.reset ? btn('reset', 'fa-solid fa-rotate-right', 'Reset devices') : ''}
                    ${this.can.ban ? btn('ban', k.status === 'banned' ? 'fa-solid fa-rotate-left' : 'fa-solid fa-ban', k.status === 'banned' ? 'Unban' : 'Revoke', 'danger') : ''}
                    ${this.can.delete ? btn('delete', 'fa-solid fa-trash', 'Delete', 'danger') : ''}
                </div>
            </div>
        </div>`;
    }

    updateBulk() {
        const n = this.sel.size;
        this.r.bulk.hidden = n === 0;
        this.r.bulkcount.textContent = n + ' selected';
        this.r.all.checked = n > 0 && n === this.data.length;
        this.r.all.indeterminate = n > 0 && n < this.data.length;
    }

    async act(act, id) {
        const k = this.data.find(x => x.id === id);
        if (!k) return;
        switch (act) {
            case 'toggle': this.openId = this.openId === id ? null : id; this.render(); break;
            case 'copy': SP.copy(k.key, 'Key copied'); break;
            case 'qr': SP.qr(k.key); break;
            case 'detail': this.detail(id); break;
            case 'renew': this.renewDialog([id]); break;
            case 'reset': {
                if (!await SP.confirm('Reset all devices (Hardware IDs) for this key?', { title: 'Reset devices', ok: 'Reset', danger: true })) return;
                const r = await this.api('reset_all_devices', { body: { license_id: id } });
                if (!r.ok) return SP.fail(r);
                SP.ok('Devices reset'); this.changed(); break;
            }
            case 'ban': {
                const banned = k.status === 'banned';
                if (!await SP.confirm(banned ? 'Unban this key?' : 'Revoke this subscription? The user will be blocked.', { title: banned ? 'Unban' : 'Revoke', ok: banned ? 'Unban' : 'Revoke', danger: !banned })) return;
                const r = await this.api(banned ? 'unban_key' : 'ban_key', { body: { id } });
                if (!r.ok) return SP.fail(r);
                SP.ok(banned ? 'Key unbanned' : 'Key revoked'); this.changed(); break;
            }
            case 'delete': {
                if (!await SP.confirm('Delete this key permanently? This cannot be undone.', { title: 'Delete key', ok: 'Delete', danger: true })) return;
                const r = await this.api('delete_key', { body: { id } });
                if (!r.ok) return SP.fail(r);
                SP.ok('Key deleted'); this.openId = null; this.changed(); break;
            }
        }
    }

    // ---------- renew ----------
    renewDialog(ids, afterDone) {
        const maxD = this.cfg.maxRenewDays || 0;
        const presets = [7, 30, 90, 180, 365].filter(x => !maxD || x <= maxD);
        const d = SP.dialog(ids.length > 1 ? `Renew ${ids.length} keys` : 'Renew key', `
            <div class="form-group"><label>Add time (days)</label>
            <div class="preset-row">${presets.map(x => `<button class="chip" type="button" data-d="${x}">+${x}d</button>`).join('')}</div>
            <input type="number" id="rn-days" min="1" value="${presets.includes(30) ? 30 : (presets[0] || 1)}" ${maxD ? `max="${maxD}"` : ''}>
            ${maxD ? `<div class="form-hint">Maximum allowed: ${maxD} days</div>` : ''}</div>
            <button class="btn-submit" id="rn-ok">Renew</button>`);
        SP.$$('[data-d]', d.el).forEach(b => b.onclick = () => { d.$('#rn-days').value = b.dataset.d; });
        d.$('#rn-ok').onclick = async () => {
            const days = parseInt(d.$('#rn-days').value, 10);
            if (!days || days < 1) return SP.fail('تعداد روز نامعتبر است');
            if (maxD && days > maxD) return SP.fail('بیشتر از سقف مجاز شماست');
            const b = d.$('#rn-ok'); b.disabled = true;
            const r = ids.length === 1
                ? await this.api('renew_key', { body: { id: ids[0], seconds: days * 86400 } })
                : await this.api('bulk', { body: { op: 'renew', ids, seconds: days * 86400 } });
            b.disabled = false;
            if (!r.ok) return SP.fail(r);
            d.close();
            SP.ok(ids.length > 1 ? `Renewed ${r.done}, failed ${r.failed}` : 'Key renewed');
            if (afterDone) afterDone();
            this.changed();
        };
    }

    // ---------- bulk ----------
    async bulk(op) {
        const ids = [...this.sel];
        if (!ids.length) return;
        const keys = this.data.filter(k => this.sel.has(k.id));
        if (op === 'copy') return SP.copy(keys.map(k => k.key).join('\n'), keys.length + ' keys copied');
        if (op === 'csv') return SP.download('keys_selected.csv', this.toCsv(keys), 'text/csv');
        if (op === 'renew') return this.renewDialog(ids);
        const labels = { ban: 'Revoke the selected keys?', unban: 'Unban the selected keys?', delete: 'Delete the selected keys permanently?' };
        if (!await SP.confirm(`${labels[op]} (${ids.length})`, { ok: 'Yes, continue', danger: op !== 'unban' })) return;
        const r = await this.api('bulk', { body: { op, ids } });
        if (!r.ok) return SP.fail(r);
        SP.ok(`Done: ${r.done} · Failed: ${r.failed}`);
        this.changed();
    }
    toCsv(rows) {
        const q = v => { v = String(v ?? ''); if (/^[=+\-@]/.test(v)) v = "'" + v; return '"' + v.replace(/"/g, '""') + '"'; };
        return 'id,key,status,max_users,devices,expires_at,note\n' + rows.map(k =>
            [k.id, k.key, k.status, k.max_users, k.current_users, k.expires_at ? new Date(k.expires_at * 1000).toISOString() : '', k.note].map(q).join(',')).join('\n');
    }

    exportDialog() {
        const d = SP.dialog('Export keys', `<p style="color:var(--text-mut);margin-bottom:16px;line-height:1.7">Exports all keys matching the current status filter${this.state.product_id ? ' and product' : ''}.</p>
            <div style="display:flex;gap:10px"><button class="btn-sm primary" style="flex:1;justify-content:center;padding:12px" data-f="txt"><i class="fa-solid fa-file-lines"></i> TXT (keys only)</button>
            <button class="btn-sm" style="flex:1;justify-content:center;padding:12px" data-f="csv"><i class="fa-solid fa-file-csv"></i> CSV (full)</button></div>`);
        SP.$$('[data-f]', d.el).forEach(b => b.onclick = async () => {
            b.disabled = true;
            const qs = new URLSearchParams({ format: b.dataset.f, filter: this.state.filter });
            if (this.state.product_id) qs.set('product_id', this.state.product_id);
            const r = await this.api('export', { qs: qs.toString() });
            b.disabled = false;
            if (!r.ok) return SP.fail(r);
            SP.download(r.filename, r.content, b.dataset.f === 'csv' ? 'text/csv' : 'text/plain');
            SP.ok(r.count + ' keys exported'); d.close();
        });
    }

    // ---------- details ----------
    async detail(id) {
        let dirty = false;
        const d = SP.dialog('Key details', '<div class="empty"><i class="fa-solid fa-spinner spin"></i></div>', { wide: true, onClose: () => { if (dirty) this.load(); } });
        const paint = async () => {
            const r = await this.api('key', { qs: 'id=' + id });
            if (!r.ok) { d.close(); return SP.fail(r); }
            const k = r.key;
            const devs = r.devices.length ? r.devices.map(v => `<div class="dev-card"><div style="min-width:0">
                <div class="mono" title="${SP.esc(v.device_id)}">${SP.esc(v.device_id_short)}</div>
                <div class="meta">${SP.esc(v.model || 'Unknown device')}${v.android ? ' · Android ' + SP.esc(v.android) : ''}<br>
                Last: ${SP.esc(SP.fmt.date(v.last_login))} · ${v.login_count} logins${v.ip ? ' · ' + SP.esc(v.ip) : ''}</div></div>
                ${this.can.reset ? `<button class="btn-sm danger" data-do="dev" data-dev="${v.id}"><i class="fa-solid fa-link-slash"></i> Reset</button>` : ''}</div>`).join('')
                : '<div class="empty" style="padding:16px">No devices connected</div>';
            const banned = k.status === 'banned';
            d.body.innerHTML = `
                <div class="key-big">${SP.esc(k.key)}</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
                    <button class="btn-sm" data-do="copy"><i class="fa-regular fa-copy"></i> Copy</button>
                    <button class="btn-sm" data-do="qr"><i class="fa-solid fa-qrcode"></i> QR</button>
                    ${this.can.ban ? `<button class="btn-sm danger" data-do="ban"><i class="fa-solid ${banned ? 'fa-rotate-left' : 'fa-ban'}"></i> ${banned ? 'Unban' : 'Revoke'}</button>` : ''}
                    ${this.can.delete ? '<button class="btn-sm danger" data-do="delete"><i class="fa-solid fa-trash"></i> Delete</button>' : ''}
                </div>
                <div class="kv">
                    <div class="k">Status</div><div class="v">${SP.badge(k.status)}</div>
                    ${k.product ? `<div class="k">Product</div><div class="v">${SP.esc(k.product)}</div>` : ''}
                    <div class="k">Validity</div><div class="v">${SP.esc(SP.expText(k))}</div>
                    <div class="k">Duration</div><div class="v">${SP.esc(SP.fmt.dur(k.duration))}</div>
                    <div class="k">Expires</div><div class="v">${k.expires_at ? SP.esc(SP.fmt.date(k.expires_at)) : '—'}</div>
                    <div class="k">First activated</div><div class="v">${SP.esc(SP.fmt.date(k.first_activated_at))}</div>
                    <div class="k">Created</div><div class="v">${SP.esc(SP.fmt.date(k.created_at))}</div>
                    <div class="k">Last seen</div><div class="v">${SP.esc(SP.fmt.date(k.last_login))} (${k.login_count} logins)</div>
                </div>
                ${this.can.edit ? `<div class="section-title" style="margin-top:6px">Edit</div>
                <div class="form-row">
                    <div class="form-group"><label>Max users (devices)</label><input type="number" id="dk-users" min="1" value="${k.max_users}"></div>
                    <div class="form-group"><label>Note</label><input type="text" id="dk-note" maxlength="200" value="${SP.esc(k.note)}"></div>
                </div>
                <button class="btn-sm primary" data-do="save" style="margin-bottom:18px"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>` : ''}
                ${this.can.renew ? `<div class="section-title" style="margin-top:6px">Renew</div>
                <div class="preset-row" style="margin-bottom:18px">${[7, 30, 90, 365].filter(x => !this.cfg.maxRenewDays || x <= this.cfg.maxRenewDays).map(x => `<button class="chip" data-do="renew" data-days="${x}">+${x} days</button>`).join('')}
                <button class="chip" data-do="renewcustom">Custom…</button></div>` : ''}
                <div class="card-row" style="margin-bottom:10px"><div class="section-title" style="margin:0">Devices (${k.current_users}/${k.max_users})</div>
                    ${this.can.reset && r.devices.length ? '<button class="btn-sm danger" data-do="resetall"><i class="fa-solid fa-rotate-right"></i> Reset all</button>' : ''}</div>
                ${devs}`;
            d.key = k;
        };
        await paint();
        d.body.addEventListener('click', async e => {
            const b = e.target.closest('[data-do]'); if (!b || !d.key) return;
            const k = d.key, a = b.dataset.do;
            const run = async (action, body, okMsg) => {
                const r = await this.api(action, { body });
                if (!r.ok) return SP.fail(r);
                SP.ok(okMsg); dirty = true; if (this.cfg.onChange) this.cfg.onChange(); await paint();
            };
            if (a === 'copy') SP.copy(k.key, 'Key copied');
            else if (a === 'qr') SP.qr(k.key);
            else if (a === 'save') run('update_key', { id: id, max_users: parseInt(d.$('#dk-users').value, 10) || 1, note: d.$('#dk-note').value.trim() }, 'Saved');
            else if (a === 'renew') run('renew_key', { id, seconds: parseInt(b.dataset.days, 10) * 86400 }, 'Key renewed');
            else if (a === 'renewcustom') { d.close(); this.renewDialog([id]); }
            else if (a === 'dev') {
                if (await SP.confirm('Reset this device?', { ok: 'Reset', danger: true })) run('reset_device', { license_id: id, device_row_id: parseInt(b.dataset.dev, 10) }, 'Device reset');
            } else if (a === 'resetall') {
                if (await SP.confirm('Reset all devices of this key?', { ok: 'Reset all', danger: true })) run('reset_all_devices', { license_id: id }, 'Devices reset');
            } else if (a === 'ban') {
                const banned = k.status === 'banned';
                if (await SP.confirm(banned ? 'Unban this key?' : 'Revoke this subscription?', { ok: banned ? 'Unban' : 'Revoke', danger: !banned })) run(banned ? 'unban_key' : 'ban_key', { id }, banned ? 'Key unbanned' : 'Key revoked');
            } else if (a === 'delete') {
                if (!await SP.confirm('Delete this key permanently?', { ok: 'Delete', danger: true })) return;
                const r = await this.api('delete_key', { body: { id } });
                if (!r.ok) return SP.fail(r);
                dirty = true; d.close(); SP.ok('Key deleted'); this.openId = null; this.changed();
            }
        });
    }
};
})();
