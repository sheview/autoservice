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
    // The sidebar section it belongs to (null = above the sections).
    group?: string | null;
}

export interface SharedData {
    name: string;
    quote?: { message: string; author: string };
    auth: Auth;
    tenant: { name: string; is_platform: boolean } | null;
    impersonation: { tenant: { name: string } } | null;
    /** Sidebar items built on the server from config/modules.php; icon is a lucide icon name. */
    navigation: { title: string; href: string; icon: string; group: string | null }[];
    flash: { success: string | null; error: string | null };
    /** The company's paid period; null for the platform tenant and customer accounts. */
    subscription: {
        state: 'unlimited' | 'not_started' | 'active' | 'expiring' | 'grace' | 'locked';
        starts_on: string | null;
        ends_on: string | null;
        days_left: number | null;
        read_only_until: string | null;
        read_only: boolean;
        locked: boolean;
    } | null;
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
