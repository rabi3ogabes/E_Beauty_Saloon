/*
 * Branch maps (Leaflet + OpenStreetMap). Loaded only on pages that render [data-map],
 * and Leaflet itself is imported lazily when the map scrolls into view.
 */
const maps = document.querySelectorAll('[data-map]');

const init = async (el) => {
    const [{ default: L }] = await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]);
    const markers = JSON.parse(el.dataset.map || '[]');
    if (!markers.length) return;

    const map = L.map(el, { scrollWheelZoom: false, attributionControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    const brand = getComputedStyle(document.documentElement).getPropertyValue('--brand-primary').trim() || '#C8A96A';
    const icon = L.divIcon({
        className: '',
        html: `<span style="display:block;width:18px;height:18px;border-radius:999px;background:${brand};border:3px solid #fff;box-shadow:0 4px 14px rgba(0,0,0,.25)"></span>`,
        iconSize: [18, 18],
        iconAnchor: [9, 9],
    });

    const bounds = [];
    markers.forEach((m) => {
        const marker = L.marker([m.lat, m.lng], { icon, title: m.name }).addTo(map);
        const label = document.createElement('div');
        const strong = document.createElement('strong');
        strong.textContent = m.name;
        label.appendChild(strong);
        if (m.area) label.appendChild(document.createTextNode(` · ${m.area}`));
        if (m.url) {
            const a = document.createElement('a');
            a.href = m.url;
            a.textContent = ' →';
            label.appendChild(a);
        }
        marker.bindPopup(label);
        bounds.push([m.lat, m.lng]);
    });
    markers.length > 1 ? map.fitBounds(bounds, { padding: [40, 40] }) : map.setView(bounds[0], 15);
};

if (maps.length) {
    const io = 'IntersectionObserver' in window
        ? new IntersectionObserver((entries) => entries.forEach((e) => {
            if (e.isIntersecting) {
                io.unobserve(e.target);
                init(e.target);
            }
        }), { rootMargin: '300px' })
        : null;
    maps.forEach((el) => (io ? io.observe(el) : init(el)));
}
