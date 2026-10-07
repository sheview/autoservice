<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';
import type { SharedData, User } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, Check, LogOut, Settings } from 'lucide-vue-next';

interface Props {
    user: User;
}

defineProps<Props>();

// Other companies this person works in (their own account there).
const page = usePage<SharedData>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="route('profile.edit')" as="button">
                <Settings class="mr-2 h-4 w-4" />
                {{ t('settings.menu') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <template v-if="page.props.companies?.length">
        <DropdownMenuSeparator />
        <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">{{ t('settings.switch_company') }}</DropdownMenuLabel>
        <DropdownMenuGroup>
            <DropdownMenuItem v-for="company in page.props.companies" :key="company.ulid" :as-child="true" :disabled="company.current">
                <Link v-if="!company.current" class="block w-full" method="post" :href="route('platform.switch-company', company.ulid)" as="button">
                    <Building2 class="mr-2 h-4 w-4" />
                    {{ company.name }}
                </Link>
                <span v-else class="flex w-full items-center font-medium">
                    <Check class="mr-2 h-4 w-4" />
                    {{ company.name }}
                </span>
            </DropdownMenuItem>
        </DropdownMenuGroup>
    </template>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link class="block w-full" method="post" :href="route('logout')" as="button">
            <LogOut class="mr-2 h-4 w-4" />
            {{ t('settings.logout') }}
        </Link>
    </DropdownMenuItem>
</template>
