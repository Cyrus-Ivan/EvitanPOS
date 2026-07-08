import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    // Fire toasts for any session flash on the current page.
    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            richColors
            closeButton
            {...props}
        />
    );
}

export { Toaster };
