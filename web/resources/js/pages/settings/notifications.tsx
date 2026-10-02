import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import NotificationSettingsForm from '@/components/notifications/notification-settings-form';
import { edit } from '@/routes/notification-preferences';
import type { FrequencyOption, NotificationSetting } from '@/types';

type Props = {
    types: NotificationSetting[];
    frequencies: FrequencyOption[];
};

export default function Notifications({ types, frequencies }: Props) {
    return (
        <>
            <Head title="Notification settings" />

            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Choose which alerts and summaries you get, and whether they also come by email"
                />

                {types.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        There are no notifications for your role.
                    </p>
                ) : (
                    <NotificationSettingsForm
                        types={types}
                        frequencies={frequencies}
                    />
                )}
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [
        {
            title: 'Notification settings',
            href: edit(),
        },
    ],
};
