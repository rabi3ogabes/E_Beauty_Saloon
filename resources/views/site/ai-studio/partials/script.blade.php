{{-- Inline (classic) script: defined before the deferred Alpine module boots. --}}
<script>
window.luxeStudio = function (cfg) {
    const labels = @js(collect(\App\Domain\AIStudio\Models\Style::CATEGORIES)->keys()->mapWithKeys(fn ($c) => [$c => __('ai_studio.categories.'.$c)]));
    return {
        ...cfg, labels,
        mode: 'tryon', category: null, styleId: null, selected: null, framing: 'face',
        viewer: null, photo: 0, wantsCamera: false, lastFocus: null, rtl: document.documentElement.dir === 'rtl',
        photoN: @js(__('ai_studio.lookbook.photo_n', ['n' => '__N__', 'total' => '__T__'])),
        stream: null, streaming: false, facing: 'user', light: '', preview: null, file: null,
        busy: false, error: '', statusText: @js(__('ai_studio.status.queued')), lightTimer: null,
        init() {
            this.category = this.visibleCategories[0] || null;
            // Shared link: /studio?look=12 opens that look straight away.
            const shared = Number(new URLSearchParams(location.search).get('look'));
            const look = shared && this.styles.find((s) => s.id === shared && (!this.minor || !s.face));
            if (look) { this.category = look.category; this.$nextTick(() => this.openViewer(look)); }
            if (this.kiosk) { this.idleReset(); }
        },
        get visibleCategories() {
            return this.categories.filter((c) => !this.minor || !this.styles.find((s) => s.category === c && s.face) || this.styles.some((s) => s.category === c && !s.face));
        },
        stylesIn(cat) { return this.styles.filter((s) => s.category === cat && (!this.minor || !s.face)); },
        pick(s) { this.styleId = s.id; this.selected = s; this.framing = s.framing; this.error = ''; },

        // ── Look viewer ────────────────────────────────────────────────
        openViewer(s, from = null) {
            this.lastFocus = from || document.activeElement;
            this.viewer = s; this.photo = 0;
            document.documentElement.style.overflow = 'hidden';
            const url = new URL(location.href); url.searchParams.set('look', s.id); history.replaceState(history.state, '', url);
            this.$nextTick(() => { this.$refs.track && (this.$refs.track.scrollLeft = 0); this.$refs.closeBtn?.focus({ preventScroll: true }); });
        },
        closeViewer() {
            this.viewer = null;
            document.documentElement.style.overflow = '';
            const url = new URL(location.href); url.searchParams.delete('look'); history.replaceState(history.state, '', url);
            this.$nextTick(() => this.lastFocus?.focus?.({ preventScroll: true }));
        },
        goPhoto(i) {
            const track = this.$refs.track; if (!track || !this.viewer) return;
            this.photo = Math.max(0, Math.min(i, this.viewer.images.length - 1));
            // RTL tracks scroll towards negative scrollLeft.
            track.scrollTo({ left: (this.rtl ? -1 : 1) * this.photo * track.clientWidth, behavior: 'smooth' });
        },
        stepPhoto(d) { this.goPhoto(this.photo + d); },
        syncPhoto() {
            const t = this.$refs.track; if (!t || !t.clientWidth) return;
            this.photo = Math.round(Math.abs(t.scrollLeft) / t.clientWidth); // RTL scrollLeft is negative
        },
        photoLabel(i) { return this.photoN.replace('__N__', i + 1).replace('__T__', this.viewer?.images.length || 1); },
        trapFocus(e) {
            const els = [...(this.$refs.dialog?.querySelectorAll('button:not([disabled]), a[href]') || [])].filter((el) => el.offsetParent !== null);
            if (!els.length) return;
            const first = els[0], last = els[els.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        },
        tryFromViewer() {
            const s = this.viewer; this.closeViewer();
            this.mode = 'tryon'; this.pick(s); this.wantCamera();
        },
        wantCamera() {
            this.wantsCamera = true;
            this.$nextTick(() => (this.consented ? this.$refs.capture : this.$refs.consent)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        },
        async giveConsent(form) {
            this.busy = true; this.error = '';
            try {
                const res = await fetch(this.urls.consent, { method: 'POST', headers: this.headers(), body: new FormData(form) });
                const json = await res.json();
                if (!res.ok) throw new Error(json.message || this.i18n.failed);
                this.consented = true; this.minor = !!json.minor;
                if (!this.visibleCategories.includes(this.category)) this.category = this.visibleCategories[0] || null;
                if (this.minor && this.selected?.face) { this.styleId = null; this.selected = null; }
                this.$nextTick(() => this.$refs.capture?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
            } catch (e) { this.error = e.message; } finally { this.busy = false; }
        },
        async startCamera() {
            this.error = '';
            if (!navigator.mediaDevices?.getUserMedia) { this.error = this.i18n.noCamera; return; }
            try {
                this.stopCamera();
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: this.facing, width: { ideal: 1280 }, height: { ideal: 1706 } }, audio: false });
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();
                this.streaming = true; this.preview = null; this.file = null;
                this.lightTimer = setInterval(() => this.checkLight(), 700);
            } catch (e) { this.error = this.i18n.noCamera; }
        },
        flip() { this.facing = this.facing === 'user' ? 'environment' : 'user'; this.startCamera(); },
        stopCamera() {
            clearInterval(this.lightTimer);
            this.stream?.getTracks().forEach((t) => t.stop());
            this.stream = null; this.streaming = false;
        },
        checkLight() {
            const v = this.$refs.video; if (!v.videoWidth) return;
            const c = document.createElement('canvas'); c.width = 48; c.height = 64;
            const ctx = c.getContext('2d', { willReadFrequently: true }); ctx.drawImage(v, 0, 0, 48, 64);
            const d = ctx.getImageData(0, 0, 48, 64).data; let sum = 0;
            for (let i = 0; i < d.length; i += 4) sum += 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2];
            const avg = sum / (d.length / 4);
            this.light = avg < 60 ? this.i18n.tooDark : (avg > 215 ? this.i18n.tooBright : this.i18n.goodLight);
        },
        snap() {
            const v = this.$refs.video; const c = document.createElement('canvas');
            c.width = v.videoWidth; c.height = v.videoHeight;
            const ctx = c.getContext('2d');
            if (this.facing === 'user') { ctx.translate(c.width, 0); ctx.scale(-1, 1); }
            ctx.drawImage(v, 0, 0);
            c.toBlob((blob) => { this.file = new File([blob], 'capture.jpg', { type: 'image/jpeg' }); this.preview = URL.createObjectURL(blob); this.stopCamera(); }, 'image/jpeg', 0.9);
        },
        choose(e) {
            const f = e.target.files[0]; if (!f) return;
            this.file = f; this.preview = URL.createObjectURL(f); this.error = '';
        },
        async submit() {
            if (!this.file) return;
            this.busy = true; this.error = ''; this.statusText = @js(__('ai_studio.status.queued'));
            const body = new FormData(); body.append('photo', this.file);
            if (this.mode === 'tryon') body.append('style_id', this.styleId);
            try {
                const res = await fetch(this.mode === 'tryon' ? this.urls.tryOn : this.urls.consultant, { method: 'POST', headers: this.headers(), body });
                const json = await res.json();
                if (!res.ok) throw new Error(json.message || (json.errors && Object.values(json.errors)[0][0]) || this.i18n.failed);
                await this.poll(json);
            } catch (e) { this.error = e.message; this.busy = false; }
        },
        async poll(job) {
            const texts = { queued: @js(__('ai_studio.status.queued')), running: @js(__('ai_studio.status.running')) };
            for (let i = 0; i < 120; i++) {
                if (job.redirect) { window.location = job.redirect; return; }
                if (job.status === 'failed' || job.status === 'refunded') throw new Error(job.error || this.i18n.failed);
                this.statusText = texts[job.status] || texts.running;
                await new Promise((r) => setTimeout(r, 1500));
                const res = await fetch(job.status_url, { headers: this.headers() });
                job = await res.json();
            }
            throw new Error(this.i18n.failed);
        },
        headers() { return { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }; },
        idleReset() {
            let t; const reset = () => { clearTimeout(t); t = setTimeout(() => { window.location = this.urls.reset; }, 120000); };
            ['pointerdown', 'keydown'].forEach((ev) => document.addEventListener(ev, reset)); reset();
        },
    };
};
</script>
