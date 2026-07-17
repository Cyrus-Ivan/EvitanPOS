import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Printer, RefreshCw } from 'lucide-react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Business Settings', href: '/business-settings' }];

interface BusinessSettingsData {
    business_name: string;
    business_address: string;
    tin: string;
    receipt_footer: string;
    [key: string]: string;
}

// Booleans ride as '1'/'0' strings, matching the string-valued settings table
// and the Laravel `boolean` validation rule (which accepts '1'/'0').
interface PrinterSettingsData {
    receipt_paper_size: string;
    receipt_connection: string;
    receipt_printer_name: string;
    receipt_device_path: string;
    receipt_network_host: string;
    receipt_network_port: string;
    auto_print: string;
    open_cash_drawer: string;
    [key: string]: string;
}

interface DetectedPrinter {
    name: string;
    label: string;
}

export default function BusinessSettings({ settings, printerSettings }: { settings: BusinessSettingsData; printerSettings: PrinterSettingsData }) {
    const { data, setData, errors, patch, processing, recentlySuccessful } = useForm<BusinessSettingsData>({
        business_name: settings.business_name,
        business_address: settings.business_address,
        tin: settings.tin,
        receipt_footer: settings.receipt_footer,
    });

    const printer = useForm<PrinterSettingsData>({
        receipt_paper_size: printerSettings.receipt_paper_size,
        receipt_connection: printerSettings.receipt_connection,
        receipt_printer_name: printerSettings.receipt_printer_name,
        receipt_device_path: printerSettings.receipt_device_path,
        receipt_network_host: printerSettings.receipt_network_host,
        receipt_network_port: printerSettings.receipt_network_port,
        auto_print: printerSettings.auto_print,
        open_cash_drawer: printerSettings.open_cash_drawer,
    });

    const [detectedPrinters, setDetectedPrinters] = useState<DetectedPrinter[]>([]);
    const [detecting, setDetecting] = useState(false);
    const [detected, setDetected] = useState(false);

    const detectPrinters = useCallback(async () => {
        setDetecting(true);
        try {
            const response = await fetch(route('printer-settings.printers'), {
                headers: { Accept: 'application/json' },
            });
            const body = (await response.json()) as { printers?: DetectedPrinter[] };
            setDetectedPrinters(body.printers ?? []);
        } catch {
            setDetectedPrinters([]);
        } finally {
            setDetected(true);
            setDetecting(false);
        }
    }, []);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('business-settings.update'), { preserveScroll: true });
    };

    const submitPrinter: FormEventHandler = (e) => {
        e.preventDefault();
        printer.patch(route('printer-settings.update'), { preserveScroll: true });
    };

    const connection = printer.data.receipt_connection;

    // Discover OS print queues the first time the "System printer" option is in
    // play (only returns names inside the packaged app; empty under artisan serve).
    useEffect(() => {
        if (connection === 'system' && !detected && !detecting) {
            void detectPrinters();
        }
    }, [connection, detected, detecting, detectPrinters]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Business Settings" />

            <div className="flex min-h-full flex-1 flex-col gap-8 p-4">
                <section className="space-y-4">
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
                            <Input
                                id="tin"
                                value={data.tin}
                                onChange={(e) => setData('tin', e.target.value)}
                                placeholder="Tax Identification Number"
                            />
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
                            <Button disabled={processing}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Save
                            </Button>

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
                </section>

                <Separator className="max-w-xl" />

                <section className="space-y-4">
                    <HeadingSmall
                        title="Receipt printing"
                        description="Configure the thermal receipt printer. Sends raw ESC/POS — via the OS print queue, a device file, or the network."
                    />

                    <form onSubmit={submitPrinter} className="max-w-xl space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="receipt_paper_size">Paper width</Label>
                            <Select value={printer.data.receipt_paper_size} onValueChange={(value) => printer.setData('receipt_paper_size', value)}>
                                <SelectTrigger id="receipt_paper_size" className="w-56">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="58mm">58 mm</SelectItem>
                                    <SelectItem value="80mm">80 mm</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={printer.errors.receipt_paper_size} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="receipt_connection">Connection</Label>
                            <Select value={printer.data.receipt_connection} onValueChange={(value) => printer.setData('receipt_connection', value)}>
                                <SelectTrigger id="receipt_connection" className="w-56">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="system">System printer (USB / Bluetooth via OS)</SelectItem>
                                    <SelectItem value="usb">USB device file (Linux)</SelectItem>
                                    <SelectItem value="bluetooth">Bluetooth serial (Linux / macOS)</SelectItem>
                                    <SelectItem value="network">Network (Wi-Fi / Ethernet)</SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                <strong>System printer</strong> is recommended on macOS &amp; Windows — install the printer in the OS, then pick it
                                below.
                            </p>
                            <InputError message={printer.errors.receipt_connection} />
                        </div>

                        {connection === 'system' ? (
                            <div className="grid gap-2">
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="receipt_printer_name">Printer</Label>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 gap-1.5 text-xs"
                                        onClick={() => void detectPrinters()}
                                        disabled={detecting}
                                    >
                                        <RefreshCw className={detecting ? 'size-3.5 animate-spin' : 'size-3.5'} />
                                        {detecting ? 'Detecting…' : 'Detect printers'}
                                    </Button>
                                </div>
                                {detectedPrinters.length > 0 && (
                                    <Select
                                        value={
                                            detectedPrinters.some((p) => p.name === printer.data.receipt_printer_name)
                                                ? printer.data.receipt_printer_name
                                                : ''
                                        }
                                        onValueChange={(value) => printer.setData('receipt_printer_name', value)}
                                    >
                                        <SelectTrigger id="receipt_printer_name" className="w-full">
                                            <SelectValue placeholder="Choose a detected printer" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {detectedPrinters.map((p) => (
                                                <SelectItem key={p.name} value={p.name}>
                                                    {p.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                <Input
                                    value={printer.data.receipt_printer_name}
                                    onChange={(e) => printer.setData('receipt_printer_name', e.target.value)}
                                    placeholder="Printer queue name"
                                    aria-label="Printer queue name"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {detected && detectedPrinters.length === 0
                                        ? 'No printers detected automatically — type the exact OS print-queue name.'
                                        : 'Pick a detected printer, or type the exact OS print-queue name.'}
                                </p>
                                <InputError message={printer.errors.receipt_printer_name} />
                            </div>
                        ) : connection === 'network' ? (
                            <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
                                <div className="grid gap-2">
                                    <Label htmlFor="receipt_network_host">Printer IP address</Label>
                                    <Input
                                        id="receipt_network_host"
                                        value={printer.data.receipt_network_host}
                                        onChange={(e) => printer.setData('receipt_network_host', e.target.value)}
                                        placeholder="192.168.1.50"
                                    />
                                    <InputError message={printer.errors.receipt_network_host} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="receipt_network_port">Port</Label>
                                    <Input
                                        id="receipt_network_port"
                                        type="number"
                                        className="w-28"
                                        value={printer.data.receipt_network_port}
                                        onChange={(e) => printer.setData('receipt_network_port', e.target.value)}
                                        placeholder="9100"
                                    />
                                    <InputError message={printer.errors.receipt_network_port} />
                                </div>
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="receipt_device_path">Device path</Label>
                                <Input
                                    id="receipt_device_path"
                                    value={printer.data.receipt_device_path}
                                    onChange={(e) => printer.setData('receipt_device_path', e.target.value)}
                                    placeholder="/dev/usb/lp0"
                                />
                                <p className="text-muted-foreground text-xs">
                                    Linux USB printers are usually <code>/dev/usb/lp0</code>; a paired Bluetooth printer is <code>/dev/rfcomm0</code>{' '}
                                    (Linux) or <code>/dev/cu.*</code> (macOS). The app needs write access to the device. On macOS/Windows, prefer{' '}
                                    <strong>System printer</strong> above.
                                </p>
                                <InputError message={printer.errors.receipt_device_path} />
                            </div>
                        )}

                        <label className="flex items-start gap-3">
                            <Checkbox
                                checked={printer.data.auto_print === '1'}
                                onCheckedChange={(value) => printer.setData('auto_print', value === true ? '1' : '0')}
                            />
                            <span className="grid gap-1">
                                <span className="text-sm font-medium">Print automatically after each sale</span>
                                <span className="text-muted-foreground text-xs">
                                    A failed print never cancels the sale — you can always reprint from Sales.
                                </span>
                            </span>
                        </label>

                        <label className="flex items-start gap-3">
                            <Checkbox
                                checked={printer.data.open_cash_drawer === '1'}
                                onCheckedChange={(value) => printer.setData('open_cash_drawer', value === true ? '1' : '0')}
                            />
                            <span className="grid gap-1">
                                <span className="text-sm font-medium">Open the cash drawer when printing</span>
                                <span className="text-muted-foreground text-xs">Sends a drawer-kick pulse to a drawer wired to the printer.</span>
                            </span>
                        </label>

                        <div className="flex items-center gap-3">
                            <Button disabled={printer.processing}>
                                {printer.processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.post(route('printer-settings.test'), {}, { preserveScroll: true })}
                            >
                                <Printer className="size-4" />
                                Test print
                            </Button>

                            <Transition
                                show={printer.recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                        <p className="text-muted-foreground text-xs">Save your changes before running a test print.</p>
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}
