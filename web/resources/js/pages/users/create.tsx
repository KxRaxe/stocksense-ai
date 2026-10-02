import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import UserForm from '@/components/users/user-form';
import { create, index, store } from '@/routes/users';
import type { RoleOption } from '@/types';

export default function CreateUser({ roles }: { roles: RoleOption[] }) {
    return (
        <>
            <Head title="New user" />

            <div className="space-y-6 p-4">
                <Heading
                    title="New user"
                    description="We email them a link to choose their own password. The email does not contain any personal details."
                />

                <UserForm
                    action={store.form()}
                    roles={roles}
                    submitLabel="Create user"
                />
            </div>
        </>
    );
}

CreateUser.layout = {
    breadcrumbs: [
        { title: 'Users', href: index() },
        { title: 'New user', href: create() },
    ],
};
