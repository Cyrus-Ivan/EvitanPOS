import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { SharedData } from '@/types';

/**
 * Bridges Laravel session flash to Sonner toasts. Flash a toast from any
 * controller/action with:
 *
 *   return back()->with('toast', ['type' => 'success', 'message' => 'Saved.']);
 *
 * The shape is shared as `flash.toast` by HandleInertiaRequests. We listen on
 * Inertia's global `success` event (fired after every successful visit,
 * including the GET that follows a redirect) so this works from anywhere,
 * independent of React/page context.
 */
export function useFlashToast(): void {
    useEffect(() => {
        return router.on('success', (event) => {
            const props = event.detail.page.props as unknown as SharedData;
            const data = props.flash?.toast;

            if (data) {
                toast[data.type](data.message);
            }
        });
    }, []);
}
