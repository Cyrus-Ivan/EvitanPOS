import { useConfirm } from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { DatabaseBackup } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Database Backup', href: '/backup' }];

interface Backup {
    name: string;
    size: string;
    created_at: string;
}

function formatWhen(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

export default function Backup({ backups }: { backups: Backup[] }) {
    const confirm = useConfirm();
    const [processing, setProcessing] = useState(false);

    const createBackup = () => {
        setProcessing(true);
        router.post(route('backup.store'), {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    const restoreBackup = async (backup: Backup) => {
        if (
            await confirm({
                title: `Restore "${backup.name}"?`,
                description:
                    'This replaces the current database with the selected backup. A safety backup of the current database is taken automatically before restoring.',
                destructive: true,
                confirmLabel: 'Restore',
            })
        ) {
            router.post(route('backup.restore'), { filename: backup.name }, { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Database Backup" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">Database Backup</h1>
                        <p className="text-muted-foreground text-sm">Back up the SQLite database, or restore it from a previous backup.</p>
                    </div>
                    <Button onClick={createBackup} disabled={processing}>
                        <DatabaseBackup className="size-4" /> Create backup
                    </Button>
                </div>

                {backups.length === 0 ? (
                    <EmptyState
                        icon={DatabaseBackup}
                        title="No backups yet"
                        description="Create your first backup to protect your business data."
                        action={
                            <Button onClick={createBackup} disabled={processing}>
                                Create backup
                            </Button>
                        }
                    />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Backup</TableHead>
                                    <TableHead>Size</TableHead>
                                    <TableHead>Created</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {backups.map((backup) => (
                                    <TableRow key={backup.name}>
                                        <TableCell className="font-medium tabular-nums">{backup.name}</TableCell>
                                        <TableCell className="tabular-nums">{backup.size}</TableCell>
                                        <TableCell className="tabular-nums">{formatWhen(backup.created_at)}</TableCell>
                                        <TableCell className="text-right">
                                            <Button variant="ghost" size="sm" onClick={() => restoreBackup(backup)}>
                                                Restore
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
