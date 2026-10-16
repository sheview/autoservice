import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * The one calendar ("งานของฉัน") on a given view, for who has it in their menu; otherwise the
 * module's own calendar page (customer accounts, or a company without the service module).
 */
export function useCalendarLink(view: 'pm' | 'room', fallback: string): string {
    const page = usePage<SharedData>();
    const myWork = route('service.my-work', undefined, false);

    return page.props.navigation.some((item) => item.href === myWork) ? route('service.my-work', { view }) : fallback;
}
