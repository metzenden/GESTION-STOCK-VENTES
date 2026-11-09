<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Sales\Infrastructure\Models\Customer;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Database\ConnectionInterface;

/** Clients (L4-01) : grille par défaut, délai de paiement, recherche par téléphone. */
final class CustomerService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly VariantCatalog $catalog,
    ) {}

    /** Téléphone normalisé en E.164 ; un numéro sénégalais à 9 chiffres reçoit l'indicatif +221. */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        if (preg_match('/^[37]\d{8}$/', $digits) === 1) {
            return '+221'.$digits;
        }

        if (preg_match('/^221\d{9}$/', $digits) === 1) {
            return '+'.$digits;
        }

        return $digits;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?string $actorId): Customer
    {
        return $this->db->transaction(function () use ($data, $actorId) {
            $this->assertPriceList($data['default_price_list_id'] ?? null);
            $code = isset($data['code']) && $data['code'] !== '' ? (string) $data['code'] : $this->nextCode();

            if (Customer::query()->where('code', $code)->exists()) {
                throw BusinessRuleViolation::invalidField('code', 'Ce code client est déjà utilisé.', 'duplicate');
            }

            $customer = new Customer;
            $customer->fill(['code' => $code, 'phone' => self::normalizePhone($data['phone'] ?? null)] + $data + ['status' => 'active'])
                ->forceFill(['code' => $code, 'phone' => self::normalizePhone($data['phone'] ?? null), 'created_by' => $actorId])->save();

            return $customer->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data, ?string $actorId): Customer
    {
        return $this->db->transaction(function () use ($id, $data, $actorId) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($id);

            if ($customer->is_walk_in && isset($data['status']) && $data['status'] !== 'active') {
                throw BusinessRuleViolation::invalidField('status', 'Le client comptoir ne peut pas être désactivé.');
            }

            if (array_key_exists('default_price_list_id', $data)) {
                $this->assertPriceList($data['default_price_list_id']);
            }

            if (array_key_exists('phone', $data)) {
                $data['phone'] = self::normalizePhone($data['phone']);
            }

            if (isset($data['code']) && Customer::query()->where('code', $data['code'])->whereKeyNot($customer->id)->exists()) {
                throw BusinessRuleViolation::invalidField('code', 'Ce code client est déjà utilisé.', 'duplicate');
            }

            $customer->fill($data)->forceFill(['updated_by' => $actorId])->save();

            return $customer;
        });
    }

    private function assertPriceList(mixed $id): void
    {
        if ($id !== null && $this->catalog->priceList((string) $id) === null) {
            throw BusinessRuleViolation::invalidField('default_price_list_id', 'Cette grille tarifaire n\'existe pas.');
        }
    }

    private function nextCode(): string
    {
        $max = Customer::query()->where('code', 'like', 'CL%')->lockForUpdate()->pluck('code')
            ->map(fn ($c) => (int) preg_replace('/\D/', '', (string) $c))->max() ?? 0;

        return sprintf('CL%05d', $max + 1);
    }
}
