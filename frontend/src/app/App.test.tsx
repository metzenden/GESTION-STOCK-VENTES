import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { routes } from '@/app/App';
import { ThemeProvider } from '@/lib/theme';

function renderAt(path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <ThemeProvider>
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>
    </ThemeProvider>,
  );

  return router;
}

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

function profile(permissions: string[], overrides: Record<string, unknown> = {}) {
  return {
    data: {
      user: {
        id: '01JCXK8H0V3Q8T1Q4M7R2N5W6Y',
        name: 'Awa Diop',
        email: 'awa@example.com',
        is_super_admin: false,
        theme: 'system',
      },
      company: {
        id: '01JCXK8H0V3Q8T1Q4M7R2N5W6Z',
        code: 'FDS',
        trade_name: 'FDS Dakar',
        status: 'active',
        reference_currency: 'XOF',
        timezone: 'Africa/Dakar',
      },
      permissions,
      ...overrides,
    },
  };
}

/** Faux serveur : route → réponse. */
function mockApi(handlers: Record<string, () => Response>) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
    const url = new URL(input instanceof Request ? input.url : String(input));
    const handler = handlers[url.pathname];
    return handler !== undefined
      ? handler()
      : json({ code: 'not_found', title: 'Introuvable', status: 404, type: 'x' }, 404);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('Application', () => {
  it("redirige vers la connexion quand personne n'est connecté", async () => {
    mockApi({
      '/api/v1/auth/me': () => json({ code: 'unauthenticated', title: 'x', status: 401, type: 'x' }, 401),
    });

    const router = renderAt('/parametres');

    expect(await screen.findByRole('heading', { name: 'Connexion' })).toBeInTheDocument();
    expect(router.state.location.search).toBe('?redirection=%2Fparametres');
  });

  it("connecte l'utilisateur et affiche son entreprise", async () => {
    let loggedIn = false;
    mockApi({
      '/api/v1/auth/me': () =>
        loggedIn
          ? json(profile(['users.view']))
          : json({ code: 'unauthenticated', title: 'x', status: 401, type: 'x' }, 401),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/auth/login': () => {
        loggedIn = true;
        return json(profile(['users.view']));
      },
      '/api/v1/health': () => json({ status: 'ok' }),
    });
    renderAt('/connexion');
    const user = userEvent.setup();

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'awa@example.com');
    await user.type(screen.getByLabelText('Mot de passe'), 'MotDePasse-2026');
    await user.click(screen.getByRole('button', { name: 'Se connecter' }));

    expect(await screen.findByRole('heading', { name: 'Tableau de bord' })).toBeInTheDocument();
    expect(screen.getAllByText(/FDS Dakar/).length).toBeGreaterThan(0);
    expect(await screen.findByText('Opérationnelle')).toBeInTheDocument();
  });

  it("affiche l'erreur de connexion renvoyée par l'API", async () => {
    mockApi({
      '/api/v1/auth/me': () => json({ code: 'unauthenticated', title: 'x', status: 401, type: 'x' }, 401),
      '/sanctum/csrf-cookie': () => new Response(null, { status: 204 }),
      '/api/v1/auth/login': () =>
        json(
          {
            code: 'invalid_credentials',
            title: 'Identifiants incorrects',
            status: 422,
            type: 'x',
            detail: 'Adresse e-mail ou mot de passe incorrect.',
          },
          422,
        ),
    });
    renderAt('/connexion');
    const user = userEvent.setup();

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'x@example.com');
    await user.type(screen.getByLabelText('Mot de passe'), 'faux');
    await user.click(screen.getByRole('button', { name: 'Se connecter' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Adresse e-mail ou mot de passe incorrect.');
  });

  it("n'affiche dans le menu que les sections autorisées", async () => {
    mockApi({
      '/api/v1/auth/me': () => json(profile(['users.view'])),
      '/api/v1/health': () => json({ status: 'ok' }),
    });

    renderAt('/');

    expect(await screen.findByRole('link', { name: 'Utilisateurs' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Paramètres' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Entreprises' })).not.toBeInTheDocument();
  });

  it('refuse une page sans la permission correspondante', async () => {
    mockApi({ '/api/v1/auth/me': () => json(profile(['users.view'])) });

    renderAt('/parametres');

    expect(await screen.findByRole('heading', { name: 'Accès refusé' })).toBeInTheDocument();
  });

  it('demande au super-admin de choisir une entreprise avant les pages métier', async () => {
    mockApi({
      '/api/v1/auth/me': () =>
        json(
          profile(['platform.companies.manage'], {
            company: null,
            user: {
              id: '01JCXK8H0V3Q8T1Q4M7R2N5W6Y',
              name: 'Admin',
              email: 'a@example.com',
              is_super_admin: true,
              theme: 'system',
            },
          }),
        ),
      '/api/v1/admin/companies': () =>
        json({ data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 } }),
    });

    renderAt('/utilisateurs');

    expect(await screen.findByRole('heading', { name: 'Choisissez une entreprise' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Entreprise active' })).toBeInTheDocument();
  });

  it('bascule le thème clair, sombre puis système', async () => {
    mockApi({ '/api/v1/auth/me': () => json(profile([])), '/api/v1/health': () => json({ status: 'ok' }) });
    renderAt('/');
    const user = userEvent.setup();

    await user.click(await screen.findByRole('button', { name: /Thème : système/ }));
    expect(document.documentElement.classList.contains('dark')).toBe(false);

    await user.click(screen.getByRole('button', { name: /Thème : clair/ }));
    expect(document.documentElement.classList.contains('dark')).toBe(true);
  });

  it('affiche une page introuvable pour une route inconnue', async () => {
    mockApi({ '/api/v1/auth/me': () => json(profile([])) });
    renderAt('/inconnu');

    expect(await screen.findByRole('heading', { name: 'Page introuvable' })).toBeInTheDocument();
  });
});
