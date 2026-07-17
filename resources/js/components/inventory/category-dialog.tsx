import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Category } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

/**
 * Create/edit dialog for a Category. Reused for both actions: pass a `category`
 * to edit, or omit it to create. Establishes the modal-form pattern (useForm +
 * shadcn Dialog) for the rest of the app.
 */
export default function CategoryDialog({
    open,
    onOpenChange,
    category,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    category?: Category | null;
}) {
    const isEdit = Boolean(category);
    const form = useForm<{ name: string }>({ name: category?.name ?? '' });
    const { data, setData, errors, processing, reset, clearErrors } = form;

    // Reset the field whenever the dialog opens so stale values/errors don't leak
    // between the create and edit flows.
    useEffect(() => {
        if (open) {
            setData('name', category?.name ?? '');
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, category?.id]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        };

        if (isEdit && category) {
            form.patch(route('categories.update', category.id), options);
        } else {
            form.post(route('categories.store'), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{isEdit ? 'Edit category' : 'New category'}</DialogTitle>
                        <DialogDescription>Categories group items in inventory and reports.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="category-name">Name</Label>
                        <Input
                            id="category-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            autoFocus
                            placeholder="e.g. Beverages"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            {isEdit ? 'Save' : 'Create'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
