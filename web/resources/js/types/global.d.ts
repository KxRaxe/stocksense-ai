import type { Auth } from '@/types/auth';
import type { NotificationsShared } from '@/types/replenishment';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            /**
             * Behind the bell; null when nobody is signed in. Not called
             * `notifications`, which is the notifications page's own prop and
             * would replace it.
             */
            bell: NotificationsShared | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
