import { ref, watch, type Ref } from 'vue';

/**
 * Records the one on a form would repeat, asked from the server while the user types (400 ms
 * after the last key): `routeName` answers JSON for the query from `params` (empty values left
 * out; nothing to check = no request). An answer older than the latest is dropped.
 */
export function useDuplicates<T>(routeName: string, params: () => Record<string, string | number | string[] | null | undefined>): Ref<T[]> {
    const matches = ref<T[]>([]) as Ref<T[]>;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let latest = 0;

    watch(
        params,
        (values) => {
            clearTimeout(timer);
            const query = Object.fromEntries(
                Object.entries(values).filter(([, value]) =>
                    Array.isArray(value) ? value.length > 0 : value !== null && value !== undefined && String(value).trim() !== '',
                ),
            );
            const checks = Object.keys(query).filter((key) => key !== 'except');
            if (!checks.length) {
                matches.value = [];
                return;
            }
            timer = setTimeout(async () => {
                const asked = ++latest;
                try {
                    const response = await fetch(route(routeName, query), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (asked === latest) matches.value = response.ok ? await response.json() : [];
                } catch {
                    // Offline: saving still checks on the server.
                }
            }, 400);
        },
        { deep: true, immediate: true },
    );

    return matches;
}
