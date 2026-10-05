import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * Flips between light and dark. "System" (follow the device) is in
 * Settings → Appearance.
 */
export default function ThemeToggle() {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const next = resolvedAppearance === 'dark' ? 'light' : 'dark';

    return (
        <Button
            variant="ghost"
            size="icon"
            onClick={() => updateAppearance(next)}
            aria-label={`Switch to ${next} mode`}
            title={`Switch to ${next} mode`}
            data-test="theme-toggle"
        >
            {next === 'dark' ? <Moon /> : <Sun />}
        </Button>
    );
}
