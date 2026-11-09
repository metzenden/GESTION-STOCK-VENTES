// Seul module autorisé à utiliser localStorage : préférences d'affichage, jamais de données métier.

export type ThemePreference = 'light' | 'dark' | 'system';

const THEME_KEY = 'fds.theme';

export function readThemePreference(): ThemePreference {
  try {
    const value = localStorage.getItem(THEME_KEY);
    return value === 'light' || value === 'dark' ? value : 'system';
  } catch {
    return 'system';
  }
}

export function writeThemePreference(value: ThemePreference): void {
  try {
    if (value === 'system') {
      localStorage.removeItem(THEME_KEY);
    } else {
      localStorage.setItem(THEME_KEY, value);
    }
  } catch {
    // Stockage indisponible (navigation privée) : la préférence reste en mémoire.
  }
}
