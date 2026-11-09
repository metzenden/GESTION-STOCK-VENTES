import { Monitor, Moon, Sun } from 'lucide-react';
import { useTheme } from '@/lib/theme';
import type { ThemePreference } from '@/lib/preferences';

const next: Record<ThemePreference, ThemePreference> = { system: 'light', light: 'dark', dark: 'system' };
const labels: Record<ThemePreference, string> = {
  system: 'Thème : système',
  light: 'Thème : clair',
  dark: 'Thème : sombre',
};

export function ThemeToggle() {
  const { preference, setPreference } = useTheme();
  const Icon = preference === 'light' ? Sun : preference === 'dark' ? Moon : Monitor;

  return (
    <button
      type="button"
      onClick={() => setPreference(next[preference])}
      className="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
      aria-label={`${labels[preference]}. Changer de thème`}
      title={labels[preference]}
    >
      <Icon className="size-4" aria-hidden />
      <span className="hidden sm:inline">{labels[preference]}</span>
    </button>
  );
}
