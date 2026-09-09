import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

/**
 * Correctness gate for the Vue 3 + TS frontend. Design-system regressions
 * (raw form controls, raw palette classes, "— Atlas" titles) are enforced
 * separately, changed-lines-only, by scripts/check-design-system.mjs.
 */
export default tseslint.config(
  {
    ignores: [
      'node_modules/**',
      'public/**',
      'vendor/**',
      'storage/**',
      'bootstrap/ssr/**',
      '**/*.d.ts',
      'vite.config.ts',
      'vitest.config.ts',
      'playwright.config.ts',
      'eslint.config.js',
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  // `essential` = error-prevention rules only. Formatting/style is left to
  // the existing hand-formatting convention (there is no Prettier config).
  ...pluginVue.configs['flat/essential'],
  {
    files: ['**/*.vue'],
    languageOptions: {
      parserOptions: { parser: tseslint.parser },
    },
  },
  {
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: { ...globals.browser, ...globals.node },
    },
  },
  {
    rules: {
      // Inertia pages are single-word by framework convention.
      'vue/multi-word-component-names': 'off',
      // Allow deliberate `any` at boundaries; still flags implicit any via TS.
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
    },
  },
  {
    files: ['**/*.spec.ts', '**/*.test.ts', 'tests/**'],
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
    },
  },
)
