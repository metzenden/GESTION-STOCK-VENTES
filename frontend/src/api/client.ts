import createClient, { type Middleware } from 'openapi-fetch';
import type { components, paths } from '@/api/schema';

export type Problem = components['schemas']['Problem'];

/** Erreur API normalisée (RFC 9457) ; `detail` est rédigé en français pour l'utilisateur. */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly problem: Problem | null;

  constructor(status: number, problem: Problem | null) {
    super(problem?.detail ?? problem?.title ?? `Erreur HTTP ${status}`);
    this.name = 'ApiError';
    this.status = status;
    this.code = problem?.code ?? 'http_error';
    this.problem = problem;
  }
}

const UNSAFE_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

function readCookie(name: string): string | null {
  const entry = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));
  return entry === undefined ? null : decodeURIComponent(entry.slice(name.length + 1));
}

/** Clé à générer une fois par intention utilisateur (un clic sur « Valider »), puis réutilisée en cas de nouvel essai. */
export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}

/** Dépose le cookie XSRF-TOKEN (Sanctum) avant la première écriture de la session. */
export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN') !== null) {
    return;
  }
  await globalThis.fetch(`${window.location.origin}/sanctum/csrf-cookie`, { credentials: 'same-origin' });
}

/** Erreurs de validation par champ : pointeur RFC 6901 « /email » → message. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError) || error.problem?.errors === undefined) {
    return {};
  }
  const result: Record<string, string> = {};
  for (const item of error.problem.errors) {
    const key = (item.pointer ?? '').replace(/^\//, '');
    if (item.message !== undefined && result[key] === undefined) {
      result[key] = item.message;
    }
  }
  return result;
}

const securityMiddleware: Middleware = {
  async onRequest({ request }) {
    request.headers.set('Accept', 'application/json');
    if (UNSAFE_METHODS.has(request.method)) {
      await ensureCsrfCookie();
      const xsrf = readCookie('XSRF-TOKEN');
      if (xsrf !== null) {
        request.headers.set('X-XSRF-TOKEN', xsrf);
      }
    }
    return request;
  },
  async onResponse({ response }) {
    if (response.ok) {
      return response;
    }
    let problem: Problem | null = null;
    try {
      problem = (await response.clone().json()) as Problem;
    } catch {
      problem = null;
    }
    throw new ApiError(response.status, problem);
  },
};

export const api = createClient<paths>({
  // URL absolue : Request (undici, jsdom) refuse les URL relatives ; l'API reste sur la même origine (D-043).
  baseUrl: `${window.location.origin}/api/v1`,
  credentials: 'same-origin',
  // Résolu à chaque appel, pour que les tests puissent remplacer fetch après l'import du module.
  fetch: (request) => globalThis.fetch(request),
});
api.use(securityMiddleware);
