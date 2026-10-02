import type { Option, Paginated } from '@/types/catalog';

/** Mirrors App\Services\Settings\SettingDefinition. */
export type SettingType = 'integer' | 'number' | 'boolean' | 'time' | 'choice';

export type SettingValue = string | number | boolean;

/** One shop-wide setting, as the settings page receives it. */
export type SettingField = {
    key: string;
    label: string;
    help: string;
    type: SettingType;
    unit: string | null;
    min: number | null;
    max: number | null;
    /** For a choice only. */
    choices: { value: number | string; label: string }[];
    value: SettingValue;
    default: SettingValue;
    is_default: boolean;
};

export type SettingGroup = { name: string; settings: SettingField[] };

export type SystemSettingsProps = {
    groups: SettingGroup[];
    has_changes: boolean;
    time_zone: string;
};

/** One line of the audit log. */
export type AuditEntry = {
    id: number;
    area: string;
    area_label: string;
    description: string;
    who: string | null;
    subject: string | null;
    details: { label: string; from: string | null; to: string | null }[];
    created_at: string;
};

export type AuditFilters = {
    area: string;
    user: number | null;
    from: string;
    to: string;
    search: string;
};

export type AuditLogProps = {
    entries: Paginated<AuditEntry>;
    filters: AuditFilters;
    areas: { value: string; label: string }[];
    users: Option[];
};
