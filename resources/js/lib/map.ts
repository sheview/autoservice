import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

/** Thailand as a whole, before anything is placed. */
export const THAILAND_CENTER: L.LatLngTuple = [13.0, 101.0];
export const THAILAND_ZOOM = 6;

/**
 * A Leaflet map on OpenStreetMap tiles (free, no key; the attribution must stay visible).
 */
export function createMap(element: HTMLElement, options: L.MapOptions = {}): L.Map {
    const map = L.map(element, { center: THAILAND_CENTER, zoom: THAILAND_ZOOM, ...options });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    }).addTo(map);

    return map;
}

/** A round pin of one colour (no image files to bundle). */
export function dotIcon(color: string, size = 18): L.DivIcon {
    return L.divIcon({
        className: '',
        html: `<span style="display:block;width:${size}px;height:${size}px;border-radius:9999px;background:${color};border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.45)"></span>`,
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
        popupAnchor: [0, -size / 2],
    });
}

/** A square pin with a building, for the company's own branches. */
export function branchIcon(color: string): L.DivIcon {
    return L.divIcon({
        className: '',
        html: `<span style="display:flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:6px;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.45);color:#fff"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/></svg></span>`,
        iconSize: [26, 26],
        iconAnchor: [13, 13],
        popupAnchor: [0, -13],
    });
}

/** Text put into popup HTML, escaped. */
export function escapeHtml(text: string | null | undefined): string {
    return (text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string);
}
