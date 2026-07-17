import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ManagedUser } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

/**
 * Administratively reset an employee's password. The target is signed out of any
 * active session on success, so they must sign back in with the new password.
 */
export default function ResetPasswordDialog({
    open,
    onOpenChange,
    user,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: ManagedUser | null;
}) {
    const form = useForm<{ password: string; password_confirmation: string }>({ password: '', password_confirmation: '' });
    const { data, setData, errors, processing, reset, clearErrors } = form;

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, user?.id]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!user) {
            return;
        }
        form.patch(route('users.reset-password', user.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Reset password{user ? ` — ${user.name ?? user.username}` : ''}</DialogTitle>
                        <DialogDescription>The user will be signed out and must log in again with the new password.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="reset-password">New password</Label>
                        <Input
                            id="reset-password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
                            autoFocus
                            autoComplete="new-password"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="reset-password-confirm">Confirm new password</Label>
                        <Input
                            id="reset-password-confirm"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            required
                            autoComplete="new-password"
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Reset password
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
