import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { UserRole } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

const ROLE_LABEL: Record<UserRole, string> = {
    owner: 'Owner',
    admin: 'Admin',
    cashier: 'Cashier',
};

/**
 * Create a new employee. Name is immutable after creation, so it is only set
 * here. The role select offers only the roles the current actor may assign
 * (resolved server-side), and the password is confirmed inline.
 */
export default function UserDialog({
    open,
    onOpenChange,
    assignableRoles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignableRoles: UserRole[];
}) {
    const form = useForm<{ name: string; username: string; role: string; password: string; password_confirmation: string }>({
        name: '',
        username: '',
        role: assignableRoles[0] ?? '',
        password: '',
        password_confirmation: '',
    });
    const { data, setData, errors, processing, reset, clearErrors } = form;

    // Reset fields/errors each time the dialog opens so nothing leaks between uses.
    useEffect(() => {
        if (open) {
            reset();
            setData('role', assignableRoles[0] ?? '');
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('users.store'), {
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
                        <DialogTitle>New user</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="user-name">Name</Label>
                        <Input
                            id="user-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            autoFocus
                            placeholder="e.g. Jane Dela Cruz"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="user-username">Username</Label>
                        <Input
                            id="user-username"
                            value={data.username}
                            onChange={(e) => setData('username', e.target.value)}
                            required
                            autoComplete="off"
                            placeholder="Used to sign in"
                        />
                        <InputError message={errors.username} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="user-role">Role</Label>
                        <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                            <SelectTrigger id="user-role">
                                <SelectValue placeholder="Select a role" />
                            </SelectTrigger>
                            <SelectContent>
                                {assignableRoles.map((role) => (
                                    <SelectItem key={role} value={role}>
                                        {ROLE_LABEL[role]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.role} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="user-password">Password</Label>
                        <Input
                            id="user-password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
                            autoComplete="new-password"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="user-password-confirm">Confirm password</Label>
                        <Input
                            id="user-password-confirm"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            required
                            autoComplete="new-password"
                        />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Create
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
