import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    permissions: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Show the item only when the user has this permission. */
    permission?: string;
}

export interface SharedData {
    name: string;
    quote?: { message: string; author: string };
    auth: Auth;
    tenant: { name: string; is_platform: boolean } | null;
    impersonation: { tenant: { name: string } } | null;
    flash: { success: string | null };
    locale: string;
    translations: Record<string, unknown>;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export type BreadcrumbItemType = BreadcrumbItem;
