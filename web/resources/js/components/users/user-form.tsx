import { Form } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/users';
import type { ManagedUser, RoleOption } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    /** Wayfinder form definition, e.g. `store.form()` or `update.form(user.id)`. */
    action: RouteFormDefinition<'post'>;
    roles: RoleOption[];
    user?: ManagedUser;
    /** The role cannot be changed (an Owner editing their own account). */
    roleLocked?: boolean;
    submitLabel: string;
};

export default function UserForm({
    action,
    roles,
    user,
    roleLocked = false,
    submitLabel,
}: Props) {
    return (
        <Form {...action} className="max-w-xl space-y-6">
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={user?.name}
                            required
                            autoComplete="off"
                            placeholder="Full name"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            defaultValue={user?.email}
                            required
                            autoComplete="off"
                            placeholder="name@example.com"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="role">Role</Label>
                        {/* A disabled field is not submitted, so a locked role is sent as a hidden value. */}
                        {roleLocked && user?.role && (
                            <input
                                type="hidden"
                                name="role"
                                value={user.role}
                            />
                        )}
                        <NativeSelect
                            id="role"
                            name={roleLocked ? undefined : 'role'}
                            defaultValue={user?.role ?? 'inventory_staff'}
                            disabled={roleLocked}
                            required
                        >
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </NativeSelect>
                        {roleLocked && (
                            <p className="text-sm text-muted-foreground">
                                You cannot change your own role.
                            </p>
                        )}
                        <InputError message={errors.role} />
                    </div>

                    <div className="flex items-center gap-3">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="save-user-button"
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index()}>Cancel</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
