/**
 * Date/time for the internal UI: Gregorian year (PDF documents use the Buddhist year), Bangkok time.
 */
export function dateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Bangkok' }) : '—';
}

/**
 * Date/time for printed documents: Buddhist year (the th-TH calendar), Bangkok time.
 */
export function documentDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Bangkok' }) : '—';
}
