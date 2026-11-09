import type { ReactNode } from 'react';
import { ThemeToggle } from '@/components/ThemeToggle';

export function AuthLayout({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
      <div className="absolute right-4 top-4">
        <ThemeToggle />
      </div>
      <div className="w-full max-w-sm">
        <p className="mb-6 text-center text-xl font-semibold text-brand-700 dark:text-brand-500">
          FDS Business
        </p>
        <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h1 className="mb-5 text-lg font-semibold">{title}</h1>
          {children}
        </div>
      </div>
    </div>
  );
}
