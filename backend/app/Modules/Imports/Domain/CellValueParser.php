<?php

declare(strict_types=1);

namespace App\Modules\Imports\Domain;

/**
 * Conversion des cellules saisies par des fournisseurs (formats français et anglo-saxons) :
 * « 1 250,50 », « 1,250.50 », « 0,125 » ; aucune conversion approximative (pas de float).
 */
final class CellValueParser
{
    /** @return array{0: string|null, 1: string|null} [valeur décimale normalisée, erreur] */
    public static function decimal(?string $value, int $maxDecimals = 8): array
    {
        $value = self::clean($value);

        if ($value === null) {
            return [null, null];
        }

        $value = str_replace(["\u{00A0}", "\u{202F}", ' ', "'"], '', $value);
        $value = preg_replace('/[^\d.,\-]/u', '', $value) ?? '';
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimalSeparator === ',' ? '.' : ',';
            $value = str_replace($thousands, '', $value);
            $value = str_replace($decimalSeparator, '.', $value);
        } elseif ($lastComma !== false) {
            $value = substr_count($value, ',') > 1 ? str_replace(',', '', $value) : str_replace(',', '.', $value);
        } elseif ($lastDot !== false && substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }

        if (preg_match('/^-?\d{1,15}(\.\d+)?$/', $value) !== 1) {
            return [null, 'Nombre illisible.'];
        }

        if (str_contains($value, '.')) {
            [$int, $dec] = explode('.', $value, 2);
            $dec = rtrim($dec, '0');

            if (strlen($dec) > $maxDecimals) {
                return [null, "Plus de {$maxDecimals} décimales."];
            }

            $value = $dec === '' ? $int : "{$int}.{$dec}";
        }

        return [$value, null];
    }

    /** @return array{0: int|null, 1: string|null} */
    public static function integer(?string $value): array
    {
        [$decimal, $error] = self::decimal($value, 0);

        if ($error !== null) {
            return [null, str_contains($error, 'décimales') ? 'Un nombre entier est attendu.' : $error];
        }

        return [$decimal === null ? null : (int) $decimal, null];
    }

    public static function text(?string $value, int $max = 255): ?string
    {
        $value = self::clean($value);

        return $value === null ? null : mb_substr(preg_replace('/\s+/u', ' ', $value) ?? $value, 0, $max);
    }

    /** Forme comparable d'un en-tête : minuscules, sans accents ni ponctuation. */
    public static function normalizeHeader(string $header): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header);

        return preg_replace('/[^a-z0-9]/', '', strtolower($ascii === false ? $header : $ascii)) ?? '';
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
