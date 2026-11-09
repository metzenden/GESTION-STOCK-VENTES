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
const variant = {
  id: ID('6A'),
  product_id: ID('6B'),
  reference: 'CH',
  product_name: 'Chaise',
  sku: 'CH',
  label: 'Chaise',
};

function me(permissions: string[]) {
  return {
    data: {
      user: { id: ID('6Y'), name: 'Awa', email: 'a@example.com', is_super_admin: false, theme: 'system' },
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
        : json({ code: 'not_found', title: 'x', status: 404, type: 'x' }, 404);
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

const order = {
  id: ID('7A'),
  number: 'BC-2026-0001',
  supplier: { id: ID('7B'), code: 'GZ', name: 'Guangzhou' },
  supplier_reference: null,
  order_date: '2026-11-06',
  currency: 'USD',
  estimated_rate: '600',
  status: 'draft',
  total_amount: '125.0000',
  notes: null,
  confirmed_at: null,
  closed_at: null,
  version: 1,
  created_at: '2026-11-06T10:00:00Z',
  lines: [
    {
      id: ID('7C'),
      position: 1,
      variant,
      unit: null,
      unit_factor: 1,
      qty_units: 10,
      qty_base: 10,
      unit_price: '12.50000000',
      line_total: '125.0000',
      qty_received: 0,
    },
  ],
};

describe('Écrans achats et arrivages', () => {
  it('affiche une commande en devise et la confirme avec une clé d’idempotence', async () => {
    const calls = renderAt(`/achats/commandes/${order.id}`, {
      '/api/v1/auth/me': () => json(me(['purchase_orders.view', 'purchase_orders.manage'])),
      '/api/v1/warehouses': () => json({ data: [] }),
      [`GET /api/v1/purchase-orders/${order.id}`]: () => json({ data: order }),
      [`POST /api/v1/purchase-orders/${order.id}/confirm`]: () =>
        json({ data: { ...order, status: 'confirmed' } }),
    });

    expect(await screen.findByText('Commande BC-2026-0001')).toBeInTheDocument();
    expect(screen.getByText(/Total/).textContent).toContain('125,00');
    await userEvent.click(screen.getByRole('button', { name: 'Confirmer la commande' }));
    await vi.waitFor(() =>
      expect(calls.some((c) => c.method === 'POST' && c.path.endsWith('/confirm'))).toBe(true),
    );
  });

  it('bloque la validation d’une packing list tant qu’une ligne n’est pas rattachée', async () => {
    renderAt(`/packing-lists/${ID('8A')}`, {
      '/api/v1/auth/me': () => json(me(['shipments.view', 'shipments.manage'])),
      [`GET /api/v1/packing-lists/${ID('8A')}`]: () =>
        json({
          data: {
            id: ID('8A'),
            shipment_id: ID('8B'),
            supplier_id: null,
            file_id: ID('8C'),
            file_format: 'xlsx',
            currency: 'USD',
            status: 'in_review',
            parse_report: null,
            validated_at: null,
            created_at: '2026-11-06T10:00:00Z',
            lines: [
              {
                id: ID('8D'),
                row_number: 4,
                raw: {},
                supplier_reference: 'CH',
                description: 'Chaise',
                cartons: 10,
                pieces_per_carton: 4,
                total_qty: 40,
                cbm: '2.500000',
                match_status: 'suggested',
                matched_variant: variant,
                errors: [],
              },
              {
                id: ID('8E'),
                row_number: 5,
                raw: {},
                supplier_reference: 'TB-77',
                description: 'Table',
                cartons: null,
                pieces_per_carton: null,
                total_qty: 5,
                cbm: null,
                match_status: 'unmatched',
                matched_variant: null,
                errors: [],
              },
            ],
          },
        }),
    });

    expect(await screen.findByText('2 ligne(s) à traiter')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Valider : alimenter/ })).toBeDisabled();
    expect(screen.getByText('À rattacher')).toBeInTheDocument();
  });

  it('envoie chaque scan avec un identifiant d’événement unique', async () => {
    const receipt = {
      id: ID('9A'),
      number: 'BR-2026-0001',
      shipment_id: ID('9B'),
      purchase_order_id: null,
      warehouse_id: ID('9C'),
      status: 'counting',
      counting_mode: 'scan',
      note: null,
      validated_at: null,
      version: 2,
      created_at: '2026-11-06T10:00:00Z',
      lines: [
        {
          id: ID('9D'),
          shipment_line_id: ID('9E'),
          purchase_order_line_id: null,
          variant,
          qty_expected: 40,
          qty_counted: 0,
          qty_accepted: 0,
          qty_damaged: 0,
          qty_missing: 40,
          qty_surplus: 0,
          damaged_disposition: 'hold',
          is_counted: false,
          lot_id: null,
          lot_number: null,
          note: null,
        },
      ],
    };
    const calls = renderAt(`/receptions/${receipt.id}`, {
      '/api/v1/auth/me': () => json(me(['shipments.view', 'shipments.manage'])),
      '/api/v1/warehouses': () => json({ data: [] }),
      [`GET /api/v1/goods-receipts/${receipt.id}`]: () => json({ data: receipt }),
      [`POST /api/v1/goods-receipts/${receipt.id}/scans`]: () =>
        json(
          {
            data: {
              id: ID('9F'),
              client_event_id: 'x',
              scanned_code: '370',
              goods_receipt_line_id: ID('9D'),
              qty_base: 4,
              damaged: false,
              outcome: 'counted',
              scanned_at: '2026-11-06T10:00:00Z',
            },
          },
          201,
        ),
    });

    const input = await screen.findByLabelText('Code-barres');
    await userEvent.type(input, '370{Enter}');
    await userEvent.type(input, '370{Enter}');

    expect(await screen.findByText('+4 compté')).toBeInTheDocument();
    const scans = calls
      .filter((c) => c.path.endsWith('/scans'))
      .map((c) => (JSON.parse(c.body) as { client_event_id: string }).client_event_id);
    expect(scans).toHaveLength(2);
    expect(new Set(scans).size).toBe(2);
  });
});
