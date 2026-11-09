import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { routes } from '@/app/App';
import { ThemeProvider } from '@/lib/theme';

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const meta = { page: 1, per_page: 50, total: 1, last_page: 1 };
const variant = {
  id: '01JCXK8H0V3Q8T1Q4M7R2N5W6A',
  product_id: '01JCXK8H0V3Q8T1Q4M7R2N5W6B',
  reference: 'TSH',
  product_name: 'T-shirt',
  sku: 'TSH',
  label: 'T-shirt',
};

function me(permissions: string[]) {
  return {
    data: {
      user: {
        id: '01JCXK8H0V3Q8T1Q4M7R2N5W6Y',
        name: 'Fatou',
        email: 'f@example.com',
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
    },
  };
}

function renderAt(path: string, handlers: Record<string, (url: URL, init?: Request) => Response>) {
  const calls: string[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const request = input instanceof Request ? input : new Request(String(input));
      const url = new URL(request.url);
      calls.push(`${request.method} ${url.pathname}${url.search}`);
      const handler = handlers[`${request.method} ${url.pathname}`] ?? handlers[url.pathname];
      return handler !== undefined
        ? handler(url, request)
        : json({ code: 'not_found', title: 'x', status: 404, type: 'x' }, 404);
    }),
  );
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(
    <ThemeProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <RouterProvider router={router} />
      </QueryClientProvider>
    </ThemeProvider>,
  );
  return calls;
}

afterEach(() => vi.unstubAllGlobals());

describe('Écrans de stock', () => {
  it('affiche les compartiments et signale le stock faible, sans les coûts pour un magasinier', async () => {
    const calls = renderAt('/stock', {
      '/api/v1/auth/me': () => json(me(['stock.view', 'products.view'])),
      '/api/v1/warehouses': () =>
        json({
          data: [
            {
              id: '01JCXK8H0V3Q8T1Q4M7R2N5W6W',
              code: 'P',
              name: 'Dépôt principal',
              address: null,
              status: 'active',
              is_default: true,
            },
          ],
        }),
      '/api/v1/stock/positions': () =>
        json({
          data: [
            {
              warehouse_id: '01JCXK8H0V3Q8T1Q4M7R2N5W6W',
              variant,
              on_hand: 10,
              reserved: 7,
              blocked: 1,
              in_delivery: 0,
              available: 2,
              expected: 0,
              in_transit: 3,
              low_stock_threshold: 5,
              is_low: true,
            },
          ],
          meta,
        }),
    });

    const row = (await screen.findByRole('button', { name: 'T-shirt (TSH)' })).closest('tr');
    expect(row).not.toBeNull();
    const cells = within(row as HTMLElement)
      .getAllByRole('cell')
      .map((c) => c.textContent);
    expect(cells).toEqual(['T-shirt (TSH)', 'Dépôt principal', '10', '7', '1', '0', '2 faible', '3']);
    expect(calls.some((c) => c.includes('/stock/valuation'))).toBe(false);
  });

  it('place le curseur sur la ligne d’inventaire de l’article scanné', async () => {
    renderAt('/stock/inventaires/01JCXK8H0V3Q8T1Q4M7R2N5W6I', {
      '/api/v1/auth/me': () => json(me(['stock.view', 'inventories.create'])),
      '/api/v1/warehouses': () => json({ data: [] }),
      '/api/v1/inventories/01JCXK8H0V3Q8T1Q4M7R2N5W6I': () =>
        json({
          data: {
            id: '01JCXK8H0V3Q8T1Q4M7R2N5W6I',
            number: 'INV-2026-0001',
            warehouse_id: '01JCXK8H0V3Q8T1Q4M7R2N5W6W',
            scope: 'full',
            variant_ids: [],
            status: 'counting',
            snapshot_at: '2026-11-05T10:00:00Z',
            validated_at: null,
            adjustment_id: null,
            note: null,
            version: 2,
            created_at: '2026-11-05T09:00:00Z',
            lines: [
              {
                id: '01JCXK8H0V3Q8T1Q4M7R2N5W6L',
                variant,
                lot_id: null,
                lot_number: 'LOT-2026-000001',
                qty_expected: 10,
                qty_moved_since_snapshot: 0,
                qty_counted: null,
                difference: null,
                counted_at: null,
                note: null,
              },
            ],
          },
        }),
      '/api/v1/product-variants/lookup': () =>
        json({
          data: {
            variant: {
              ...variant,
              is_default: true,
              pieces_per_carton: null,
              low_stock_threshold: null,
              status: 'active',
              attribute_value_ids: [],
              units: [],
            },
            unit: {
              id: 'u',
              variant_id: variant.id,
              code: 'piece',
              label: 'pièce',
              factor: 1,
              barcode: '123',
              is_sellable: true,
              locked: false,
            },
            product: { id: variant.product_id, reference: 'TSH', name: 'T-shirt', status: 'active' },
          },
        }),
    });
    const user = userEvent.setup();

    await user.type(await screen.findByLabelText('Code-barres'), '123{Enter}');

    expect(screen.getByLabelText('Quantité comptée T-shirt (TSH)')).toHaveFocus();
  });
});
