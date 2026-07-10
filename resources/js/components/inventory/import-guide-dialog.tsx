import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { BookOpen } from 'lucide-react';

interface ColumnDoc {
    name: string;
    required: boolean;
    type: string;
    description: React.ReactNode;
}

const COLUMNS: ColumnDoc[] = [
    {
        name: 'category',
        required: false,
        type: 'text',
        description: 'Groups the product. Created automatically if it doesn’t exist yet. Leave blank for uncategorized.',
    },
    {
        name: 'product',
        required: true,
        type: 'text',
        description: 'The item name (e.g. Bottled Water). Rows sharing the same product and category group under one item.',
    },
    {
        name: 'variant',
        required: true,
        type: 'text',
        description: 'The specific version — size, flavor, color (e.g. 500ml). Every product has at least one.',
    },
    {
        name: 'sku',
        required: true,
        type: 'text',
        description:
            'Unique stock code and the match key. An existing SKU updates that variant; a new one creates it. Must be unique within the file.',
    },
    {
        name: 'barcode',
        required: false,
        type: 'text',
        description: 'Scannable code. Unique when provided — a blank barcode is fine and never blocks a sale.',
    },
    { name: 'selling_price', required: true, type: 'number', description: 'Price to customers. Up to 2 decimals, not negative.' },
    {
        name: 'cost',
        required: false,
        type: 'number',
        description: 'Owner only — ignored on import for anyone else, and left off exports. Up to 2 decimals, not negative.',
    },
    { name: 'stock', required: true, type: 'whole number', description: 'On-hand quantity, read together with the mode column.' },
    { name: 'unit', required: false, type: 'text', description: 'Unit of measure — pc, kg, box. Defaults to “pc” for new products.' },
    { name: 'description', required: false, type: 'text', description: 'Free-text notes about the product.' },
    {
        name: 'low_stock_threshold',
        required: false,
        type: 'whole number',
        description: 'The level at which a variant is flagged “Low”. Defaults to 0.',
    },
    { name: 'mode', required: true, type: 'keyword', description: 'How to apply the stock number — replace_stock_count or add_to_current_stock.' },
];

const EXAMPLE_HEADERS = [
    'category',
    'product',
    'variant',
    'sku',
    'barcode',
    'selling_price',
    'cost',
    'stock',
    'unit',
    'description',
    'low_stock_threshold',
    'mode',
];
const EXAMPLE_ROW = [
    'Beverages',
    'Bottled Water',
    '500ml',
    'WATER-500',
    '4801234567890',
    '15.00',
    '9.50',
    '100',
    'pc',
    'Purified drinking water',
    '12',
    'replace_stock_count',
];

const REJECTION_REASONS = [
    'A required column is missing from the header.',
    'A required cell is blank (product, variant, sku, selling_price, stock, or mode).',
    'A price, stock, or threshold isn’t a valid number — or a price / replace-stock is negative.',
    'The mode isn’t replace_stock_count or add_to_current_stock.',
    'The same SKU or barcode appears twice in the file.',
    'A barcode is already used by a different product.',
    'A stock change would push a variant below zero.',
    'The upload isn’t a CSV, or is larger than 5 MB.',
];

function Mono({ children }: { children: React.ReactNode }) {
    return <code className="bg-muted rounded px-1 py-0.5 font-mono text-[0.8em]">{children}</code>;
}

function Callout({ title, tone = 'accent', children }: { title: string; tone?: 'accent' | 'warn'; children: React.ReactNode }) {
    const toneClasses = tone === 'warn' ? 'border-destructive/30 bg-destructive/5' : 'border-primary/30 bg-primary/5';
    return (
        <div className={`rounded-lg border p-3 ${toneClasses}`}>
            <p className="text-xs font-semibold tracking-wide uppercase">{title}</p>
            <p className="text-muted-foreground mt-1 text-sm">{children}</p>
        </div>
    );
}

function SectionHeading({ children }: { children: React.ReactNode }) {
    return <h3 className="text-sm font-semibold">{children}</h3>;
}

export default function ImportGuideDialog() {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <BookOpen className="size-4" /> Guide
                </Button>
            </DialogTrigger>
            <DialogContent className="flex max-h-[85vh] w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl">
                <DialogHeader className="shrink-0 border-b px-4 py-4 pr-12 sm:px-6">
                    <DialogTitle>Inventory CSV import guide</DialogTitle>
                    <DialogDescription>
                        Load or update your whole catalog from one spreadsheet. Here’s every column, the two stock modes, and why an import might be
                        rejected.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 min-w-0 flex-1 space-y-6 overflow-y-auto px-4 py-4 text-sm sm:px-6">
                    {/* How it works */}
                    <section className="space-y-3">
                        <SectionHeading>How the import thinks</SectionHeading>
                        <Callout title="Matched by SKU">
                            The <Mono>sku</Mono> is the key. If a row’s SKU already exists, that variant is <strong>updated</strong> and its stock
                            adjusted. If the SKU is new, the category, product and variant are <strong>created</strong> for you.
                        </Callout>
                        <Callout title="All or nothing" tone="warn">
                            The whole file is validated before anything is saved. If <strong>any</strong> row has a problem, the import is rejected
                            and <strong>nothing changes</strong> — Upload history names the row and reason so you can fix it and re-upload.
                        </Callout>
                    </section>

                    {/* Columns */}
                    <section className="space-y-3">
                        <SectionHeading>The columns</SectionHeading>
                        <p className="text-muted-foreground">
                            Order doesn’t matter and headers are case-insensitive — <Mono>Selling Price</Mono> and <Mono>selling_price</Mono> both
                            match. Unknown columns are ignored.
                        </p>
                        <div className="divide-y rounded-lg border">
                            {COLUMNS.map((column) => (
                                <div key={column.name} className="grid gap-1 p-3 sm:grid-cols-[11rem_1fr] sm:gap-4">
                                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                                        <span className="font-mono text-sm font-medium">{column.name}</span>
                                        {column.required ? (
                                            <Badge className="text-[0.65rem]">Required</Badge>
                                        ) : (
                                            <Badge variant="secondary" className="text-[0.65rem]">
                                                Optional
                                            </Badge>
                                        )}
                                        <span className="text-muted-foreground font-mono text-xs">{column.type}</span>
                                    </div>
                                    <p className="text-muted-foreground min-w-0">{column.description}</p>
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* Modes */}
                    <section className="space-y-3">
                        <SectionHeading>Two ways to set stock</SectionHeading>
                        <p className="text-muted-foreground">
                            Each row carries its own mode, so one file can create new products and adjust existing ones at once. For a brand-new
                            variant both modes simply set the opening count.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="bg-muted/40 space-y-2 rounded-lg border p-3">
                                <p className="font-mono text-xs font-medium">replace_stock_count</p>
                                <p className="text-muted-foreground">
                                    Sets the exact on-hand to your stock value; the system works out the adjustment. Best for a physical count. Can’t
                                    be negative.
                                </p>
                                <p className="font-mono text-xs">Current 5 · stock 20 → on-hand 20 (+15)</p>
                            </div>
                            <div className="bg-muted/40 space-y-2 rounded-lg border p-3">
                                <p className="font-mono text-xs font-medium">add_to_current_stock</p>
                                <p className="text-muted-foreground">
                                    Adds your stock value to what’s there; use a negative number to subtract. Never goes below zero.
                                </p>
                                <p className="font-mono text-xs">Current 10 · stock 5 → on-hand 15 (or −3 → 7)</p>
                            </div>
                        </div>
                    </section>

                    {/* Example */}
                    <section className="space-y-3">
                        <SectionHeading>Example</SectionHeading>
                        <p className="text-muted-foreground">The header row plus one product — exactly what the template contains.</p>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full border-collapse font-mono text-xs whitespace-nowrap">
                                <thead>
                                    <tr className="bg-muted/60 text-muted-foreground">
                                        {EXAMPLE_HEADERS.map((header) => (
                                            <th key={header} className="border-r px-2.5 py-1.5 text-left font-medium last:border-r-0">
                                                {header}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        {EXAMPLE_ROW.map((cell, index) => (
                                            <td key={index} className="border-r px-2.5 py-1.5 tabular-nums last:border-r-0">
                                                {cell}
                                            </td>
                                        ))}
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {/* Rules */}
                    <section className="space-y-3">
                        <SectionHeading>Rules &amp; limits</SectionHeading>
                        <ul className="text-muted-foreground list-disc space-y-1.5 pl-5">
                            <li>
                                The file must be a <Mono>.csv</Mono> up to 5 MB.
                            </li>
                            <li>Prices use up to 2 decimals and are never negative.</li>
                            <li>
                                Stock and threshold are whole numbers. Replace mode can’t be negative; add mode may subtract but never below zero.
                            </li>
                            <li>
                                SKU is required on every row and unique within the file. Barcode is unique across all products, including archived
                                ones.
                            </li>
                            <li>Cost is Owner-only; for others it’s simply ignored.</li>
                            <li>Blank rows are skipped, and blank optional cells use their defaults.</li>
                        </ul>
                    </section>

                    {/* Rejection */}
                    <section className="space-y-3">
                        <SectionHeading>Why an import gets rejected</SectionHeading>
                        <p className="text-muted-foreground">Any one of these stops the whole file — nothing is saved. Fix it and re-upload.</p>
                        <ul className="space-y-1.5">
                            {REJECTION_REASONS.map((reason) => (
                                <li key={reason} className="flex gap-2">
                                    <span aria-hidden className="bg-destructive mt-1.5 size-1.5 shrink-0 rotate-45" />
                                    <span className="text-muted-foreground">{reason}</span>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <p className="text-muted-foreground border-t pt-4 text-xs">
                        Tip: to update existing products, use <strong>Export CSV</strong> first, edit the numbers, and re-import with{' '}
                        <Mono>replace_stock_count</Mono> — the export is already in this exact format.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
