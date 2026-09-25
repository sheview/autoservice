import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * Translate a key from lang/{locale}/ui.php, e.g. t('users.title') or t('common.showing', { from: 1, to: 20, total: 45 }).
 * Returns the key itself when it is missing, so a missing text is visible instead of blank.
 */
export function t(key: string, replace: Record<string, string | number> = {}): string {
    const translations = usePage<SharedData>().props.translations ?? {};

    const value = key.split('.').reduce<unknown>((node, part) => {
        return node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined;
    }, translations);

    if (typeof value !== 'string') {
        return key;
    }

    return Object.entries(replace).reduce((text, [name, val]) => text.replaceAll(`:${name}`, String(val)), value);
}
