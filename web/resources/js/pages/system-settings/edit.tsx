import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import SettingsForm from '@/components/system-settings/settings-form';
import { edit } from '@/routes/system-settings';
import type { SystemSettingsProps } from '@/types';

export default function SystemSettings(props: SystemSettingsProps) {
    return (
        <>
            <Head title="System settings" />

            <div className="max-w-3xl space-y-6 p-4">
                <Heading
                    title="System settings"
                    description="How stock advice is worked out and when things run for the whole shop. Only the Owner can change these. Your own notification and account settings are under your name."
                />
                <SettingsForm
                    key={JSON.stringify(props.groups)}
                    groups={props.groups}
                    has_changes={props.has_changes}
                    time_zone={props.time_zone}
                />
            </div>
        </>
    );
}

SystemSettings.layout = {
    breadcrumbs: [{ title: 'System settings', href: edit() }],
};
