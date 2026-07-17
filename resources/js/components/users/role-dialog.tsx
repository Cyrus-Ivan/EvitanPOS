import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { ManagedUser, UserRole } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

const ROLE_LABEL: Record<UserRole, string> = {
    owner: 'Owner',
    admin: 'Admin',
    cashier: 'Cashier',
};

/**
 * Change an existing employee's role. Only the role is editable — the name is
 * immutable. The select offers only roles the actor may assign.
 */
export default function RoleDialog({
    open,
    onOpenChange,
    user,
    assignableRoles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: ManagedUser | null;
    assignableRoles: UserRole[];
}) {
    const form = useForm<{ role: string }>({ role: user?.role ?? '' });
    const { data, setData, errors, processing, clearErrors } = form;

    useEffect(() => {
        if (open && user) {
            setData('role', user.role);
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, user?.id]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!user) {
            return;
        }
        form.patch(route('users.role', user.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Change role{user ? ` — ${user.name ?? user.username}` : ''}</DialogTitle>
                        <DialogDescription>Changes what this user can see and do.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="role-select">Role</Label>
                        <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                            <SelectTrigger id="role-select" autoFocus>
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

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
