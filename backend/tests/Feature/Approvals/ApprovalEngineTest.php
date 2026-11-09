<?php

declare(strict_types=1);

use App\Modules\Approvals\Infrastructure\Mail\ApprovalRequestedMail;
use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
| L6-01 à L6-03 : règles, demandes, décisions, effets provisoires, notifications (APR-001 à APR-011, TEST-015, TEST-016).
*/

function aprKey(): array
{
    return ['Idempotency-Key' => Str::random(24)];
}

function discountRule($admin, string $threshold = '10'): void
{
    loginAs($admin)->postJson('/api/v1/approval-rules', ['name' => 'Remise > '.$threshold.' %', 'operation' => 'discount', 'threshold_type' => 'percent', 'threshold_value' => $threshold])->assertCreated();
}

it('met en attente une commande trop remisée, puis l\'exécute à l\'approbation, une seule fois (TEST-015)', function () {
    Mail::fake();
    $ctx = salesSetup();
    $seller = userWithRole($ctx['company'], RoleTemplate::Seller);
    discountRule($ctx['admin']);

    $order = loginAs($seller)->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [
        ['variant_id' => $ctx['item']['variant'], 'qty_units' => 10, 'discount_type' => 'percent', 'discount_value' => '20'],
    ]])->assertCreated()->json('data');
    $submitted = loginAs($seller)->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], aprKey())->assertOk();
    $requestId = $submitted->json('meta.approval_request_id');

    expect($submitted->json('data.status'))->toBe('pending_approval')
        ->and($requestId)->not->toBeNull()
        ->and(position($ctx['wh'], $ctx['item']['variant'])['reserved'])->toBe(10)
        ->and(DB::table('stock_reservations')->where('company_id', $ctx['company']->id)->value('is_provisional'))->toBe(1)
        ->and(DB::table('invoices')->where('company_id', $ctx['company']->id)->count())->toBe(0)
        ->and(DB::table('notifications_inbox')->where('user_id', $ctx['admin']->id)->where('type', 'approval_requested')->count())->toBe(1);
    Mail::assertQueued(ApprovalRequestedMail::class);

    // Le vendeur ne peut pas décider ; l'administrateur approuve.
    loginAs($seller)->postJson("/api/v1/approvals/{$requestId}/approve", [], aprKey())->assertForbidden();
    loginAs($ctx['admin'])->postJson("/api/v1/approvals/{$requestId}/approve", ['comment' => 'Client fidèle'], aprKey())->assertOk()->assertJsonPath('data.status', 'approved');

    expect(DB::table('sales_orders')->where('id', $order['id'])->value('status'))->toBe('confirmed')
        ->and(DB::table('invoices')->where('company_id', $ctx['company']->id)->count())->toBe(1)
        ->and(DB::table('stock_reservations')->where('company_id', $ctx['company']->id)->value('is_provisional'))->toBe(0)
        ->and(DB::table('notifications_inbox')->where('user_id', $seller->id)->where('type', 'approval_decided')->count())->toBe(1);

    // Seconde décision : refusée, aucun second effet.
    loginAs($ctx['admin'])->postJson("/api/v1/approvals/{$requestId}/approve", [], aprKey())->assertStatus(409)->assertJsonPath('code', 'already_processed');
    expect(DB::table('invoices')->where('company_id', $ctx['company']->id)->count())->toBe(1);
});

it('rejette : réservations provisoires levées, commande de nouveau modifiable (TEST-016, APR-006)', function () {
    $ctx = salesSetup();
    $seller = userWithRole($ctx['company'], RoleTemplate::Seller);
    discountRule($ctx['admin']);
    $order = loginAs($seller)->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [
        ['variant_id' => $ctx['item']['variant'], 'qty_units' => 5, 'discount_type' => 'percent', 'discount_value' => '50'],
    ]])->json('data');
    $requestId = loginAs($seller)->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], aprKey())->json('meta.approval_request_id');

    loginAs($ctx['admin'])->postJson("/api/v1/approvals/{$requestId}/reject", ['comment' => 'Remise excessive'], aprKey())->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(DB::table('sales_orders')->where('id', $order['id'])->value('status'))->toBe('draft')
        ->and(position($ctx['wh'], $ctx['item']['variant'])['reserved'])->toBe(0);

    // Remise ramenée sous le seuil : confirmation directe.
    loginAs($seller)->patchJson("/api/v1/sales-orders/{$order['id']}", ['lines' => [['variant_id' => $ctx['item']['variant'], 'qty_units' => 5, 'discount_type' => 'percent', 'discount_value' => '5']]])->assertOk();
    loginAs($seller)->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], aprKey())->assertOk()->assertJsonPath('data.status', 'confirmed');
});

it('interdit l\'auto-approbation quand l\'entreprise le demande (APR-003, D-061)', function () {
    $ctx = salesSetup();
    discountRule($ctx['admin'], '0');
    DB::table('company_settings')->where('company_id', $ctx['company']->id)->update(['allow_self_approval' => false]);
    $order = loginAs($ctx['admin'])->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [
        ['variant_id' => $ctx['item']['variant'], 'qty_units' => 1, 'discount_type' => 'percent', 'discount_value' => '1'],
    ]])->json('data');
    $requestId = loginAs($ctx['admin'])->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], aprKey())->json('meta.approval_request_id');

    loginAs($ctx['admin'])->postJson("/api/v1/approvals/{$requestId}/approve", [], aprKey())->assertForbidden()->assertJsonPath('code', 'self_approval_forbidden');

    // Le demandeur peut retirer sa demande : effets levés.
    loginAs($ctx['admin'])->postJson("/api/v1/approvals/{$requestId}/withdraw", [], aprKey())->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(DB::table('sales_orders')->where('id', $order['id'])->value('status'))->toBe('draft');
});

it('approuve une correction financière : mouvement écrit à la décision seulement', function () {
    $ctx = salesSetup();
    $accountant = userWithRole($ctx['company'], RoleTemplate::Accountant);
    loginAs($ctx['admin'])->postJson('/api/v1/approval-rules', ['name' => 'Corrections', 'operation' => 'financial_correction', 'threshold_type' => 'amount', 'threshold_value' => '1000'])->assertCreated();
    $cash = account($ctx['company'], 'cash');

    $response = loginAs($accountant)->postJson('/api/v1/financial-corrections', ['financial_account_id' => $cash, 'direction' => 'in', 'amount' => '5000', 'reason' => 'Écart'], aprKey());
    $response->assertStatus(403); // le comptable n'a pas financial_accounts.manage par défaut

    $created = loginAs($ctx['admin'])->postJson('/api/v1/financial-corrections', ['financial_account_id' => $cash, 'direction' => 'in', 'amount' => '5000', 'reason' => 'Écart'], aprKey())->assertCreated();
    expect($created->json('data.status'))->toBe('pending_approval')
        ->and(DB::table('financial_movements')->where('financial_account_id', $cash)->count())->toBe(0);

    loginAs($ctx['admin'])->postJson('/api/v1/approvals/'.$created->json('meta.approval_request_id').'/approve', [], aprKey())->assertOk()->assertJsonPath('data.self_approved', true);
    expect(DB::table('financial_accounts')->where('id', $cash)->value('balance'))->toBe('5000.0000')
        ->and(DB::table('financial_corrections')->where('id', $created->json('data.id'))->value('status'))->toBe('posted');

    $small = loginAs($ctx['admin'])->postJson('/api/v1/financial-corrections', ['financial_account_id' => $cash, 'direction' => 'out', 'amount' => '500', 'reason' => 'Petit écart'], aprKey());
    expect($small->json('data.status'))->toBe('posted');
});

it('liste les notifications et les marque lues', function () {
    $ctx = salesSetup();
    $seller = userWithRole($ctx['company'], RoleTemplate::Seller);
    discountRule($ctx['admin']);
    $order = loginAs($seller)->postJson('/api/v1/sales-orders', ['customer_id' => $ctx['customer']['id'], 'lines' => [
        ['variant_id' => $ctx['item']['variant'], 'qty_units' => 1, 'discount_type' => 'percent', 'discount_value' => '30'],
    ]])->json('data');
    loginAs($seller)->postJson("/api/v1/sales-orders/{$order['id']}/submit", [], aprKey())->assertOk();

    $list = loginAs($ctx['admin'])->getJson('/api/v1/notifications')->assertOk();
    expect($list->json('meta.unread'))->toBe(1);
    loginAs($ctx['admin'])->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 1);
    expect(loginAs($ctx['admin'])->getJson('/api/v1/notifications')->json('meta.unread'))->toBe(0);
    expect(loginAs($seller)->getJson('/api/v1/approvals')->json('data'))->toHaveCount(1);
});
