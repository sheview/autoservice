/**
 * Date/time for the internal UI: Gregorian year (PDF documents use the Buddhist year), Bangkok time.
 */
export function dateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Bangkok' }) : '—';
}

/**
 * A file size for people: 820 KB, 1.4 MB.
 */
export function formatBytes(bytes: number): string {
    return bytes < 1024 * 1024 ? `${Math.max(1, Math.ceil(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Date/time for printed documents: Buddhist year (the th-TH calendar), Bangkok time.
 */
export function documentDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Bangkok' }) : '—';
}
