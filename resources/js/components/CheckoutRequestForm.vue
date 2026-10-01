<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Asking to lend or issue one asset: to a user of the company or to someone from outside.
 * Used on the asset page and on the search page ("fromSearch": go on to the list afterwards).
 */
const props = defineProps<{
    assetUlid: string;
    borrowers: { id: number; name: string }[];
    title: string;
    fromSearch?: boolean;
    // How many can still be asked for; above 1 the form asks how many (an asset bought by the lot).
    maxQuantity?: number;
    unit?: string | null;
    // Projects (MA contracts) the form can be for; none = the field is hidden.
    contracts?: { id: number; label: string }[];
}>();

const emit = defineEmits<{ done: []; cancel: [] }>();

const borrowerKind = ref<'staff' | 'other'>('staff');
const form = useForm({
    type: 'loan' as 'issue' | 'loan',
    quantity: 1,
    contract_id: null as number | null,
    borrower_user_id: null as number | null,
    borrower_name: '',
    borrower_department: '',
    borrower_phone: '',
    purpose: '',
    due_on: '',
});

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            // One or the other: a user of the company, or a name typed in.
            borrower_user_id: borrowerKind.value === 'staff' ? data.borrower_user_id : null,
            borrower_name: borrowerKind.value === 'other' ? data.borrower_name : '',
            due_on: data.type === 'loan' ? data.due_on : null,
            from_search: props.fromSearch ?? false,
        }))
        .post(route('asset.checkouts.store', props.assetUlid), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                emit('done');
            },
        });

const today = new Date().toLocaleDateString('sv-SE');
const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <form class="space-y-4 rounded-md border p-4" @submit.prevent="submit">
        <h4 class="text-sm font-semibold">{{ title }}</h4>

        <fieldset class="grid gap-2 sm:grid-cols-2">
            <legend class="sr-only">{{ t('checkouts.type') }}</legend>
            <label
                v-for="type in ['loan', 'issue'] as const"
                :key="type"
                class="flex cursor-pointer gap-2 rounded-md border p-3 text-sm"
                :class="{ 'border-primary ring-1 ring-primary': form.type === type }"
            >
                <input v-model="form.type" type="radio" :name="`type-${assetUlid}`" :value="type" class="mt-1" />
                <span>
                    <span class="font-medium">{{ t(`checkouts.types.${type}`) }}</span>
                    <span class="block text-xs text-muted-foreground">{{ t(`checkouts.type_hints.${type}`) }}</span>
                </span>
            </label>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <div v-if="(maxQuantity ?? 1) > 1" class="grid content-start gap-2 sm:col-span-2">
                <Label :for="`quantity-${assetUlid}`">{{ t('checkouts.quantity') }}</Label>
                <div class="flex items-center gap-2">
                    <Input
                        :id="`quantity-${assetUlid}`"
                        v-model.number="form.quantity"
                        type="number"
                        min="1"
                        :max="maxQuantity"
                        required
                        class="w-32"
                    />
                    <span class="text-sm text-muted-foreground">{{ unit }} · {{ t('checkouts.max_quantity', { max: maxQuantity ?? 1 }) }}</span>
                </div>
                <InputError :message="form.errors.quantity" />
            </div>
            <div v-if="contracts?.length" class="grid content-start gap-2 sm:col-span-2">
                <Label :for="`contract_id-${assetUlid}`">{{ t('checkouts.contract') }}</Label>
                <select :id="`contract_id-${assetUlid}`" v-model="form.contract_id" :class="selectClass">
                    <option :value="null">{{ t('checkouts.no_contract') }}</option>
                    <option v-for="contract in contracts" :key="contract.id" :value="contract.id">{{ contract.label }}</option>
                </select>
                <InputError :message="form.errors.contract_id" />
            </div>
            <div class="grid content-start gap-2 sm:col-span-2">
                <Label>{{ t('checkouts.borrower') }}</Label>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2">
                        <input v-model="borrowerKind" type="radio" value="staff" />
                        {{ t('checkouts.borrower_staff') }}
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="borrowerKind" type="radio" value="other" />
                        {{ t('checkouts.borrower_other') }}
                    </label>
                </div>
            </div>
            <div v-if="borrowerKind === 'staff'" class="grid content-start gap-2">
                <Label :for="`borrower_user_id-${assetUlid}`">{{ t('checkouts.borrower_name') }}</Label>
                <select :id="`borrower_user_id-${assetUlid}`" v-model="form.borrower_user_id" required :class="selectClass">
                    <option :value="null" disabled>{{ t('checkouts.choose_staff') }}</option>
                    <option v-for="user in borrowers" :key="user.id" :value="user.id">{{ user.name }}</option>
                </select>
                <InputError :message="form.errors.borrower_user_id" />
            </div>
            <div v-else class="grid content-start gap-2">
                <Label :for="`borrower_name-${assetUlid}`">{{ t('checkouts.borrower_name') }}</Label>
                <Input :id="`borrower_name-${assetUlid}`" v-model="form.borrower_name" required />
                <InputError :message="form.errors.borrower_name" />
            </div>
            <div class="grid content-start gap-2">
                <Label :for="`borrower_department-${assetUlid}`">{{ t('checkouts.borrower_department') }}</Label>
                <Input :id="`borrower_department-${assetUlid}`" v-model="form.borrower_department" />
                <InputError :message="form.errors.borrower_department" />
            </div>
            <div class="grid content-start gap-2">
                <Label :for="`borrower_phone-${assetUlid}`">{{ t('checkouts.borrower_phone') }}</Label>
                <Input :id="`borrower_phone-${assetUlid}`" v-model="form.borrower_phone" type="tel" />
                <InputError :message="form.errors.borrower_phone" />
            </div>
            <div v-if="form.type === 'loan'" class="grid content-start gap-2">
                <Label :for="`due_on-${assetUlid}`">{{ t('checkouts.due_on') }}</Label>
                <Input :id="`due_on-${assetUlid}`" v-model="form.due_on" type="date" :min="today" required />
                <InputError :message="form.errors.due_on" />
            </div>
            <div class="grid content-start gap-2 sm:col-span-2">
                <Label :for="`purpose-${assetUlid}`">{{ t('checkouts.purpose') }}</Label>
                <textarea
                    :id="`purpose-${assetUlid}`"
                    v-model="form.purpose"
                    rows="2"
                    class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                />
                <InputError :message="form.errors.purpose" />
            </div>
        </div>
        <InputError :message="form.errors.type" />
        <div class="flex gap-2">
            <Button :disabled="form.processing">{{ t('checkouts.submit') }}</Button>
            <Button type="button" variant="outline" @click="emit('cancel')">{{ t('common.cancel') }}</Button>
        </div>
    </form>
</template>
