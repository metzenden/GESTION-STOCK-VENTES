import { describe, expect, it } from 'vitest';
import { formatMoney, roundDecimalString } from '@/lib/money';

const nnbsp = ' ';
const nbsp = ' ';

describe('roundDecimalString (HALF_UP, D-013)', () => {
  it.each([
    ['1250.5', 0, '1251'],
    ['1250.4999', 0, '1250'],
    ['-1250.5', 0, '-1251'],
    ['10.005', 2, '10.01'],
    ['10.004', 2, '10.00'],
    ['-0.4', 0, '0'],
    ['100000', 0, '100000'],
  ])('%s à %i décimale(s) → %s', (value, decimals, expected) => {
    expect(roundDecimalString(value, decimals)).toBe(expected);
  });

  it('refuse un montant mal formé', () => {
    expect(() => roundDecimalString('1e3', 0)).toThrow();
  });
});

describe('formatMoney', () => {
  it('formate le franc CFA sans décimale avec séparateur de milliers', () => {
    expect(formatMoney({ amount: '100000', currency: 'XOF' })).toBe(`100${nnbsp}000${nbsp}FCFA`);
  });

  it("formate l'euro avec deux décimales et une virgule", () => {
    expect(formatMoney({ amount: '1234.5', currency: 'EUR' })).toBe(`1${nnbsp}234,50${nbsp}€`);
  });

  it('affiche un coût unitaire exact sur demande', () => {
    expect(formatMoney({ amount: '33333.33333333', currency: 'XOF' }, { exact: true })).toBe(
      `33${nnbsp}333,33333333${nbsp}FCFA`,
    );
  });

  it('gère les montants négatifs (avoirs)', () => {
    expect(formatMoney({ amount: '-20000', currency: 'XOF' })).toBe(`-20${nnbsp}000${nbsp}FCFA`);
  });
});
