import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * A request the server refused as too large (413, over post_max_size: bootstrap/app.php) arrives
 * with no form data at all. Instead of Inertia's error window, its Thai message is kept here and
 * shown by UploadNotice; the form on the page keeps what was typed.
 */
export const uploadNotice = ref<string | null>(null);

export const installUploadNotice = (): void => {
    router.on('invalid', (event) => {
        const response = event.detail.response;
        if (response.status !== 413) return;
        event.preventDefault();
        const data = response.data as unknown;
        uploadNotice.value = typeof data === 'string' ? data : ((data as { message?: string } | null)?.message ?? null);
    });
};
