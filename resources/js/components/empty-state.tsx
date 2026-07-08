import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type EmptyStateProps = {
    icon?: LucideIcon;
    title: string;
    description?: string;
    /** Optional call-to-action, e.g. a button. */
    action?: ReactNode;
    className?: string;
};

/**
 * Neutral placeholder shown when a page or list has nothing to display.
 * Used across the app for "no results" and (during early phases) for
 * not-yet-built sections.
 */
export default function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex h-full flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border',
                className,
            )}
        >
            {Icon && (
                <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Icon className="size-6" />
                </div>
            )}
            <div className="space-y-1">
                <h3 className="text-lg font-semibold tracking-tight">{title}</h3>
                {description && (
                    <p className="mx-auto max-w-md text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {action}
        </div>
    );
}
