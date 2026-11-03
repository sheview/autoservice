/**
 * Reads a place typed or pasted by the user: "13.7563, 100.5018", or a Google Maps link
 * (…/@13.75,100.50,17z, ?q=13.75,100.50, ?ll=…, …!3d13.75!4d100.50). Short share links
 * (maps.app.goo.gl) carry no coordinates and cannot be read here.
 */
export function parseCoordinates(text: string): { lat: string; lng: string } | null {
    const value = text.trim();
    if (value === '') return null;

    const number = '(-?\\d{1,3}(?:\\.\\d+)?)';
    const patterns = [
        new RegExp(`!3d${number}!4d${number}`), // the place itself, before the map's centre
        new RegExp(`@${number},${number}`),
        new RegExp(`[?&](?:q|query|ll|destination|center)=${number}(?:,|%2C)\\s*${number}`, 'i'),
        new RegExp(`^${number}\\s*[,\\s]\\s*${number}$`),
    ];

    for (const pattern of patterns) {
        const match = value.match(pattern);
        if (!match) continue;
        const lat = Number(match[1]);
        const lng = Number(match[2]);
        if (Math.abs(lat) <= 90 && Math.abs(lng) <= 180) {
            return { lat: lat.toFixed(7), lng: lng.toFixed(7) };
        }
    }

    return null;
}

/** A Google Maps link that opens the place (or directions from the phone's location). */
export function mapsUrl(lat: string | number, lng: string | number): string {
    return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
}
