import { Link } from 'react-router';

export function NotFoundPage() {
  return (
    <div className="mx-auto max-w-md py-16 text-center">
      <h1 className="text-2xl font-semibold">Page introuvable</h1>
      <p className="mt-2 text-slate-600 dark:text-slate-400">Cette page n'existe pas ou a été déplacée.</p>
      <Link to="/" className="mt-6 inline-block text-brand-700 underline dark:text-brand-500">
        Retour au tableau de bord
      </Link>
    </div>
  );
}
