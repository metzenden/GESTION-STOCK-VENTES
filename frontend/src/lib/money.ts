// Formatage des montants reçus de l'API (chaînes décimales, D-013).
// Aucun calcul en virgule flottante : l'arrondi d'affichage est fait sur des entiers (BigInt).

export interface MoneyValue {
  amount: string;
  currency: string;
}

const DECIMALS: Record<string, number> = { XOF: 0, EUR: 2, USD: 2, CNY: 2 };
const SYMBOLS: Record<string, string> = { XOF: 'FCFA', EUR: '€', USD: '$', CNY: '¥' };

const NARROW_NBSP = ' ';
const NBSP = ' ';
const DECIMAL_PATTERN = /^(-)?(\d+)(?:\.(\d+))?$/;

export function currencyDecimals(currency: string): number {
  return DECIMALS[currency] ?? 2;
}

/** Arrondit une chaîne décimale au demi supérieur (HALF_UP), comme le serveur. */
export function roundDecimalString(value: string, decimals: number): string {
  const match = DECIMAL_PATTERN.exec(value.trim());
  if (match === null) {
    throw new Error(`Montant invalide : « ${value} »`);
  }

  const [, sign = '', integer = '0', fraction = ''] = match;
  const digits = BigInt(integer + fraction.padEnd(decimals + 1, '0').slice(0, decimals + 1));
  // digits porte une décimale de plus que la précision voulue : on arrondit sur ce dernier chiffre.
  const rounded = (digits + 5n) / 10n;
  const text = rounded.toString().padStart(decimals + 1, '0');
  const intPart = decimals > 0 ? text.slice(0, -decimals) : text;
  const fracPart = decimals > 0 ? text.slice(-decimals) : '';
  const isZero = rounded === 0n;

  return `${sign && !isZero ? '-' : ''}${intPart}${fracPart ? `.${fracPart}` : ''}`;
}

function groupThousands(integer: string): string {
  return integer.replace(/\B(?=(\d{3})+(?!\d))/g, NARROW_NBSP);
}

/** « 100000 » XOF → « 100 000 FCFA » ; « 1234.5 » EUR → « 1 234,50 € ». */
export function formatMoney(money: MoneyValue, options: { exact?: boolean } = {}): string {
  const decimals = currencyDecimals(money.currency);
  const value = options.exact ? money.amount : roundDecimalString(money.amount, decimals);
  const negative = value.startsWith('-');
  const [integer = '0', fraction] = (negative ? value.slice(1) : value).split('.');
  const body = groupThousands(integer) + (fraction ? `,${fraction}` : '');
  const symbol = SYMBOLS[money.currency] ?? money.currency;

  return `${negative ? '-' : ''}${body}${NBSP}${symbol}`;
}
