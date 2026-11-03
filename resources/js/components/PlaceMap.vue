<script setup lang="ts">
import { directionsUrl } from '@/lib/coordinates';
import { t } from '@/lib/i18n';
import { branchIcon, createMap, dotIcon, escapeHtml } from '@/lib/map';
import { router } from '@inertiajs/vue3';
import L from 'leaflet';
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

/**
 * The map tab of the home page: the company's branches and the customers' sites placed on it,
 * each site coloured by its customer's open tickets. Filters reload only the map's data.
 */

export interface SitePlace {
    id: number;
    name: string;
    address: string | null;
    province: string | null;
    lat: number;
    lng: number;
    customer_id: number;
    customer: string;
    tickets: { open: number; overdue: number };
}

export interface BranchPlace {
    id: number;
    code: string;
    name: string;
    address: string | null;
    province: string | null;
    lat: number;
    lng: number;
}

const props = defineProps<{
    filters: { customer_id: number | null; province: string | null };
    sites: SitePlace[];
    unplaced: number;
    branches: BranchPlace[];
    customers: { id: number; name: string }[];
    provinces: string[];
    showTickets: boolean;
}>();

const filters = reactive({ ...props.filters });
watch(
    () => ({ ...filters }),
    (now) => {
        const query = Object.fromEntries(Object.entries(now).filter(([, value]) => value !== null && value !== ''));
        router.get(route('dashboard'), { tab: 'map', ...query }, { only: ['map'], preserveState: true, preserveScroll: true, replace: true });
    },
);

const COLORS = { branch: '#2563eb', overdue: '#dc2626', open: '#f59e0b', ok: '#16a34a', site: '#7c3aed' };

const siteColor = (site: SitePlace) => {
    if (!props.showTickets) return COLORS.site;
    if (site.tickets.overdue > 0) return COLORS.overdue;
    return site.tickets.open > 0 ? COLORS.open : COLORS.ok;
};

const link = (href: string, text: string, external = false) =>
    `<a href="${escapeHtml(href)}" ${external ? 'target="_blank" rel="noopener"' : ''} style="color:#6d28d9;text-decoration:underline">${escapeHtml(text)}</a>`;

const sitePopup = (site: SitePlace) => {
    const place = [site.address, site.province].filter(Boolean).join(' · ');
    let tickets = '';
    if (props.showTickets) {
        tickets =
            site.tickets.open === 0
                ? t('map.no_open_tickets')
                : t('map.open_tickets', { open: site.tickets.open }) +
                  (site.tickets.overdue
                      ? ` · <b style="color:${COLORS.overdue}">${t('map.overdue_tickets', { overdue: site.tickets.overdue })}</b>`
                      : '');
    }

    return [
        `<b>${escapeHtml(site.name)}</b>`,
        escapeHtml(site.customer),
        place && `<span style="color:#6b7280">${escapeHtml(place)}</span>`,
        tickets,
        `${link(route('contract.customers.edit', site.customer_id), t('map.open_customer'))} · ${link(directionsUrl(site.lat, site.lng), t('map.directions'), true)}`,
    ]
        .filter(Boolean)
        .join('<br>');
};

const branchPopup = (branch: BranchPlace) =>
    [
        `<b>${escapeHtml(branch.name)}</b> <span style="color:#6b7280">(${t('map.branch')} ${escapeHtml(branch.code)})</span>`,
        branch.address || branch.province
            ? `<span style="color:#6b7280">${escapeHtml([branch.address, branch.province].filter(Boolean).join(' · '))}</span>`
            : '',
        link(directionsUrl(branch.lat, branch.lng), t('map.directions'), true),
    ]
        .filter(Boolean)
        .join('<br>');

const mapElement = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let layer: L.LayerGroup | null = null;
const markers = new Map<string, L.Marker>();

// Put every pin on the map and fit the view around them.
const draw = () => {
    if (!map || !layer) return;
    layer.clearLayers();
    markers.clear();

    for (const branch of props.branches) {
        const marker = L.marker([branch.lat, branch.lng], { icon: branchIcon(COLORS.branch), zIndexOffset: 1000 }).bindPopup(branchPopup(branch));
        marker.addTo(layer);
        markers.set(`branch-${branch.id}`, marker);
    }
    for (const site of props.sites) {
        const marker = L.marker([site.lat, site.lng], { icon: dotIcon(siteColor(site)) }).bindPopup(sitePopup(site));
        marker.addTo(layer);
        markers.set(`site-${site.id}`, marker);
    }

    const points = [...props.branches, ...props.sites].map((place) => [place.lat, place.lng] as L.LatLngTuple);
    if (points.length === 1) map.setView(points[0], 14);
    else if (points.length > 1) map.fitBounds(points, { padding: [40, 40], maxZoom: 14 });
};

const focus = (key: string) => {
    const marker = markers.get(key);
    if (!map || !marker) return;
    map.setView(marker.getLatLng(), Math.max(map.getZoom(), 14));
    marker.openPopup();
};

onMounted(() => {
    if (!mapElement.value) return;
    map = createMap(mapElement.value);
    layer = L.layerGroup().addTo(map);
    draw();
});

onBeforeUnmount(() => map?.remove());

watch(() => [props.sites, props.branches], draw);

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <select v-model="filters.customer_id" :class="selectClass" :aria-label="t('map.all_customers')">
                <option :value="null">{{ t('map.all_customers') }}</option>
                <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
            </select>
            <select v-if="provinces.length" v-model="filters.province" :class="selectClass" :aria-label="t('map.all_provinces')">
                <option :value="null">{{ t('map.all_provinces') }}</option>
                <option v-for="province in provinces" :key="province" :value="province">{{ province }}</option>
            </select>

            <!-- Legend -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground sm:ml-auto">
                <span class="flex items-center gap-1.5"
                    ><span class="h-3 w-3 rounded-sm" :style="{ background: COLORS.branch }" />{{ t('map.legend_branch') }}</span
                >
                <template v-if="showTickets">
                    <span class="flex items-center gap-1.5"
                        ><span class="h-3 w-3 rounded-full" :style="{ background: COLORS.overdue }" />{{ t('map.legend_overdue') }}</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><span class="h-3 w-3 rounded-full" :style="{ background: COLORS.open }" />{{ t('map.legend_open') }}</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><span class="h-3 w-3 rounded-full" :style="{ background: COLORS.ok }" />{{ t('map.legend_ok') }}</span
                    >
                </template>
                <span v-else class="flex items-center gap-1.5"
                    ><span class="h-3 w-3 rounded-full" :style="{ background: COLORS.site }" />{{ t('map.legend_site') }}</span
                >
            </div>
        </div>

        <p
            v-if="unplaced"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            {{ t('map.unplaced', { count: unplaced }) }}
        </p>

        <div class="grid gap-4 lg:grid-cols-[1fr_18rem]">
            <div class="relative">
                <!-- isolate: Leaflet's panes use high z-indexes; keep them under the app's menus and dialogs -->
                <div ref="mapElement" class="isolate h-[70vh] min-h-[400px] w-full rounded-md border" />
                <p
                    v-if="!sites.length && !branches.length"
                    class="pointer-events-none absolute inset-x-0 top-4 z-[1000] mx-auto w-fit rounded-md bg-background/90 px-4 py-2 text-sm text-muted-foreground shadow"
                >
                    {{ t('map.no_places') }}
                </p>
            </div>

            <!-- The places on the map; a click goes to the pin -->
            <div class="max-h-[70vh] overflow-y-auto rounded-md border text-sm">
                <p class="sticky top-0 border-b bg-muted/50 px-3 py-2 font-medium">{{ t('map.sites_count', { count: sites.length }) }}</p>
                <button
                    v-for="branch in branches"
                    :key="`branch-${branch.id}`"
                    type="button"
                    class="flex w-full items-start gap-2 border-b px-3 py-2 text-left hover:bg-muted/50"
                    @click="focus(`branch-${branch.id}`)"
                >
                    <span class="mt-1 h-3 w-3 shrink-0 rounded-sm" :style="{ background: COLORS.branch }" />
                    <span>
                        {{ branch.name }}
                        <span class="block text-xs text-muted-foreground">{{ t('map.branch') }} {{ branch.code }}</span>
                    </span>
                </button>
                <button
                    v-for="site in sites"
                    :key="`site-${site.id}`"
                    type="button"
                    class="flex w-full items-start gap-2 border-b px-3 py-2 text-left hover:bg-muted/50"
                    @click="focus(`site-${site.id}`)"
                >
                    <span class="mt-1 h-3 w-3 shrink-0 rounded-full" :style="{ background: siteColor(site) }" />
                    <span>
                        {{ site.name }}
                        <span class="block text-xs text-muted-foreground">{{ [site.customer, site.province].filter(Boolean).join(' · ') }}</span>
                        <span v-if="showTickets && site.tickets.open" class="block text-xs">
                            {{ t('map.open_tickets', { open: site.tickets.open }) }}
                            <template v-if="site.tickets.overdue">
                                ·
                                <span class="text-red-700 dark:text-red-400">{{ t('map.overdue_tickets', { overdue: site.tickets.overdue }) }}</span>
                            </template>
                        </span>
                    </span>
                </button>
            </div>
        </div>

        <p v-if="showTickets" class="text-xs text-muted-foreground">{{ t('map.tickets_note') }}</p>
    </div>
</template>
