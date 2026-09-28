import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

/**
 * Reactive filters for a server-side list page. Any change reloads the page with the filters in the query string
 * (search is debounced); pagination resets to page 1.
 */
export function useListFilters<T extends Record<string, unknown>>(routeName: string, initial: T) {
    const filters = reactive({ ...initial }) as T;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const reload = () => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== null && value !== '')) as Parameters<
            typeof router.get
        >[1];
        router.get(route(routeName), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    watch(
        () => ({ ...filters }),
        (now, before) => {
            clearTimeout(timer);
            timer = now.search !== before.search ? setTimeout(reload, 300) : undefined;
            if (!timer) reload();
        },
    );

    return filters;
}
