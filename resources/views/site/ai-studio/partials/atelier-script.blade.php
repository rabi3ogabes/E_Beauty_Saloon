{{-- Live Atelier component. Classic inline script: defined before the deferred Alpine module boots.
     The on-device engine (resources/js/atelier-engine.js) publishes window.LuxeAtelier. --}}
<script>
window.luxeAtelier = function (cfg) {
    // Heavy, non-reactive objects live outside Alpine's proxies.
    let engine = null; let src = null; let stream = null; let raf = 0; let layer = null; let plain = null; let view = null; let detecting = false; let lastFit = 0; let splitting = false;
    const dets = { face: null, hand: null };
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));

    return {
        ...cfg,
        i18n: {
            categories: @js(collect(\App\Domain\AIStudio\Support\AtelierCatalog::CATEGORIES)->keys()->mapWithKeys(fn ($c) => [$c => __('ai_studio.atelier.categories.'.$c)])),
            noFace: @js(__('ai_studio.atelier.status.no_face')), noHand: @js(__('ai_studio.atelier.status.no_hand')),
            camera: @js(__('ai_studio.atelier.source.camera')), capture: @js(__('ai_studio.atelier.source.capture')), flip: @js(__('ai_studio.atelier.source.flip')),
            sample: @js(__('ai_studio.atelier.source.sample')), noCamera: @js(__('ai_studio.capture.no_camera')),
            save: @js(__('ai_studio.atelier.actions.save')), saved: @js(__('ai_studio.atelier.actions.saved')), savedNotice: @js(__('ai_studio.atelier.actions.saved_notice')),
            remove: @js(__('ai_studio.atelier.saved.remove')), mm: @js(__('ai_studio.atelier.controls.mm')),
            working: @js(__('ai_studio.atelier.actions.real_working')), adviceWorking: @js(__('ai_studio.atelier.advice.working')),
            failed: @js(__('ai_studio.errors.try_again')), minor: @js(__('ai_studio.consent.minor_notice')),
            fit: @js(__('ai_studio.atelier.fit')),
        },
        icons: { lashes: '◡', brows: '⌒', lips: '◠', nails: '◖' },
        cat: 'lashes', design: {}, status: 'loading', errorText: '', found: false,
        sourceKind: 'sample', sampleKey: null, mirror: false, facing: 'user',
        comparing: false, split: 0.5, splitLeft: 0, fit: null, advice: null, zoom: true,
        busy: '', error: '', notice: '', statusText: '', consentOpen: false, pending: null,

        get framing() { return this.cat === 'nails' ? 'hand' : 'face'; },
        /** Close-ups of the eyes or the mouth are landscape; everything else is a portrait. */
        get wide() { return this.zoom && this.found && this.framing === 'face'; },
        get kind() { return this.framing === 'hand' ? 'hand' : 'face'; },
        get params() { return this.design[this.cat]; },
        get summary() { return this.describe(this.cat, this.params); },
        get isSaved() { return this.designs.some((d) => d.category === this.cat && JSON.stringify(d.params) === JSON.stringify(this.normalized(this.cat, this.params))); },
        get lashMap() {
            const d = this.design.lashes; const st = this.catalog.lashes.styles[d.style];
            return [0, 0.25, 0.5, 0.75, 1].map((t) => Math.round(this.profileAt(st.profile, t) * d.length));
        },
        get fitLine() {
            if (!this.fit) return '';
            const f = this.i18n.fit;
            return [f.shapes[this.fit.shape], f.lifts[this.fit.lift], f.sets[this.fit.set]].join(' · ');
        },
        get suggestionLine() { return this.fit ? this.describe('lashes', this.fit.suggest, false) : ''; },

        async init() {
            this.design = Object.fromEntries(Object.entries(this.catalog).map(([k, c]) => [k, { ...c.defaults }]));
            // A saved look shared or opened from the account: /studio/atelier?design=lashes:{json}
            const shared = new URLSearchParams(location.search).get('try');
            if (shared) { try { const d = JSON.parse(shared); if (this.catalog[d.category]) { this.cat = d.category; Object.assign(this.design[d.category], d.params || {}); } } catch (e) { /* ignore */ } }
            window.addEventListener('resize', () => this.placeSplit());
            document.addEventListener('visibilitychange', () => { if (document.hidden && this.sourceKind === 'camera') this.capture(); });
            try {
                if (!window.LuxeAtelier) await new Promise((r, x) => { window.addEventListener('luxe-atelier-ready', r, { once: true }); setTimeout(() => x(new Error('timeout')), 30000); });
                engine = await window.LuxeAtelier.createEngine(this.assets);
            } catch (e) { this.status = 'error'; return; }
            const first = this.samplesFor(this.framing)[0];
            if (first) await this.useSample(first); else this.status = 'ready';
        },

        // ── Sources ─────────────────────────────────────────────────────────
        samplesFor(framing) { return this.samples.filter((s) => s.framing === framing); },
        async useSample(s) {
            this.stopCamera(); this.error = '';
            const img = new Image(); img.decoding = 'async'; img.src = s.src;
            try { await img.decode(); } catch (e) { this.status = 'error'; return; }
            src = img; this.sourceKind = 'sample'; this.sampleKey = s.key; this.mirror = false;
            await this.detectStill(true);
        },
        async usePhoto(e) {
            const file = e.target.files?.[0]; e.target.value = ''; if (!file) return;
            this.stopCamera(); this.error = '';
            const url = URL.createObjectURL(file); const img = new Image(); img.src = url;
            try { await img.decode(); } catch (err) { this.error = this.i18n.failed; return; }
            src = this.downscale(img, 1600); this.sourceKind = 'photo'; this.sampleKey = null; this.mirror = false;
            URL.revokeObjectURL(url);
            await this.detectStill(true);
        },
        async startCamera() {
            this.error = '';
            if (!navigator.mediaDevices?.getUserMedia) { this.error = this.i18n.noCamera; return; }
            try {
                this.stopCamera();
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: this.facing, width: { ideal: 1280 }, height: { ideal: 960 } }, audio: false });
                const v = this.$refs.video; v.srcObject = stream; await v.play();
                src = v; this.sourceKind = 'camera'; this.sampleKey = null; this.mirror = this.facing === 'user';
                dets.face = null; dets.hand = null; this.found = false;
                const loop = () => { raf = requestAnimationFrame(loop); this.tick(); };
                loop();
            } catch (e) { this.error = this.i18n.noCamera; }
        },
        flip() { this.facing = this.facing === 'user' ? 'environment' : 'user'; this.startCamera(); },
        stopCamera() {
            cancelAnimationFrame(raf); raf = 0;
            stream?.getTracks().forEach((t) => t.stop()); stream = null;
        },
        /** Freeze the live frame into a still photo (so it can be compared, saved or made real). */
        async capture() { this.freeze(); this.stopCamera(); await this.detectStill(true); },
        freeze() {
            const v = this.$refs.video; if (!v?.videoWidth) return;
            const c = document.createElement('canvas'); c.width = v.videoWidth; c.height = v.videoHeight;
            const ctx = c.getContext('2d'); if (this.mirror) { ctx.translate(c.width, 0); ctx.scale(-1, 1); }
            ctx.drawImage(v, 0, 0); src = c; this.mirror = false; this.sourceKind = 'photo';
        },
        downscale(img, max) {
            const w = img.naturalWidth || img.width; const h = img.naturalHeight || img.height; const k = Math.min(1, max / Math.max(w, h));
            if (k === 1) return img;
            const c = document.createElement('canvas'); c.width = Math.round(w * k); c.height = Math.round(h * k);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); return c;
        },

        // ── Tracking + drawing ─────────────────────────────────────────────
        async detectStill(reset = false) {
            if (!engine || !src) return;
            if (reset) { dets.face = null; dets.hand = null; }
            this.status = this.status === 'error' ? 'error' : (dets[this.kind] ? 'ready' : 'loading');
            if (!dets[this.kind]) {
                try { dets[this.kind] = await engine.detect(this.kind, src); } catch (e) { this.status = 'error'; return; }
            }
            this.found = !!dets[this.kind];
            this.updateFit(true);
            this.status = 'ready';
            this.draw();
        },
        tick() {
            const v = this.$refs.video;
            if (!engine || !v || v.readyState < 2) return;
            if (!detecting) {
                detecting = true; const kind = this.kind;
                engine.detect(kind, v, true).then((d) => { dets[kind] = d; if (this.found !== !!d) this.found = !!d; this.updateFit(); })
                    .catch(() => {}).finally(() => { detecting = false; });
            }
            if (this.status !== 'ready') this.status = 'ready';
            this.draw();
        },
        updateFit(force = false) {
            if (!force && performance.now() - lastFit < 700) return;
            lastFit = performance.now();
            const f = dets.face; const s = this.size();
            this.fit = this.cat === 'lashes' && f?.face && s ? window.LuxeAtelier.eyeFit(f.face, s.w, s.h) : null;
        },
        size() {
            if (!src) return null;
            const w = src.videoWidth || src.naturalWidth || src.width; const h = src.videoHeight || src.naturalHeight || src.height;
            return w && h ? { w, h } : null;
        },
        /** Paint the source, then the design (on its own layer so before/after can be split). */
        render(target, withDesign = true) {
            const s = this.size(); if (!s) return null;
            if (target.width !== s.w || target.height !== s.h) { target.width = s.w; target.height = s.h; }
            const ctx = target.getContext('2d');
            ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.globalCompositeOperation = 'source-over'; ctx.filter = 'none';
            ctx.clearRect(0, 0, s.w, s.h);
            if (this.mirror) ctx.setTransform(-1, 0, 0, 1, s.w, 0);
            ctx.drawImage(src, 0, 0, s.w, s.h);
            if (withDesign) window.LuxeAtelier.drawDesign(ctx, dets[this.kind], this.cat, this.params, this.catalog, s.w, s.h);
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            return s;
        },
        draw() {
            const canvas = this.$refs.canvas; if (!canvas || !src || !window.LuxeAtelier) return;
            layer ||= document.createElement('canvas');
            const s = this.render(layer); if (!s) return;
            const r = this.viewRect(s); const w = Math.round(r.w); const h = Math.round(r.h);
            if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; this.$nextTick(() => this.placeSplit()); }
            const ctx = canvas.getContext('2d');
            ctx.drawImage(layer, r.x, r.y, r.w, r.h, 0, 0, w, h);
            if (!this.comparing) return;
            plain ||= document.createElement('canvas'); this.render(plain, false);
            ctx.save(); ctx.beginPath(); ctx.rect(0, 0, w * this.split, h); ctx.clip(); ctx.drawImage(plain, r.x, r.y, r.w, r.h, 0, 0, w, h); ctx.restore();
        },
        /** The part of the frame to show: the whole frame, or a close-up on the feature being designed. */
        viewRect(s) {
            const full = { x: 0, y: 0, w: s.w, h: s.h };
            const target = this.zoom ? this.focus(s) : null;
            if (!target) { view = null; return full; }
            // Live camera: glide towards the face instead of jumping with every frame.
            if (view && this.sourceKind === 'camera') { const k = 0.22; view = { x: view.x + (target.x - view.x) * k, y: view.y + (target.y - view.y) * k, w: view.w + (target.w - view.w) * k, h: view.h + (target.h - view.h) * k }; }
            else view = target;
            return view;
        },
        focus(s) {
            const d = dets[this.kind]; if (!d) return null;
            const flip = (p) => (this.mirror ? s.w - p.x * s.w : p.x * s.w);
            let pts; let aspect; let pad;
            if (this.kind === 'hand') { pts = d.hands.flatMap((h) => h.lm); aspect = 3 / 4; pad = 1.25; }
            else {
                const idx = this.cat === 'lips' ? [61, 291, 0, 17, 164, 18] : [70, 300, 46, 276, 33, 263, 145, 374, 105, 334];
                pts = idx.map((i) => d.face[i]); aspect = 4 / 3; pad = this.cat === 'lips' ? 2.3 : 1.3;
            }
            const xs = pts.map(flip); const ys = pts.map((p) => p.y * s.h);
            const cx = (Math.min(...xs) + Math.max(...xs)) / 2; const cy = (Math.min(...ys) + Math.max(...ys)) / 2;
            let w = (Math.max(...xs) - Math.min(...xs)) * pad; let h = (Math.max(...ys) - Math.min(...ys)) * pad;
            if (w / h > aspect) h = w / aspect; else w = h * aspect;
            if (w >= s.w || h >= s.h) { const k = Math.min(s.w / w, s.h / h); w *= k; h *= k; }
            return { x: Math.min(Math.max(0, cx - w / 2), s.w - w), y: Math.min(Math.max(0, cy - h / 2), s.h - h), w, h };
        },
        toggleZoom() { this.zoom = !this.zoom; view = null; this.draw(); this.$nextTick(() => this.placeSplit()); },

        // ── Before / after ─────────────────────────────────────────────────
        toggleCompare() { this.comparing = !this.comparing; this.split = 0.5; this.$nextTick(() => { this.placeSplit(); this.draw(); }); },
        contentRect() {
            const c = this.$refs.canvas; const r = c.getBoundingClientRect(); if (!c.width) return r;
            const k = Math.min(r.width / c.width, r.height / c.height); const w = c.width * k; const h = c.height * k;
            return { left: r.left + (r.width - w) / 2, top: r.top + (r.height - h) / 2, width: w, height: h, offset: (r.width - w) / 2 };
        },
        placeSplit() { if (!this.comparing || !this.$refs.canvas) return; const r = this.contentRect(); this.splitLeft = (r.offset || 0) + r.width * this.split; },
        startSplit(e) { if (!this.comparing) return; splitting = true; e.target.setPointerCapture?.(e.pointerId); this.moveSplit(e); },
        moveSplit(e) {
            if (!this.comparing || !splitting) return;
            const r = this.contentRect(); this.split = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width));
            this.placeSplit(); if (this.sourceKind !== 'camera') this.draw();
        },
        endSplit() { splitting = false; },

        // ── Design ─────────────────────────────────────────────────────────
        set(key, value) { this.design[this.cat][key] = value; this.advice = null; this.draw(); },
        apply(category, params) {
            this.setCategory(category);
            Object.assign(this.design[category], this.normalized(category, params));
            this.draw();
        },
        async setCategory(c) {
            if (this.cat === c) return;
            const before = this.framing; this.cat = c; this.advice = null;
            if (this.framing !== before && this.sourceKind === 'sample') { const s = this.samplesFor(this.framing)[0]; if (s) return this.useSample(s); }
            if (this.sourceKind !== 'camera') await this.detectStill(); else { this.found = !!dets[this.kind]; this.updateFit(true); }
        },
        tryDesign(d) { this.apply(d.category, d.params); this.$refs.stage.scrollIntoView({ behavior: 'smooth', block: 'center' }); },
        normalized(category, params) {
            const c = this.catalog[category]; const out = {};
            Object.keys(c.defaults).forEach((k) => { out[k] = params?.[k] ?? c.defaults[k]; });
            return out;
        },
        describe(category, p, withCategory = true) {
            const c = this.catalog[category]; if (!c || !p) return '';
            const parts = {
                lashes: () => [c.styles[p.style]?.label, `${p.length} ${this.i18n.mm}`, c.curls[p.curl]?.label, `${p.fullness}%`],
                brows: () => [c.shapes[p.shape]?.label, c.shades[p.shade]?.label, `${p.intensity}%`],
                lips: () => [c.shades[p.shade]?.label, c.finishes[p.finish]?.label, `${p.intensity}%`],
                nails: () => [c.colors[p.color]?.label, c.shapes[p.shape]?.label, c.lengths[p.length]?.label, c.finishes[p.finish]?.label],
            }[category]();
            return [withCategory ? this.i18n.categories[category] : null, ...parts].filter(Boolean).join(' · ');
        },
        profileAt(stops, t) { const f = Math.min(1, Math.max(0, t)) * (stops.length - 1); const i = Math.min(Math.floor(f), stops.length - 2); return stops[i] + (stops[i + 1] - stops[i]) * (f - i); },
        /** Mini lash line: lengths exaggerated from the style's profile so each style reads at a glance (outer corner on the right). */
        lashIcon(s) {
            const lid = (t) => ({ x: 6 + 48 * (1 - t), y: 20 - 7 * Math.sin(Math.PI * t) });
            let d = `M${lid(0).x} ${lid(0).y} Q30 6 ${lid(1).x} ${lid(1).y}`;
            const n = Math.round(9 + 5 * (s.density - 0.8));
            for (let i = 0; i <= n; i++) {
                const t = i / n; const p = lid(t);
                let L = 1.5 + 12 * Math.pow(this.profileAt(s.profile, t), 2.2);
                if (s.spikes && i % 3 === 0) L *= 1.35;
                const a = -Math.PI / 2 + (0.75 - 1.1 * t + 1.2 * s.sweep * (1 - t)) * 0.75;
                d += ` M${p.x.toFixed(1)} ${p.y.toFixed(1)} l${(Math.cos(a) * L).toFixed(1)} ${(Math.sin(a) * L).toFixed(1)}`;
            }
            return d;
        },
        browIcon(s) {
            const top = []; const bottom = [];
            for (let i = 0; i <= 12; i++) {
                const t = i / 12; const x = 4 + 52 * t;
                const arch = t < 0.64 ? Math.sin(Math.PI / 2 * t / 0.64) : Math.cos(Math.PI / 2 * (t - 0.64) / 0.36) * 1.15 - 0.15;
                const mid = 14 - 6 * s.arch * arch - 4 * s.lift * t - 2 * t;
                const th = 5 * s.thick * (t < 0.18 ? 0.86 + 0.8 * t : 1 - 0.62 * Math.pow((t - 0.18) / 0.82, 1.25));
                top.push(`${x.toFixed(1)} ${(mid - th / 2).toFixed(1)}`); bottom.unshift(`${x.toFixed(1)} ${(mid + th / 2).toFixed(1)}`);
            }
            return `M${top.join(' L')} L${bottom.join(' L')} Z`;
        },

        // ── Consent, AI, saving, booking ───────────────────────────────────
        needConsent(next) {
            if (this.minor && this.framing === 'face') { this.error = this.i18n.minor; return true; }
            if (this.consented) return false;
            this.pending = next; this.consentOpen = true; return true;
        },
        async giveConsent(form) {
            this.busy = 'consent'; this.error = '';
            try {
                const res = await fetch(this.urls.consent, { method: 'POST', headers: this.headers(), body: new FormData(form) });
                const json = await res.json(); if (!res.ok) throw new Error(json.message || this.i18n.failed);
                this.consented = true; this.minor = !!json.minor; this.consentOpen = false;
                const next = this.pending; this.pending = null; this.busy = '';
                if (next) this[next]();
            } catch (e) { this.error = e.message; } finally { if (this.busy === 'consent') this.busy = ''; }
        },
        blob(canvas, q = 0.9) { return new Promise((r) => canvas.toBlob(r, 'image/jpeg', q)); },
        async shots() {
            if (this.sourceKind === 'camera') await this.capture(); // the frozen frame is tracked again before the guide is drawn
            const photo = document.createElement('canvas'); this.render(photo, false);
            const guide = document.createElement('canvas'); this.render(guide, true);
            const fitTo = (c) => this.downscale(c, 1600);
            return { photo: await this.blob(fitTo(photo)), guide: await this.blob(fitTo(guide), 0.85) };
        },
        async makeReal() {
            if (this.needConsent('makeReal')) return;
            this.busy = 'real'; this.error = ''; this.statusText = this.i18n.working;
            try {
                const { photo, guide } = await this.shots();
                const body = new FormData();
                body.append('category', this.cat); body.append('params', JSON.stringify(this.normalized(this.cat, this.params)));
                body.append('photo', photo, 'photo.jpg'); body.append('guide', guide, 'guide.jpg');
                const job = await this.post(this.urls.render, body);
                const done = await this.poll(job);
                if (done.redirect) { window.location = done.redirect; return; }
                throw new Error(this.i18n.failed);
            } catch (e) { this.error = e.message; this.busy = ''; }
        },
        async askArtist() {
            if (this.needConsent('askArtist')) return;
            this.busy = 'advice'; this.error = ''; this.statusText = this.i18n.adviceWorking;
            try {
                const { photo } = await this.shots();
                const body = new FormData(); body.append('category', this.cat); body.append('photo', photo, 'photo.jpg');
                const done = await this.poll(await this.post(this.urls.advice, body));
                if (!done.advice) throw new Error(this.i18n.failed);
                this.advice = { ...done.advice, summary: this.describe(done.advice.category, done.advice.params, false) };
            } catch (e) { this.error = e.message; } finally { this.busy = ''; }
        },
        async post(url, body) {
            const res = await fetch(url, { method: 'POST', headers: this.headers(), body });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(json.message || (json.errors && Object.values(json.errors)[0][0]) || this.i18n.failed);
            return json;
        },
        async poll(job) {
            for (let i = 0; i < 160; i++) {
                if (job.status === 'succeeded') return job;
                if (job.status === 'failed' || job.status === 'refunded') throw new Error(job.error || this.i18n.failed);
                await wait(1500);
                job = await (await fetch(job.status_url, { headers: this.headers() })).json();
            }
            throw new Error(this.i18n.failed);
        },
        async save(quiet = false) {
            this.error = '';
            try {
                const res = await fetch(this.urls.designs, { method: 'POST', headers: { ...this.headers(), 'Content-Type': 'application/json' },
                    body: JSON.stringify({ category: this.cat, params: this.normalized(this.cat, this.params) }) });
                const json = await res.json(); if (!res.ok) throw new Error(json.message || this.i18n.failed);
                this.designs = json.designs;
                if (!quiet) { this.notice = this.i18n.savedNotice; setTimeout(() => (this.notice = ''), 2500); }
                return json.design;
            } catch (e) { this.error = e.message; return null; }
        },
        async book() {
            this.busy = 'book';
            const d = await this.save(true);
            this.busy = '';
            if (d?.book) window.location = d.book;
        },
        async remove(d) {
            const res = await fetch(d.delete, { method: 'DELETE', headers: this.headers() });
            if (res.ok) this.designs = (await res.json()).designs;
        },
        headers() { return { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }; },
    };
};
</script>
