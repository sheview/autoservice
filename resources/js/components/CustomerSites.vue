<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface Site {
    id: number;
    name: string;
    address: string | null;
}

/**
 * The sites of a customer (head office, branches, data center), where its networks are.
 * Each saved on its own, outside the customer form.
 */
const props = defineProps<{ customerId: number; sites: Site[] }>();

const editing = ref<Site | 'new' | null>(null);
const form = useForm({ name: '', address: '' });

const open = (site: Site | 'new') => {
    editing.value = site;
    form.defaults(site === 'new' ? { name: '', address: '' } : { name: site.name, address: site.address ?? '' });
    form.reset();
    form.clearErrors();
};

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') form.post(route('contract.customers.sites.store', props.customerId), options);
    else if (editing.value) form.put(route('contract.customers.sites.update', [props.customerId, editing.value.id]), options);
};

const destroy = (site: Site) =>
    confirm(t('common.confirm_delete', { name: site.name })) &&
    router.delete(route('contract.customers.sites.destroy', [props.customerId, site.id]), { preserveScroll: true });
</script>

<template>
    <section class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold">{{ t('customers.sites') }}</h3>
                <p class="text-sm text-muted-foreground">{{ t('customers.sites_hint') }}</p>
            </div>
            <Button v-if="!editing" type="button" variant="outline" size="sm" @click="open('new')">
                <Plus class="h-4 w-4" />
                {{ t('customers.add_site') }}
            </Button>
        </div>

        <ul v-if="sites.length" class="divide-y rounded-md border text-sm">
            <li v-for="site in sites" :key="site.id" class="flex items-start justify-between gap-3 px-3 py-2">
                <span>
                    {{ site.name }}
                    <span v-if="site.address" class="block text-xs text-muted-foreground">{{ site.address }}</span>
                </span>
                <span class="flex gap-1">
                    <Button type="button" size="sm" variant="ghost" :aria-label="t('common.edit')" @click="open(site)"
                        ><Pencil class="h-3.5 w-3.5"
                    /></Button>
                    <Button type="button" size="sm" variant="ghost" class="text-red-600" :aria-label="t('common.delete')" @click="destroy(site)">
                        <Trash2 class="h-3.5 w-3.5" />
                    </Button>
                </span>
            </li>
        </ul>
        <p v-else-if="!editing" class="text-sm text-muted-foreground">{{ t('customers.no_sites') }}</p>

        <form v-if="editing" class="grid gap-3 rounded-md border p-3 sm:grid-cols-2" @submit.prevent="save">
            <div class="grid gap-1.5">
                <Input v-model="form.name" :placeholder="t('customers.site_name')" required />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-1.5">
                <Input v-model="form.address" :placeholder="t('customers.site_address')" />
                <InputError :message="form.errors.address" />
            </div>
            <div class="flex gap-2 sm:col-span-2">
                <Button size="sm" :disabled="form.processing">{{ t('common.save') }}</Button>
                <Button type="button" size="sm" variant="ghost" @click="editing = null">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </section>
</template>
