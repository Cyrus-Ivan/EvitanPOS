import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Business Settings', href: '/business-settings' }];

interface BusinessSettingsData {
    business_name: string;
    business_address: string;
    tin: string;
    receipt_footer: string;
    [key: string]: string;
}

export default function BusinessSettings({ settings }: { settings: BusinessSettingsData }) {
    const { data, setData, errors, patch, processing, recentlySuccessful } = useForm<BusinessSettingsData>({
        business_name: settings.business_name,
        business_address: settings.business_address,
        tin: settings.tin,
        receipt_footer: settings.receipt_footer,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('business-settings.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Business Settings" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <HeadingSmall title="Business Settings" description="Business identity used on receipts and reports." />

                <form onSubmit={submit} className="max-w-xl space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="business_name">Business name</Label>
                        <Input
                            id="business_name"
                            value={data.business_name}
                            onChange={(e) => setData('business_name', e.target.value)}
                            required
                            autoComplete="organization"
                            placeholder="Business name"
                        />
                        <InputError message={errors.business_name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="business_address">Address</Label>
                        <Textarea
                            id="business_address"
                            value={data.business_address}
                            onChange={(e) => setData('business_address', e.target.value)}
                            placeholder="Business address"
                        />
                        <InputError message={errors.business_address} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="tin">TIN</Label>
                        <Input id="tin" value={data.tin} onChange={(e) => setData('tin', e.target.value)} placeholder="Tax Identification Number" />
                        <InputError message={errors.tin} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="receipt_footer">Receipt footer</Label>
                        <Textarea
                            id="receipt_footer"
                            value={data.receipt_footer}
                            onChange={(e) => setData('receipt_footer', e.target.value)}
                            placeholder="Message printed at the bottom of every receipt"
                        />
                        <InputError message={errors.receipt_footer} />
                    </div>

                    <div className="flex items-center gap-4">
                        <Button disabled={processing}>Save</Button>

                        <Transition
                            show={recentlySuccessful}
                            enter="transition ease-in-out"
                            enterFrom="opacity-0"
                            leave="transition ease-in-out"
                            leaveTo="opacity-0"
                        >
                            <p className="text-sm text-neutral-600">Saved</p>
                        </Transition>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
