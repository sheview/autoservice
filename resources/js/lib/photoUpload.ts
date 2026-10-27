import { t } from '@/lib/i18n';
import { ref } from 'vue';

/**
 * Sends one photo to a photo slot (PhotoSlots: POST to its address) with fetch, asking for JSON,
 * so a refused file (422), a body too large (413) or a dropped connection comes back here as a
 * message instead of a page change. Throws an Error whose message is shown (Thai).
 */
export const uploadPhoto = async (action: string, file: File): Promise<void> => {
    const body = new FormData();
    body.append('photo', file);
    const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];

    let response: Response;
    try {
        response = await fetch(action, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
            },
        });
    } catch {
        throw new Error(t('photos.failed'));
    }
    if (response.ok) return;

    const data = await response.json().catch(() => null);
    throw new Error(data?.errors?.photo?.[0] ?? data?.message ?? t('photos.failed'));
};

/**
 * Photos picked on a create form, waiting for the record to exist: the form leaves them here
 * under the new record's key ("part:12") and its page (PhotoSlots pending-key) uploads them.
 * Kept in memory only, so a failed upload can be retried without picking the files again.
 */
export const pendingPhotos = ref<{ key: string; files: File[] } | null>(null);
