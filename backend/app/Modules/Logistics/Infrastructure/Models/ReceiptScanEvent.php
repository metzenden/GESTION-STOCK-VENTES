<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $goods_receipt_id
 * @property string|null $goods_receipt_line_id
 * @property string $scanned_code
 * @property string|null $resolved_unit_id
 * @property int $qty_base
 * @property bool $damaged
 * @property string $outcome
 * @property string $scanned_by
 * @property CarbonImmutable $scanned_at
 * @property string $client_event_id
 */
class ReceiptScanEvent extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'receipt_scan_events';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['qty_base' => 'integer', 'damaged' => 'boolean', 'scanned_at' => 'immutable_datetime'];
    }
}
