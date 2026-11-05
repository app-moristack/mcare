import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    workspace: {
        organisation_id: number | null;
        organisations: { id: number; name: string }[];
        permissions: string[];
        mfa_enrolled: boolean;
        notifications: { id: number; message: string; url: string }[];
    } | null;
    flash: { success?: string };
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    account_kind: 'admin' | 'self_patient' | 'records_only' | 'proxy' | 'provider_application' | 'partner_application';
    preferred_language: string;
    identity_status: string;
    last_login_at: string | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
