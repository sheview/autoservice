import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * Whether the current user has a permission. For showing/hiding UI only — the server checks again.
 */
export function useCan() {
    const page = usePage<SharedData>();

    return (permission: string): boolean => page.props.auth.permissions.includes(permission);
}
