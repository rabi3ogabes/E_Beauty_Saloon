/*
 * Luxe banner studio — tiny Alpine component (no dependencies).
 * The design is a JSON document rendered twice: as positioned HTML for editing, and onto a <canvas> at full
 * export resolution for the PNG (so exports are pixel-exact and work offline, Arabic shaping included via the browser).
 *
 * design = { background: {type: 'gradient'|'solid'|'image', from, to, color, image, overlay}, elements: [
 *   {id, type: 'text'|'logo'|'pill', text, x, y, w (percent of width), size (px @ export), color, bg, font, weight, align, italic, letter}
 * ]}
 */
(function () {
    const ARABIC = /[؀-ۿ]/;
    const uid = () => Math.random().toString(36).slice(2, 9);

    function templates(brand, size, copy) {
        const [W, H] = size;
        const tall = H / W > 1.3;
        const wide = W / H > 1.5;
        const base = {
            headline: { type: 'text', text: copy.headline, x: 50, y: tall ? 40 : (wide ? 38 : 40), w: 84, size: wide ? 86 : (tall ? 120 : 104), color: brand.ink, font: brand.display, weight: 600, align: 'center' },
            subline: { type: 'text', text: copy.subline, x: 50, y: tall ? 52 : (wide ? 58 : 56), w: 80, size: wide ? 34 : 44, color: brand.ink, font: brand.body, weight: 400, align: 'center', letter: 1 },
            price: { type: 'pill', text: copy.price, x: 50, y: tall ? 64 : (wide ? 76 : 70), w: 40, size: wide ? 30 : 38, color: '#FFFFFF', bg: brand.primary, font: brand.body, weight: 600, align: 'center' },
            cta: { type: 'text', text: copy.cta.toUpperCase(), x: 50, y: tall ? 88 : 88, w: 60, size: wide ? 24 : 30, color: brand.ink, font: brand.body, weight: 600, align: 'center', letter: 6 },
            logo: { type: 'logo', x: 50, y: tall ? 9 : 11, w: wide ? 18 : 26, size: 0 },
        };
        const clone = (o) => Object.assign({ id: uid() }, JSON.parse(JSON.stringify(o)));
        return {
            editorial: {
                background: { type: 'gradient', from: brand.background, to: brand.accent },
                elements: [clone(base.logo), clone(base.headline), clone(base.subline), clone(base.price), clone(base.cta)],
            },
            minimal: {
                background: { type: 'solid', color: brand.background },
                elements: [clone(base.logo), Object.assign(clone(base.headline), { color: brand.primaryDark }), clone(base.subline), clone(base.cta)],
            },
            offer: {
                background: { type: 'gradient', from: '#1E1620', to: '#3A2F3D' },
                elements: [clone(base.logo), Object.assign(clone(base.headline), { color: '#F4E9D3' }), Object.assign(clone(base.subline), { color: '#E9D6CF' }), clone(base.price), Object.assign(clone(base.cta), { color: brand.primary })],
            },
            arabic: {
                background: { type: 'gradient', from: brand.accent, to: brand.background },
                elements: [clone(base.logo),
                    Object.assign(clone(base.headline), { text: copy.headline_ar, font: 'Amiri', weight: 700 }),
                    Object.assign(clone(base.subline), { text: copy.subline_ar, font: 'IBM Plex Sans Arabic' }),
                    Object.assign(clone(base.price), { text: copy.price_ar, font: 'IBM Plex Sans Arabic' }),
                    Object.assign(clone(base.cta), { text: copy.cta_ar, font: 'IBM Plex Sans Arabic', letter: 0 })],
            },
        };
    }

    function loadImage(src) {
        return new Promise((resolve) => {
            if (!src) return resolve(null);
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = src;
        });
    }

    function wrap(ctx, text, maxWidth) {
        const lines = [];
        String(text || '').split('\n').forEach((para) => {
            const words = para.split(' ');
            let line = '';
            words.forEach((word) => {
                const test = line ? line + ' ' + word : word;
                if (ctx.measureText(test).width > maxWidth && line) { lines.push(line); line = word; } else { line = test; }
            });
            lines.push(line);
        });
        return lines;
    }

    async function render(design, size, logoUrl) {
        const [W, H] = size;
        const canvas = document.createElement('canvas');
        canvas.width = W; canvas.height = H;
        const ctx = canvas.getContext('2d');
        if (document.fonts && document.fonts.ready) { await document.fonts.ready; }

        const bg = design.background || {};
        if (bg.type === 'image' && bg.image) {
            const img = await loadImage(bg.image);
            if (img) {
                const scale = Math.max(W / img.width, H / img.height);
                ctx.drawImage(img, (W - img.width * scale) / 2, (H - img.height * scale) / 2, img.width * scale, img.height * scale);
                ctx.fillStyle = `rgba(0,0,0,${bg.overlay ?? 0.15})`;
                ctx.fillRect(0, 0, W, H);
            }
        } else if (bg.type === 'gradient') {
            const g = ctx.createLinearGradient(0, 0, W, H);
            g.addColorStop(0, bg.from || '#FAF7F2'); g.addColorStop(1, bg.to || '#E9D6CF');
            ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
        } else {
            ctx.fillStyle = bg.color || '#FAF7F2'; ctx.fillRect(0, 0, W, H);
        }
        // hairline frame
        ctx.strokeStyle = 'rgba(200,169,106,0.45)'; ctx.lineWidth = Math.max(2, W / 540);
        const inset = W * 0.035; ctx.strokeRect(inset, inset, W - inset * 2, H - inset * 2);

        const logo = await loadImage(logoUrl);
        for (const el of design.elements || []) {
            const cx = (el.x / 100) * W, cy = (el.y / 100) * H, maxW = (el.w / 100) * W;
            if (el.type === 'logo') {
                if (!logo) continue;
                const w = maxW, h = (logo.height / logo.width) * w;
                ctx.drawImage(logo, cx - w / 2, cy - h / 2, w, h);
                continue;
            }
            const rtl = ARABIC.test(el.text || '');
            ctx.direction = rtl ? 'rtl' : 'ltr';
            ctx.font = `${el.italic ? 'italic ' : ''}${el.weight || 400} ${el.size}px "${el.font}", "IBM Plex Sans Arabic", serif`;
            if ('letterSpacing' in ctx) ctx.letterSpacing = `${el.letter || 0}px`;
            ctx.textBaseline = 'middle';
            const lines = wrap(ctx, el.text, maxW);
            const lh = el.size * 1.18;
            const top = cy - ((lines.length - 1) * lh) / 2;
            const align = el.align || 'center';
            ctx.textAlign = align === 'center' ? 'center' : (align === 'left' ? 'left' : 'right');
            const x = align === 'center' ? cx : (align === 'left' ? cx - maxW / 2 : cx + maxW / 2);
            if (el.type === 'pill') {
                const tw = Math.min(maxW, Math.max(...lines.map((l) => ctx.measureText(l).width)) + el.size * 1.6);
                const th = lh * lines.length + el.size * 0.7;
                const r = th / 2;
                ctx.fillStyle = el.bg || '#C8A96A';
                ctx.beginPath();
                ctx.roundRect ? ctx.roundRect(cx - tw / 2, cy - th / 2, tw, th, r) : ctx.rect(cx - tw / 2, cy - th / 2, tw, th);
                ctx.fill();
            }
            ctx.fillStyle = el.color || '#1C1A17';
            lines.forEach((line, i) => ctx.fillText(line, x, top + i * lh));
        }
        return canvas;
    }

    window.luxeBannerStudio = function (config) {
        return {
            format: config.format,
            formats: config.formats,
            template: config.template || 'editorial',
            design: config.design || null,
            selected: null,
            dragging: null,
            busy: false,
            aiPrompt: '',
            brand: config.brand,
            fonts: config.fonts,
            init() {
                if (!this.design || !this.design.elements) this.applyTemplate(this.template);
            },
            get size() { return this.formats[this.format]; },
            get ratio() { return `${this.size[0]} / ${this.size[1]}`; },
            get current() { return this.design.elements.find((e) => e.id === this.selected) || null; },
            scale() { return (this.$refs.stage?.clientWidth || 360) / this.size[0]; },
            applyTemplate(key) { this.template = key; this.design = templates(this.brand, this.size, config.copy)[key]; this.selected = null; },
            setFormat(key) { this.format = key; this.applyTemplate(this.template); },
            bgStyle() {
                const bg = this.design.background || {};
                if (bg.type === 'image' && bg.image) return `background-image: linear-gradient(rgba(0,0,0,${bg.overlay ?? 0.15}), rgba(0,0,0,${bg.overlay ?? 0.15})), url('${bg.image}'); background-size: cover; background-position: center;`;
                if (bg.type === 'gradient') return `background: linear-gradient(135deg, ${bg.from}, ${bg.to});`;
                return `background: ${bg.color};`;
            },
            elStyle(el) {
                const s = this.scale();
                const base = `left:${el.x}%; top:${el.y}%; width:${el.w}%; transform: translate(-50%,-50%);`;
                if (el.type === 'logo') return base;
                return base + `font-family:'${el.font}', 'IBM Plex Sans Arabic', serif; font-size:${el.size * s}px; font-weight:${el.weight}; color:${el.color}; text-align:${el.align}; letter-spacing:${(el.letter || 0) * s}px; line-height:1.18;` +
                    (el.italic ? 'font-style:italic;' : '');
            },
            pillStyle(el) {
                const s = this.scale();
                return `background:${el.bg}; color:${el.color}; padding:${el.size * s * 0.35}px ${el.size * s * 0.8}px; border-radius:999px; display:inline-block;`;
            },
            addText() {
                const el = { id: uid(), type: 'text', text: 'Your text', x: 50, y: 50, w: 70, size: 48, color: this.brand.ink, font: this.brand.body, weight: 500, align: 'center', letter: 0 };
                this.design.elements.push(el); this.selected = el.id;
            },
            addLogo() {
                const el = { id: uid(), type: 'logo', x: 50, y: 12, w: 24, size: 0 };
                this.design.elements.push(el); this.selected = el.id;
            },
            removeSelected() { this.design.elements = this.design.elements.filter((e) => e.id !== this.selected); this.selected = null; },
            startDrag(e, el) {
                this.selected = el.id;
                const rect = this.$refs.stage.getBoundingClientRect();
                this.dragging = { el, rect, sx: e.clientX, sy: e.clientY, ox: el.x, oy: el.y };
                e.target.setPointerCapture?.(e.pointerId);
            },
            onDrag(e) {
                if (!this.dragging) return;
                const d = this.dragging;
                d.el.x = Math.min(98, Math.max(2, d.ox + ((e.clientX - d.sx) / d.rect.width) * 100));
                d.el.y = Math.min(98, Math.max(2, d.oy + ((e.clientY - d.sy) / d.rect.height) * 100));
            },
            endDrag() { this.dragging = null; },
            async exportPng(download = true) {
                const canvas = await render(this.design, this.size, config.logoUrl);
                if (download) {
                    const a = document.createElement('a');
                    a.href = canvas.toDataURL('image/png');
                    a.download = (config.name || 'banner') + '.png';
                    a.click();
                }
                return canvas;
            },
            async save() {
                this.busy = true;
                try {
                    const canvas = await this.exportPng(false);
                    const blob = await new Promise((r) => canvas.toBlob(r, 'image/png'));
                    await new Promise((resolve, reject) => this.$wire.upload('png', new File([blob], 'banner.png', { type: 'image/png' }), resolve, reject));
                    await this.$wire.saveBanner(this.format, this.template, JSON.parse(JSON.stringify(this.design)));
                } finally { this.busy = false; }
            },
            async generate() {
                this.busy = true;
                try {
                    const result = await this.$wire.generateWithAi(this.aiPrompt, this.format);
                    if (result.image_url) this.design.background = { type: 'image', image: result.image_url, overlay: 0.2 };
                    const headline = this.design.elements.find((e) => e.type === 'text');
                    if (headline && result.headline) headline.text = result.headline;
                } finally { this.busy = false; }
            },
        };
    };
})();
