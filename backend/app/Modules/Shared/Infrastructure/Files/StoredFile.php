<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Files;

use App\Modules\Shared\Domain\Enums\FilePurpose;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property FilePurpose $purpose
 */
class StoredFile extends Model
{
    use HasUlidKey;

    protected $table = 'files';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purpose' => FilePurpose::class,
            'size_bytes' => 'integer',
        ];
    }
}
