import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  { ignores: ['dist', 'coverage', 'src/api/schema.d.ts'] },
  {
    extends: [js.configs.recommended, ...tseslint.configs.strict],
    files: ['**/*.{ts,tsx}'],
    languageOptions: { ecmaVersion: 2022, globals: globals.browser },
    plugins: { 'react-hooks': reactHooks, 'react-refresh': reactRefresh },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
      // Les montants circulent en chaînes décimales : interdiction de parseFloat sur des données monétaires (D-013).
      'no-restricted-globals': ['error', { name: 'parseFloat', message: 'Utiliser lib/money.ts pour les montants.' }],
      'no-restricted-properties': ['error', { object: 'Number', property: 'parseFloat', message: 'Utiliser lib/money.ts pour les montants.' }],
      'no-restricted-syntax': ['error', { selector: "MemberExpression[object.name='localStorage']", message: 'Aucune donnée en localStorage hors préférences (lib/preferences.ts).' }],
    },
  },
  {
    files: ['src/lib/preferences.ts'],
    rules: { 'no-restricted-syntax': 'off' },
  },
);
