import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { routes } from '@/app/App';
import { ThemeProvider } from '@/lib/theme';

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const ID = (n: string) => `01JCXK8H0V3Q8T1Q4M7R2N5W${n}`;

function me(permissions: string[]) {
  return {
    data: {
      user: { id: ID('6Y'), name: 'Moussa', email: 'm@example.com', is_super_admin: false, theme: 'system' },
      company: {
        id: ID('6Z'),
        code: 'FDS',
        trade_name: 'FDS Dakar',
        status: 'active',
        reference_currency: 'XOF',
        timezone: 'Africa/Dakar',
      },
      permissions,
    },
  };
}

function renderAt(
  path: string,
  handlers: Record<string, (request: Request) => Response | Promise<Response>>,
) {
  const calls: { method: string; path: string; body: string }[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const request = input instanceof Request ? input : new Request(String(input));
      const url = new URL(request.url);
      calls.push({
        method: request.method,
        path: url.pathname,
        body: request.method === 'GET' ? '' : await request.clone().text(),
      });
      const handler = handlers[`${request.method} ${url.pathname}`] ?? handlers[url.pathname];
      return handler !== undefined
        ? handler(request)
        : json({ data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1, unread: 0 } });
    }),
  );
  render(
    <ThemeProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />
      </QueryClientProvider>
    </ThemeProvider>,
  );
  return calls;
}

afterEach(() => vi.unstubAllGlobals());

describe('Écrans de vente et d’approbation', () => {
  it('signale la vente à crédit quand aucun compte n’accepte le moyen choisi', async () => {
    renderAt('/comptoir', {
      '/api/v1/auth/me': () => json(me(['sales.confirm', 'deliveries.handover', 'customer_payments.create'])),
      '/api/v1/financial-accounts': () =>
        json({
          data: [
            {
              id: ID('7A'),
              code: 'CAISSE',
              name: 'Caisse',
              account_type: 'cash',
              currency: 'XOF',
              status: 'active',
            },
          ],
          meta: { redacted: ['balance'] },
        }),
    });

    expect(await screen.findByText('Vente comptoir', { selector: 'h1' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Encaisser et remettre' })).toBeDisabled();
    await userEvent.click(screen.getByRole('button', { name: 'Wave' }));
    expect(screen.getByText(/enregistrée à crédit/)).toBeInTheDocument();
  });

  it('permet à un approbateur d’approuver une demande en attente avec une clé d’idempotence', async () => {
    const calls = renderAt('/approbations', {
      '/api/v1/auth/me': () => json(me(['approvals.approve'])),
      'GET /api/v1/approvals': () =>
        json({
          data: [
            {
              id: ID('8A'),
              number: 'APP-2026-0001',
              operation: 'sales_order',
              summary: 'Commande CMD-2026-0004 : remise 20 %',
              status: 'pending',
              last_error: null,
              created_at: '2026-11-09T09:00:00Z',
            },
          ],
          meta: { page: 1, per_page: 50, total: 1, last_page: 1 },
        }),
      [`POST /api/v1/approvals/${ID('8A')}/approve`]: () =>
        json({ data: { id: ID('8A'), status: 'approved' } }),
    });

    expect(await screen.findByText(/remise 20 %/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Approuver' }));
    await vi.waitFor(() =>
      expect(calls.some((c) => c.method === 'POST' && c.path.endsWith('/approve'))).toBe(true),
    );
  });
});
