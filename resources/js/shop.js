/*
 * Shop maps (checkout pin, driver view) — Leaflet + OpenStreetMap tiles, no API key.
 * Loaded only on pages that need a map; Alpine components wait for the "luxe:leaflet" event.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const pin = L.divIcon({
    className: 'luxe-pin',
    html: '<span style="display:block;width:28px;height:28px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:#C8A96A;border:3px solid #1C1A17;box-shadow:0 6px 16px rgba(28,26,23,.25)"></span>',
    iconSize: [28, 28],
    iconAnchor: [14, 28],
});

/**
 * Mount a map with a draggable pin. Returns { map, marker, setView(lat, lng) }.
 */
window.luxeMap = (el, { lat = 25.2854, lng = 51.531, zoom = 12, draggable = true, onMove = null } = {}) => {
    const map = L.map(el, { zoomControl: true, attributionControl: true }).setView([lat, lng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    const marker = L.marker([lat, lng], { draggable, icon: pin }).addTo(map);
    const emit = () => onMove && onMove(marker.getLatLng());
    marker.on('dragend', emit);
    map.on('click', (e) => { marker.setLatLng(e.latlng); emit(); });
    setTimeout(() => map.invalidateSize(), 150);

    return {
        map,
        marker,
        setView(la, ln, z = 16) { marker.setLatLng([la, ln]); map.setView([la, ln], z); emit(); },
    };
};

window.L = L;
window.luxeLeafletReady = true;
window.dispatchEvent(new CustomEvent('luxe:leaflet'));
