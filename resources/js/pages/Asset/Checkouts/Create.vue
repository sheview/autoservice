<script setup lang="ts">
import CheckoutRequestForm from '@/components/CheckoutRequestForm.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { PackageSearch, ShoppingCart } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Unit {
    ulid: string;
    asset_code: string;
    serial_number: string | null;
    property_no: string | null;
    location: string | null;
    branch: string | null;
    quantity: number;
    available: number;
    unit: string | null;
}

interface Group {
    key: string;
    name: string;
    brand: string | null;
    model: string | null;
    category: string | null;
    units: Unit[];
}

/**
 * Asking for something always starts with a search of the spare devices. Only when nothing fits
 * is a purchase request offered, starting from what was searched for.
 */
const props = defineProps<{
    search: string;
    groups: Group[];
    borrowers: { id: number; name: string }[];
    contracts: { id: number; label: string }[];
    canPurchase: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('checkouts.title'), href: route('asset.checkouts.index') },
    { title: t('checkouts.new_request'), href: route('asset.checkouts.create') },
];

const search = ref(props.search);
const picked = ref<{ unit: Unit; group: Group } | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    picked.value = null;
    timer = setTimeout(() => {
        router.get(route('asset.checkouts.create'), value.trim() ? { search: value.trim() } : {}, {
            only: ['groups', 'search'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

const searched = computed(() => props.search !== '');
const purchaseUrl = computed(() => route('inventory.purchase-requests.create', props.search ? { item: props.search } : {}));
const label = (group: Group) => [group.brand, group.model].filter(Boolean).join(' ');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('checkouts.new_request')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('checkouts.new_request')" :description="t('checkouts.find_hint')" />

            <div class="relative max-w-xl">
                <PackageSearch class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="search" type="search" class="h-11 pl-9 text-base" :placeholder="t('checkouts.find_placeholder')" autofocus />
            </div>

            <!-- Results, one card per model -->
            <div v-if="groups.length" class="grid gap-4 lg:grid-cols-2">
                <section v-for="group in groups" :key="group.key" class="space-y-2 rounded-md border p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <h3 class="font-semibold">{{ group.name }}</h3>
                            <p class="text-xs text-muted-foreground">{{ [label(group), group.category].filter(Boolean).join(' · ') }}</p>
                        </div>
                        <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800 dark:bg-green-950 dark:text-green-200">
                            {{ t('checkouts.available_count', { count: group.units.length }) }}
                        </span>
                    </div>
                    <ul class="divide-y rounded-md border text-sm">
                        <li v-for="unit in group.units" :key="unit.ulid" class="flex flex-wrap items-center gap-2 px-3 py-2">
                            <span class="font-mono text-xs">{{ unit.asset_code }}</span>
                            <span v-if="unit.serial_number" class="text-xs text-muted-foreground">S/N {{ unit.serial_number }}</span>
                            <span v-if="unit.quantity > 1" class="text-xs text-muted-foreground">
                                · {{ t('assets.available_of', { available: unit.available, total: unit.quantity }) }} {{ unit.unit ?? '' }}
                            </span>
                            <span v-if="unit.branch || unit.location" class="text-xs text-muted-foreground">
                                · {{ [unit.branch, unit.location].filter(Boolean).join(' · ') }}
                            </span>
                            <Button
                                size="sm"
                                class="ml-auto"
                                :variant="picked?.unit.ulid === unit.ulid ? 'default' : 'outline'"
                                @click="picked = { unit, group }"
                            >
                                {{ t('checkouts.pick') }}
                            </Button>
                        </li>
                    </ul>
                    <CheckoutRequestForm
                        v-if="picked && picked.group.key === group.key"
                        :key="picked.unit.ulid"
                        :asset-ulid="picked.unit.ulid"
                        :borrowers="borrowers"
                        :contracts="contracts"
                        :max-quantity="picked.unit.available"
                        :unit="picked.unit.unit"
                        :title="t('checkouts.request_for', { code: picked.unit.asset_code })"
                        from-search
                        @cancel="picked = null"
                    />
                </section>
            </div>

            <!-- Nothing spare matches: buy instead -->
            <div v-else-if="searched" class="max-w-xl space-y-3 rounded-md border border-dashed p-6 text-center">
                <p class="font-medium">{{ t('checkouts.not_found', { search }) }}</p>
                <p class="text-sm text-muted-foreground">{{ t('checkouts.not_found_hint') }}</p>
                <Button v-if="canPurchase" as-child>
                    <Link :href="purchaseUrl">
                        <ShoppingCart class="h-4 w-4" />
                        {{ t('checkouts.purchase_instead') }}
                    </Link>
                </Button>
            </div>

            <p v-else class="text-sm text-muted-foreground">{{ t('checkouts.find_start') }}</p>

            <p v-if="groups.length && canPurchase" class="text-sm text-muted-foreground">
                {{ t('checkouts.none_fits') }}
                <Link :href="purchaseUrl" class="text-primary underline-offset-4 hover:underline">{{ t('checkouts.purchase_instead') }}</Link>
            </p>
        </div>
    </AppLayout>
</template>
