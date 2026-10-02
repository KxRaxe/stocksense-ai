import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import UserForm from '@/components/users/user-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { activate, deactivate, index, setupLink, update } from '@/routes/users';
import type { ManagedUser, RoleOption } from '@/types';

type Props = {
    user: ManagedUser;
    roles: RoleOption[];
    can: {
        changeRole: boolean;
        setActive: boolean;
        sendSetupLink: boolean;
    };
};

export default function EditUser({ user, roles, can }: Props) {
    const [confirmingDeactivate, setConfirmingDeactivate] = useState(false);
    const [busy, setBusy] = useState(false);

    // Errors from these buttons are not tied to a form field, so show them as a toast.
    const run = (method: 'post' | 'patch', url: string, done?: () => void) => {
        router[method](
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: done,
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? 'Something went wrong.',
                    ),
            },
        );
    };

    return (
        <>
            <Head title={`Edit ${user.name}`} />

            <div className="space-y-8 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title={user.name}
                        description="Update this person's details and role."
                    />
                    {!user.is_active && (
                        <Badge variant="destructive">Deactivated</Badge>
                    )}
                </div>

                <UserForm
                    action={update.form(user.id)}
                    roles={roles}
                    user={user}
                    roleLocked={!can.changeRole}
                    submitLabel="Save changes"
                />

                {(can.sendSetupLink || can.setActive) && (
                    <div className="max-w-xl space-y-4">
                        <Heading
                            variant="small"
                            title="Account"
                            description="Password and access."
                        />

                        <div className="flex flex-wrap gap-3">
                            {can.sendSetupLink && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        run('post', setupLink.url(user.id))
                                    }
                                    data-test="send-setup-link-button"
                                >
                                    Send set-up link
                                </Button>
                            )}

                            {can.setActive &&
                                (user.is_active ? (
                                    <Button
                                        variant="destructive"
                                        disabled={busy}
                                        onClick={() =>
                                            setConfirmingDeactivate(true)
                                        }
                                        data-test="deactivate-user-button"
                                    >
                                        Deactivate
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() =>
                                            run('patch', activate.url(user.id))
                                        }
                                        data-test="activate-user-button"
                                    >
                                        Activate
                                    </Button>
                                ))}
                        </div>

                        {can.sendSetupLink && (
                            <p className="text-sm text-muted-foreground">
                                The set-up link lets them choose a new password.
                                It expires after an hour; send another if
                                needed.
                            </p>
                        )}
                    </div>
                )}
            </div>

            <Dialog
                open={confirmingDeactivate}
                onOpenChange={setConfirmingDeactivate}
            >
                <DialogContent>
                    <DialogTitle>Deactivate {user.name}?</DialogTitle>
                    <DialogDescription>
                        They are signed out immediately and cannot sign in again
                        until you activate the account. Their history is kept.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                run('patch', deactivate.url(user.id), () =>
                                    setConfirmingDeactivate(false),
                                )
                            }
                            data-test="confirm-deactivate-button"
                        >
                            Deactivate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

EditUser.layout = {
    breadcrumbs: [
        { title: 'Users', href: index() },
        // The last crumb is shown as plain text, so its href is never used.
        { title: 'Edit user', href: index() },
    ],
};
