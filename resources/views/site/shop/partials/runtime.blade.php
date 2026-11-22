{{-- Shop Alpine runtime. Inline + classic so it registers on "alpine:init" before the layout's app.js starts Alpine. --}}
<script>
(() => {
    if (window.__luxeShop) return;
    window.__luxeShop = true;
    const csrf = () => document.querySelector('meta[name=csrf-token]')?.content;
    const api = async (url, method = 'GET', body = null) => {
        const res = await fetch(url, {
            method,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : null,
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) { const err = new Error(data.message || @js(__('shop.errors.generic'))); err.data = data; throw err; }
        return data;
    };
    window.luxeShopApi = api;
    const routes = @js([
        'cart' => route('shop.cart'),
        'add' => route('shop.cart.add'),
        'item' => url('/shop/cart/items'),
        'coupon' => route('shop.cart.coupon'),
        'gift' => route('shop.cart.gift'),
        'wishlist' => url('/shop/wishlist'),
        'suggest' => route('shop.suggest'),
    ]);

    document.addEventListener('alpine:init', () => {
        Alpine.store('cart', {
            open: false, loading: false, busy: false, data: { count: 0, items: [], upsell: [], formatted: {} }, toast: null, error: null, couponCode: '',
            async load() { this.loading = true; try { this.data = await api(routes.cart); } catch (e) {} this.loading = false; },
            show() { this.open = true; this.load(); },
            flash(msg, error = false) { this.toast = { msg, error }; clearTimeout(this._t); this._t = setTimeout(() => this.toast = null, 2600); },
            async call(fn) {
                this.busy = true; this.error = null;
                try { const r = await fn(); if (r.cart) this.data = r.cart; if (r.message) this.flash(r.message); return r; }
                catch (e) { if (e.data?.cart) this.data = e.data.cart; this.error = e.message; this.flash(e.message, true); }
                finally { this.busy = false; }
            },
            async add(variantId, qty = 1, openDrawer = true) { const r = await this.call(() => api(routes.add, 'POST', { variant_id: variantId, qty })); if (r && openDrawer) this.open = true; return r; },
            update(id, qty) { return this.call(() => api(`${routes.item}/${id}`, 'PATCH', { qty })); },
            remove(id) { return this.call(() => api(`${routes.item}/${id}`, 'DELETE')); },
            applyCoupon() { return this.couponCode ? this.call(() => api(routes.coupon, 'POST', { code: this.couponCode })) : null; },
            removeCoupon() { return this.call(() => api(routes.coupon, 'DELETE')); },
            saveGift(wrap, message) { return this.call(() => api(routes.gift, 'POST', { gift_wrap: wrap, gift_message: message })); },
        });

        Alpine.data('wishlistButton', (productId, on = false) => ({
            on, busy: false,
            async toggle() {
                this.busy = true;
                try { const r = await api(`${routes.wishlist}/${productId}`, 'POST'); this.on = r.wishlisted; Alpine.store('cart').flash(r.message); } catch (e) { Alpine.store('cart').flash(e.message, true); }
                this.busy = false;
            },
        }));

        Alpine.data('shopSearch', (initial = '') => ({
            q: initial, items: [], open: false, loading: false, active: -1,
            async suggest() {
                if (this.q.trim().length < 2) { this.items = []; this.open = false; return; }
                this.loading = true; this.open = true;
                try { this.items = (await api(`${routes.suggest}?q=${encodeURIComponent(this.q)}`)).items; } catch (e) { this.items = []; }
                this.loading = false; this.active = -1;
            },
            move(d) { if (!this.items.length) return; this.active = (this.active + d + this.items.length) % this.items.length; },
            go(e) { if (this.active >= 0 && this.items[this.active]) { e.preventDefault(); window.location = this.items[this.active].url; } },
        }));

        Alpine.data('shopListing', () => ({
            loading: false, filtersOpen: false,
            async submit(form) {
                const params = new URLSearchParams(new FormData(form));
                [...params.keys()].forEach(k => { if (params.get(k) === '') params.delete(k); });
                const url = `${form.action}?${params.toString()}`;
                this.loading = true; this.filtersOpen = false;
                try {
                    const r = await api(`${url}${params.toString() ? '&' : ''}partial=1`);
                    this.$refs.grid.innerHTML = r.html;
                    this.$refs.count && (this.$refs.count.textContent = this.$refs.count.dataset.template.replace(':count', r.total));
                    history.replaceState({}, '', url);
                } catch (e) { window.location = url; }
                this.loading = false;
            },
        }));

        Alpine.data('shopProduct', (cfg) => ({
            variants: cfg.variants, selected: cfg.variants[0]?.id, qty: 1, image: 0, images: cfg.images, zoom: false, zx: 50, zy: 50, adding: false, branches: cfg.branches,
            get variant() { return this.variants.find(v => v.id === this.selected) || this.variants[0]; },
            get available() { return Object.values(this.variant?.stock || {}).reduce((a, b) => Math.max(a, b), 0); },
            select(id) { this.selected = id; this.qty = 1; const v = this.variant; if (v?.image !== null && v?.image !== undefined) this.image = v.image; history.replaceState({}, '', `?variant=${id}`); },
            move(e) { const r = e.currentTarget.getBoundingClientRect(); this.zx = ((e.clientX - r.left) / r.width) * 100; this.zy = ((e.clientY - r.top) / r.height) * 100; },
            async add() { this.adding = true; await Alpine.store('cart').add(this.selected, this.qty); this.adding = false; },
        }));
    });
})();
</script>
