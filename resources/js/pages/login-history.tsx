import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, LoginEventType, LoginHistoryEntry, Paginated, User } from '@/types';
import { Head, router } from '@inertiajs/react';
import { History } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Login History', href: '/login-history' }];

const ALL = 'all';

// Human labels + badge tone per event. Clean sign-in/out reads neutral; the
// abnormal terminations (crash / power loss / forced) read as warnings.
const EVENT: Record<LoginEventType, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    login: { label: 'Login', variant: 'default' },
    logout: { label: 'Logout', variant: 'secondary' },
    // Any unclean exit (window closed / shutdown / crash / force-kill) is detected
    // on the next launch and recorded uniformly as application_crash.
    application_crash: { label: 'Unexpected exit', variant: 'destructive' },
    forced_logout: { label: 'Forced logout', variant: 'destructive' },
    // Legacy values kept for historical rows recorded before the boot-guard model.
    window_closed: { label: 'Window closed', variant: 'outline' },
    power_loss: { label: 'Power loss', variant: 'destructive' },
};

function formatWhen(value: string | null): string {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

interface Filters {
    user: string | null;
    event: string | null;
    date_from: string | null;
    date_to: string | null;
}

export default function LoginHistory({
    entries,
    canViewAll,
    users,
    eventTypes,
    filters,
}: {
    entries: Paginated<LoginHistoryEntry>;
    canViewAll: boolean;
    users: Pick<User, 'id' | 'name' | 'username'>[];
    eventTypes: LoginEventType[];
    filters: Filters;
}) {
    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            route('login-history'),
            {
                user: merged.user && merged.user !== ALL ? merged.user : undefined,
                event: merged.event && merged.event !== ALL ? merged.event : undefined,
                date_from: merged.date_from || undefined,
                date_to: merged.date_to || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true, only: ['entries', 'filters'] },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Login History" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    {canViewAll && (
                        <Select value={filters.user ?? ALL} onValueChange={(value) => applyFilters({ user: value })}>
                            <SelectTrigger className="w-56">
                                <SelectValue placeholder="All users" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All users</SelectItem>
                                {users.map((user) => (
                                    <SelectItem key={user.id} value={user.id}>
                                        {user.name ?? user.username}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <Select value={filters.event ?? ALL} onValueChange={(value) => applyFilters({ event: value })}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All events" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All events</SelectItem>
                            {eventTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {EVENT[type]?.label ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground">From</span>
                        <Input
                            type="date"
                            value={filters.date_from ?? ''}
                            max={filters.date_to ?? undefined}
                            onChange={(e) => applyFilters({ date_from: e.target.value || null })}
                            className="w-40"
                        />
                        <span className="text-sm text-muted-foreground">To</span>
                        <Input
                            type="date"
                            value={filters.date_to ?? ''}
                            min={filters.date_from ?? undefined}
                            onChange={(e) => applyFilters({ date_to: e.target.value || null })}
                            className="w-40"
                        />
                    </div>
                </div>

                {entries.data.length === 0 ? (
                    <EmptyState icon={History} title="No login events" description="Authentication events will appear here as users sign in and out." />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    {canViewAll && <TableHead>User</TableHead>}
                                    <TableHead>Event</TableHead>
                                    <TableHead>Notes</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.data.map((entry) => (
                                    <TableRow key={entry.id}>
                                        <TableCell className="tabular-nums">{formatWhen(entry.occurred_at ?? entry.created_at)}</TableCell>
                                        {canViewAll && <TableCell>{entry.user?.name ?? entry.user?.username ?? '—'}</TableCell>}
                                        <TableCell>
                                            <Badge variant={EVENT[entry.event_type]?.variant ?? 'outline'}>
                                                {EVENT[entry.event_type]?.label ?? entry.event_type}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">{entry.notes ?? '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <Pagination links={entries.links} />
            </div>
        </AppLayout>
    );
}
