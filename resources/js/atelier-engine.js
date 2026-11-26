/*
 * Live Atelier engine: on-device face/hand tracking (MediaPipe Tasks Vision) and the canvas
 * renderers for lashes, brows, lips and nails. Nothing leaves the device here.
 *
 * Landmarks are normalised (0..1) to the source image; every renderer works in source pixels.
 */
import { FaceLandmarker, FilesetResolver, HandLandmarker } from '@mediapipe/tasks-vision';

// ── Landmark maps (MediaPipe face mesh, 478 points with irises) ───────────────────────────
const EYES = [
    { outer: 33, upper: [246, 161, 160, 159, 158, 157, 173], inner: 133, lower: [7, 163, 144, 145, 153, 154, 155], iris: [469, 471] },
    { outer: 263, upper: [466, 388, 387, 386, 385, 384, 398], inner: 362, lower: [249, 390, 373, 374, 380, 381, 382], iris: [474, 476] },
];
const BROWS = [
    { upper: [70, 63, 105, 66, 107], lower: [46, 53, 52, 65, 55] }, // outer → inner
    { upper: [300, 293, 334, 296, 336], lower: [276, 283, 282, 295, 285] },
];
const LIPS_OUTER = [61, 185, 40, 39, 37, 0, 267, 269, 270, 409, 291, 375, 321, 405, 314, 17, 84, 181, 91, 146];
const LIPS_INNER = [78, 191, 80, 81, 82, 13, 312, 311, 310, 415, 308, 324, 318, 402, 317, 14, 87, 178, 88, 95];
const FINGERS = [ // [tip, dip, pip, width factor]
    [4, 3, 2, 0.5], [8, 7, 6, 0.52], [12, 11, 10, 0.54], [16, 15, 14, 0.5], [20, 19, 18, 0.46],
];
const IRIS_MM = 11.7;

// ── Small geometry kit ────────────────────────────────────────────────────────────────────
const v = (x, y) => ({ x, y });
const add = (a, b) => v(a.x + b.x, a.y + b.y);
const sub = (a, b) => v(a.x - b.x, a.y - b.y);
const mul = (a, k) => v(a.x * k, a.y * k);
const len = (a) => Math.hypot(a.x, a.y);
const dist = (a, b) => len(sub(a, b));
const norm = (a) => { const l = len(a) || 1; return v(a.x / l, a.y / l); };
const perp = (a) => v(-a.y, a.x);
const dot = (a, b) => a.x * b.x + a.y * b.y;
const lerp = (a, b, t) => a + (b - a) * t;
const mix = (a, b, t) => v(lerp(a.x, b.x, t), lerp(a.y, b.y, t));
const mean = (pts) => mul(pts.reduce(add, v(0, 0)), 1 / pts.length);
const clamp = (x, a, b) => Math.max(a, Math.min(b, x));

/** Catmull-Rom through points → n evenly spread samples (by index, good enough for short contours). */
function spline(points, n, closed = false) {
    const p = closed ? points : [points[0], ...points, points[points.length - 1]];
    const segs = closed ? p.length : p.length - 3;
    const out = [];
    for (let i = 0; i < n; i++) {
        const f = (closed ? i / n : i / (n - 1)) * segs;
        const s = Math.min(Math.floor(f), segs - 1);
        const t = f - s;
        const g = (k) => (closed ? p[(s + k - 1 + p.length) % p.length] : p[s + k]);
        const [p0, p1, p2, p3] = [g(0), g(1), g(2), g(3)];
        const t2 = t * t; const t3 = t2 * t;
        out.push(v(
            0.5 * (2 * p1.x + (-p0.x + p2.x) * t + (2 * p0.x - 5 * p1.x + 4 * p2.x - p3.x) * t2 + (-p0.x + 3 * p1.x - 3 * p2.x + p3.x) * t3),
            0.5 * (2 * p1.y + (-p0.y + p2.y) * t + (2 * p0.y - 5 * p1.y + 4 * p2.y - p3.y) * t2 + (-p0.y + 3 * p1.y - 3 * p2.y + p3.y) * t3),
        ));
    }
    return out;
}
/** Value of a piecewise-linear profile (evenly spaced stops) at t ∈ [0, 1]. */
function profileAt(stops, t) {
    const f = clamp(t, 0, 1) * (stops.length - 1);
    const i = Math.min(Math.floor(f), stops.length - 2);
    return lerp(stops[i], stops[i + 1], f - i);
}
function rng(seed) { // mulberry32: stable "random" so a design never flickers between frames
    let a = seed >>> 0;
    return () => { a |= 0; a = (a + 0x6D2B79F5) | 0; let t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}
function hexRgb(hex) { const n = parseInt(hex.slice(1), 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; }
function rgba(hex, a, shade = 1) { const [r, g, b] = hexRgb(hex).map((c) => clamp(Math.round(shade >= 1 ? c + (255 - c) * (shade - 1) : c * shade), 0, 255)); return `rgba(${r},${g},${b},${a})`; }
function pathFrom(ctx, pts, closed = true) { ctx.moveTo(pts[0].x, pts[0].y); for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i].x, pts[i].y); if (closed) ctx.closePath(); }
const canFilter = typeof CanvasRenderingContext2D !== 'undefined' && 'filter' in CanvasRenderingContext2D.prototype;
function blur(ctx, px) { if (canFilter) ctx.filter = px > 0.2 ? `blur(${px.toFixed(1)}px)` : 'none'; }

// ── Face measurements ─────────────────────────────────────────────────────────────────────
function facePoints(lm, w, h) { return (i) => v(lm[i].x * w, lm[i].y * h); }
function pxPerMm(P, lm) {
    if (lm.length >= 478) {
        const d = (dist(P(EYES[0].iris[0]), P(EYES[0].iris[1])) + dist(P(EYES[1].iris[0]), P(EYES[1].iris[1]))) / 2;
        if (d > 2) return d / IRIS_MM;
    }
    return (dist(P(33), P(133)) + dist(P(263), P(362))) / 2 / 28; // an eye is ~28 mm wide
}

/** Eye shape read from geometry (free, instant) and the lash design it suggests. */
export function eyeFit(lm, w, h) {
    const P = facePoints(lm, w, h);
    const eyes = EYES.map((e) => {
        const outer = P(e.outer); const inner = P(e.inner);
        const width = dist(outer, inner);
        const height = dist(P(e.upper[3]), P(e.lower[3]));
        // Tilt: outer corner above the inner corner (screen y grows downwards) → upturned.
        const along = norm(sub(outer, inner));
        const faceUp = norm(sub(P(10), P(152))); // forehead − chin
        const tilt = Math.asin(clamp(dot(along, faceUp), -1, 1)) * 180 / Math.PI;
        return { width, ratio: height / width, tilt };
    });
    const ratio = (eyes[0].ratio + eyes[1].ratio) / 2;
    const tilt = (eyes[0].tilt + eyes[1].tilt) / 2;
    const spacing = dist(P(133), P(362)) / ((eyes[0].width + eyes[1].width) / 2);
    const shape = ratio > 0.36 ? 'round' : (ratio < 0.25 ? 'hooded' : 'almond');
    // A natural canthal tilt is about +4–8°, and the gap between the eyes is about 1.1 eye widths.
    const lift = tilt > 9 ? 'upturned' : (tilt < 2 ? 'downturned' : 'level');
    const set = spacing < 0.98 ? 'close' : (spacing > 1.28 ? 'wide' : 'balanced');
    let suggest = { style: 'wispy', length: 12, curl: 'C', fullness: 65 };
    if (shape === 'round') suggest = { style: 'cat_eye', length: 12, curl: 'C', fullness: 65 };
    if (shape === 'hooded') suggest = { style: 'doll_eye', length: 11, curl: 'L', fullness: 60 };
    if (lift === 'downturned') suggest = { style: 'foxy', length: 12, curl: 'D', fullness: 65 };
    if (lift === 'upturned' && shape !== 'hooded') suggest = { style: 'doll_eye', length: 12, curl: 'C', fullness: 70 };
    if (set === 'close' && shape !== 'hooded') suggest = { ...suggest, style: 'cat_eye' };
    if (set === 'wide' && shape !== 'hooded') suggest = { ...suggest, style: 'soft_volume' };
    return { shape, lift, set, suggest, metrics: { ratio: +ratio.toFixed(3), tilt: +tilt.toFixed(1), spacing: +spacing.toFixed(2) } };
}

// ── Lashes ────────────────────────────────────────────────────────────────────────────────
function drawLashes(ctx, lm, w, h, p, cat) {
    const P = facePoints(lm, w, h);
    const mm = pxPerMm(P, lm);
    const style = cat.styles[p.style]; const bend = cat.curls[p.curl].bend;
    EYES.forEach((e, side) => {
        const line = spline([P(e.outer), ...e.upper.map(P), P(e.inner)], 64);
        const centre = mean([...e.upper, ...e.lower].map(P));
        const outward = norm(sub(P(e.outer), P(e.inner)));
        const eyeMm = dist(P(e.outer), P(e.inner)) / mm;
        const count = clamp(Math.round(eyeMm * 3.4 * (p.fullness / 100) * style.density), 18, 240);
        const random = rng(7919 * (side + 1) + count);
        ctx.save();
        ctx.globalCompositeOperation = 'multiply';
        ctx.strokeStyle = 'rgba(20,14,12,0.55)'; ctx.lineWidth = Math.max(1, mm * 0.35); ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        blur(ctx, mm * 0.12);
        ctx.beginPath(); pathFrom(ctx, line.slice(2, -2), false); ctx.stroke();
        ctx.restore();

        ctx.save();
        const baseWidth = Math.max(0.9, mm * 0.085) * (style.density > 1.2 ? 1.15 : 1);
        for (let k = 0; k < count; k++) {
            const t = clamp((k + 0.15 + random() * 0.7) / count, 0.01, 0.99); // 0 = outer corner, 1 = inner corner
            const f = t * (line.length - 1); const i = Math.min(Math.floor(f), line.length - 2);
            const base = mix(line[i], line[i + 1], f - i);
            const tangent = norm(sub(line[i + 1], line[i]));
            let n = perp(tangent); if (dot(n, sub(base, centre)) < 0) n = mul(n, -1);
            // Lashes fan out: towards the outer corner on the outer half, upright in the middle, slightly inward at the inner corner.
            const fan = lerp(0.95, -0.3, t) + style.sweep * Math.pow(1 - t, 1.3) + (random() - 0.5) * 0.18;
            const start = norm(add(n, mul(outward, fan)));
            let rel = profileAt(style.profile, t) * (0.82 + 0.18 * Math.min(1, t / 0.06)) * Math.min(1, (1 - t) / 0.1 + 0.3);
            if (style.spikes && k % 7 < 2) rel *= 1.3;
            // Seen from the front, upright lashes point at the camera (short); fanned ones are seen side-on (longer).
            const foreshorten = 0.5 + 0.28 * Math.min(1, Math.abs(fan));
            const L = rel * p.length * mm * foreshorten * (0.9 + random() * 0.2);
            // Curl lifts the tip towards the lid normal; the mid-point bows outward along the fan.
            const tipDir = norm(add(start, mul(n, bend * 2.2)));
            const tip = add(base, mul(norm(add(mul(start, 0.55), mul(tipDir, 0.45))), L));
            const ctrl = add(base, mul(start, L * 0.6));
            // Tapered lash: thick root, fine tip.
            const pts = []; const left = []; const right = [];
            for (let s = 0; s <= 6; s++) {
                const q = s / 6; const a = 1 - q;
                pts.push(v(a * a * base.x + 2 * a * q * ctrl.x + q * q * tip.x, a * a * base.y + 2 * a * q * ctrl.y + q * q * tip.y));
            }
            pts.forEach((pt, s) => {
                const d = norm(sub(pts[Math.min(s + 1, 6)], pts[Math.max(s - 1, 0)]));
                const half = baseWidth * 0.5 * Math.pow(1 - s / 6, 0.85) + 0.12;
                left.push(add(pt, mul(perp(d), half))); right.push(add(pt, mul(perp(d), -half)));
            });
            ctx.fillStyle = `rgba(16,11,10,${(0.82 + random() * 0.15).toFixed(2)})`;
            ctx.beginPath(); pathFrom(ctx, [...left, ...right.reverse()]); ctx.fill();
        }
        ctx.restore();
    });
}

// ── Brows ─────────────────────────────────────────────────────────────────────────────────
function drawBrows(ctx, lm, w, h, p, cat) {
    const P = facePoints(lm, w, h);
    const shape = cat.shapes[p.shape]; const colour = cat.shades[p.shade].hex; const k = p.intensity / 100;
    const faceUp = norm(sub(P(10), P(152)));
    BROWS.forEach((b, side) => {
        // Centreline and thickness of the client's own brow, inner (head) → outer (tail).
        const upper = [...b.upper].reverse().map(P); const lower = [...b.lower].reverse().map(P);
        const centre = upper.map((u, i) => mix(u, lower[i], 0.5));
        const baseTh = upper.reduce((s, u, i) => s + dist(u, lower[i]), 0) / upper.length;
        const tailDir = norm(sub(centre[4], centre[3]));
        const ext = add(centre[4], mul(tailDir, dist(centre[3], centre[4]) * (shape.tail - 1) * 3));
        const line = spline([...centre.slice(0, 4), shape.tail > 1 ? ext : centre[4]], 40);
        const browLen = dist(line[0], line[line.length - 1]);
        const top = []; const bottom = [];
        line.forEach((c, i) => {
            const s = i / (line.length - 1);
            const arch = s < 0.64 ? Math.sin(Math.PI / 2 * s / 0.64) : Math.cos(Math.PI / 2 * (s - 0.64) / 0.36) * 1.15 - 0.15;
            const offset = mul(faceUp, browLen * (0.085 * shape.arch * arch + shape.lift * 0.1 * s));
            const th = baseTh * shape.thick * (s < 0.18 ? 0.86 + 0.8 * s : 1.0 - 0.62 * Math.pow((s - 0.18) / 0.82, 1.25));
            const mid = add(c, offset);
            const up = s === 1 ? faceUp : norm(perp(sub(line[Math.min(i + 1, line.length - 1)], line[Math.max(i - 1, 0)])));
            const u = dot(up, faceUp) < 0 ? mul(up, -1) : up;
            top.push(add(mid, mul(u, th / 2))); bottom.push(add(mid, mul(u, -th / 2)));
        });
        const poly = [...top, ...bottom.reverse()];
        ctx.save();
        ctx.globalCompositeOperation = 'multiply';
        ctx.fillStyle = rgba(colour, 0.18 + 0.5 * k);
        blur(ctx, baseTh * 0.18);
        ctx.beginPath(); pathFrom(ctx, poly); ctx.fill();
        ctx.restore();
        // Hair strokes for texture.
        const random = rng(4241 * (side + 1));
        const strokes = Math.round(browLen / Math.max(1, baseTh) * 26);
        ctx.save(); ctx.lineCap = 'round';
        blur(ctx, 0);
        // Hairs stay inside a slightly soft brow outline, so nothing pokes out like bristles.
        ctx.beginPath(); pathFrom(ctx, poly); ctx.clip();
        for (let i = 0; i < strokes; i++) {
            const s = random();
            const j = Math.min(Math.floor(s * (top.length - 1)), top.length - 2);
            const tPt = top[j]; const bPt = bottom[bottom.length - 1 - j];
            const pos = mix(bPt, tPt, 0.05 + random() * 0.7);
            const along = norm(sub(line[Math.min(j + 1, line.length - 1)], line[j]));
            const lean = (shape.strokes === 'up' ? 0.62 : (s < 0.22 ? 0.7 : 0.3)) + (random() - 0.5) * 0.25;
            const dir = norm(add(mul(along, 1 - lean), mul(faceUp, lean + 0.1)));
            const L = dist(tPt, bPt) * (0.35 + random() * 0.4);
            ctx.strokeStyle = rgba(colour, (0.25 + 0.45 * k) * (0.6 + random() * 0.4), 0.85 + random() * 0.2);
            ctx.lineWidth = Math.max(0.6, baseTh * 0.045);
            ctx.beginPath(); ctx.moveTo(pos.x, pos.y); ctx.lineTo(pos.x + dir.x * L, pos.y + dir.y * L); ctx.stroke();
        }
        ctx.restore();
    });
}

// ── Lips ──────────────────────────────────────────────────────────────────────────────────
function drawLips(ctx, lm, w, h, p, cat) {
    const P = facePoints(lm, w, h);
    const colour = cat.shades[p.shade].hex; const k = p.intensity / 100;
    const outer = spline(LIPS_OUTER.map(P), 80, true);
    const inner = spline(LIPS_INNER.map(P), 80, true);
    const mouth = dist(P(61), P(291));
    const lipShape = () => { ctx.beginPath(); pathFrom(ctx, outer); pathFrom(ctx, [...inner].reverse()); };
    ctx.save();
    blur(ctx, mouth * 0.008);
    ctx.globalCompositeOperation = 'multiply';
    ctx.fillStyle = rgba(colour, (p.finish === 'blush' ? 0.35 : 0.5) * (0.4 + 0.75 * k));
    lipShape(); ctx.fill('evenodd');
    ctx.globalCompositeOperation = 'soft-light';
    ctx.fillStyle = rgba(colour, 0.25 + 0.4 * k);
    lipShape(); ctx.fill('evenodd');
    if (p.finish === 'defined') {
        ctx.globalCompositeOperation = 'multiply';
        ctx.strokeStyle = rgba(colour, 0.25 + 0.3 * k, 0.72); ctx.lineWidth = Math.max(1, mouth * 0.012);
        ctx.beginPath(); pathFrom(ctx, outer); ctx.stroke();
    }
    if (p.finish === 'gloss') {
        ctx.globalCompositeOperation = 'screen';
        blur(ctx, mouth * 0.02);
        const lower = mix(P(14), P(17), 0.45); const upper = mix(P(13), P(0), 0.5);
        ctx.fillStyle = 'rgba(255,255,255,0.42)';
        ctx.beginPath(); ctx.ellipse(lower.x, lower.y, mouth * 0.16, dist(P(14), P(17)) * 0.16, 0, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = 'rgba(255,255,255,0.22)';
        ctx.beginPath(); ctx.ellipse(upper.x, upper.y, mouth * 0.08, dist(P(13), P(0)) * 0.12, 0, 0, Math.PI * 2); ctx.fill();
    }
    ctx.restore();
}

// ── Nails ─────────────────────────────────────────────────────────────────────────────────
function nailPath(ctx, shape, W, L) {
    // Local frame: x across the nail (−W/2..W/2), y from the cuticle (0) to the free edge (L).
    const r = W / 2; const c = Math.min(W * 0.45, L * 0.35); // cuticle curve depth
    ctx.beginPath();
    ctx.moveTo(-r, c);
    ctx.quadraticCurveTo(-r, 0, 0, 0); ctx.quadraticCurveTo(r, 0, r, c);
    switch (shape) {
        case 'square': ctx.lineTo(r, L - W * 0.1); ctx.quadraticCurveTo(r, L, r - W * 0.1, L); ctx.lineTo(-r + W * 0.1, L); ctx.quadraticCurveTo(-r, L, -r, L - W * 0.1); break;
        case 'coffin': ctx.lineTo(r, L * 0.55); ctx.lineTo(W * 0.3, L); ctx.lineTo(-W * 0.3, L); ctx.lineTo(-r, L * 0.55); break;
        case 'stiletto': ctx.lineTo(r, L * 0.45); ctx.quadraticCurveTo(r * 0.85, L * 0.8, 0, L); ctx.quadraticCurveTo(-r * 0.85, L * 0.8, -r, L * 0.45); break;
        case 'almond': ctx.lineTo(r, L * 0.55); ctx.bezierCurveTo(r, L * 0.86, W * 0.12, L, 0, L); ctx.bezierCurveTo(-W * 0.12, L, -r, L * 0.86, -r, L * 0.55); break;
        case 'oval': ctx.lineTo(r, L - r * 1.3); ctx.bezierCurveTo(r, L - r * 0.2, W * 0.2, L, 0, L); ctx.bezierCurveTo(-W * 0.2, L, -r, L - r * 0.2, -r, L - r * 1.3); break;
        default: ctx.lineTo(r, L - r); ctx.arc(0, L - r, r, 0, Math.PI, false); // round
    }
    ctx.closePath();
}
function drawNails(ctx, hands, w, h, p, cat) {
    const colour = cat.colors[p.color].hex; const ext = cat.lengths[p.length].ext;
    hands.forEach(({ lm, back }) => {
        const P = (i) => v(lm[i].x * w, lm[i].y * h);
        const wrist = P(0);
        FINGERS.forEach(([tipI, dipI, pipI, wf], fi) => {
            const tip = P(tipI); const dip = P(dipI); const pip = P(pipI);
            const curled = fi > 0 && dist(tip, wrist) < dist(pip, wrist) * 0.98;
            // Palm towards the camera: only curled fingertips show their nails (the thumb's is side-on).
            if (!back && !curled) return;
            const seg = sub(tip, dip); const sl = len(seg); if (sl < 3) return;
            const u = norm(seg);
            const W = sl * wf;
            // Straight finger seen from the back: the nail covers the end of the last segment.
            // Curled fingertip seen from the front: the nail starts at the fingertip point and runs on past it.
            const base = curled ? sub(tip, mul(u, sl * 0.06)) : add(dip, mul(seg, fi === 0 ? 0.3 : 0.38));
            const L = (curled ? sl * 0.8 : dist(base, tip) * 1.1 + sl * 0.06) + sl * ext; // always covers the natural free edge
            ctx.save();
            ctx.translate(base.x, base.y);
            ctx.rotate(Math.atan2(u.y, u.x) - Math.PI / 2);
            // Soft contact shadow, then the polish.
            ctx.save(); blur(ctx, W * 0.08); ctx.globalCompositeOperation = 'multiply'; ctx.fillStyle = 'rgba(80,50,40,0.25)';
            ctx.translate(0, W * 0.03); nailPath(ctx, p.shape, W * 1.04, L * 1.01); ctx.fill(); ctx.restore();
            blur(ctx, W * 0.012);
            const french = p.finish === 'french';
            const grad = ctx.createLinearGradient(-W / 2, 0, W / 2, 0);
            if (p.finish === 'chrome') {
                [[0, 0.62], [0.28, 1.25], [0.5, 0.85], [0.72, 1.3], [1, 0.6]].forEach(([o, s]) => grad.addColorStop(o, rgba(colour, 0.97, s)));
            } else {
                // French: a sheer pink-nude base that lets the natural nail show, then an opaque tip.
                const base0 = french ? '#E7BDB4' : colour; const a0 = french ? 0.5 : 0.96;
                grad.addColorStop(0, rgba(base0, a0, 0.86)); grad.addColorStop(0.5, rgba(base0, a0 + 0.02, 1.06)); grad.addColorStop(1, rgba(base0, a0, 0.84));
            }
            ctx.fillStyle = grad; nailPath(ctx, p.shape, W, L); ctx.fill();
            if (french) {
                ctx.save(); nailPath(ctx, p.shape, W, L); ctx.clip();
                ctx.fillStyle = rgba(p.color === 'milky_nude' ? '#FCFAF7' : colour, 1);
                ctx.beginPath(); ctx.ellipse(0, L, W * 0.8, Math.max(L * 0.3, sl * (0.14 + ext * 0.95)), 0, Math.PI, 0, false); ctx.fill(); // the half towards the cuticle
                ctx.restore();
            }
            if (p.finish !== 'matte') {
                ctx.globalCompositeOperation = 'screen'; blur(ctx, W * 0.05);
                ctx.fillStyle = `rgba(255,255,255,${p.finish === 'chrome' ? 0.55 : 0.4})`;
                ctx.beginPath(); ctx.ellipse(-W * 0.18, L * 0.45, W * 0.07, L * 0.28, 0, 0, Math.PI * 2); ctx.fill();
            }
            ctx.restore();
        });
    });
}

// ── Engine ────────────────────────────────────────────────────────────────────────────────
export async function createEngine(assets) {
    const fileset = await FilesetResolver.forVisionTasks(assets.wasm);
    const tasks = {};
    const make = async (kind, mode) => {
        const Task = kind === 'face' ? FaceLandmarker : HandLandmarker;
        const opts = (delegate) => ({
            baseOptions: { modelAssetPath: assets[kind], delegate },
            runningMode: mode,
            ...(kind === 'face' ? { numFaces: 1, minFaceDetectionConfidence: 0.4 } : { numHands: 2, minHandDetectionConfidence: 0.3, minHandPresenceConfidence: 0.3, minTrackingConfidence: 0.3 }),
        });
        try { return await Task.createFromOptions(fileset, opts('GPU')); } catch (e) { return Task.createFromOptions(fileset, opts('CPU')); }
    };
    const get = async (kind, mode) => {
        const t = tasks[kind];
        if (t && t.mode === mode) return t.task;
        if (t) { await t.task.setOptions({ runningMode: mode }); t.mode = mode; return t.task; }
        tasks[kind] = { task: await make(kind, mode), mode };
        return tasks[kind].task;
    };
    let lastTs = 0;
    return {
        /** Detect on an image (IMAGE mode) or a playing video (VIDEO mode). */
        async detect(kind, source, video = false) {
            const task = await get(kind, video ? 'VIDEO' : 'IMAGE');
            const ts = Math.max(performance.now(), lastTs + 1); lastTs = ts;
            let res = video ? task.detectForVideo(source, ts) : task.detect(source);
            let unpad = null;
            const empty = (r) => !(kind === 'face' ? r.faceLandmarks?.length : r.landmarks?.length);
            if (!video && empty(res)) {
                // Close-ups (a hand filling the frame) are easier to find zoomed out: retry on a padded copy.
                const sw = source.naturalWidth || source.width; const sh = source.naturalHeight || source.height;
                const pad = 0.35; const W = Math.round(sw * (1 + 2 * pad)); const H = Math.round(sh * (1 + 2 * pad));
                const c = document.createElement('canvas'); c.width = W; c.height = H;
                const cx = c.getContext('2d'); cx.fillStyle = '#ffffff'; cx.fillRect(0, 0, W, H);
                cx.drawImage(source, Math.round(sw * pad), Math.round(sh * pad), sw, sh);
                res = task.detect(c);
                unpad = (p) => ({ ...p, x: (p.x * W - sw * pad) / sw, y: (p.y * H - sh * pad) / sh });
            }
            if (unpad) {
                if (res.faceLandmarks) res = { ...res, faceLandmarks: res.faceLandmarks.map((l) => l.map(unpad)) };
                if (res.landmarks) res = { ...res, landmarks: res.landmarks.map((l) => l.map(unpad)) };
            }
            if (kind === 'face') return res.faceLandmarks?.[0] ? { face: res.faceLandmarks[0] } : null;
            const hands = (res.landmarks || []).map((lm, i) => {
                // On un-mirrored frames (photos, raw camera frames) the label is the real hand. Calibrated on
                // a left palm (curled) and a right back-of-hand: back of the hand ⇔ (Right and cross > 0) or (Left and cross < 0).
                const label = res.handedness?.[i]?.[0]?.categoryName === 'Left' ? 'Left' : 'Right';
                const P = (k) => lm[k];
                const cross = (P(5).x - P(0).x) * (P(17).y - P(0).y) - (P(5).y - P(0).y) * (P(17).x - P(0).x);
                return { lm, back: label === 'Right' ? cross > 0 : cross < 0 };
            });
            return hands.length ? { hands } : null;
        },
        close() { Object.values(tasks).forEach((t) => t.task.close()); },
    };
}

/** Draw a design over the current frame (landmarks in source pixels w × h). */
export function drawDesign(ctx, detection, category, params, catalog, w, h) {
    if (!detection) return;
    const cat = catalog[category];
    if (category === 'nails') { if (detection.hands) drawNails(ctx, detection.hands, w, h, params, cat); return; }
    if (!detection.face) return;
    ({ lashes: drawLashes, brows: drawBrows, lips: drawLips })[category](ctx, detection.face, w, h, params, cat);
}

/** Lash map for the UI: lengths (mm) from the outer to the inner corner. */
export function lashMap(style, length) {
    return [0, 0.25, 0.5, 0.75, 1].map((t) => Math.round(profileAt(style.profile, t) * length));
}

// Vite entry chunks do not keep their exports: the page reaches the engine through window.
window.LuxeAtelier = { createEngine, drawDesign, eyeFit, lashMap };
window.dispatchEvent(new Event('luxe-atelier-ready'));
