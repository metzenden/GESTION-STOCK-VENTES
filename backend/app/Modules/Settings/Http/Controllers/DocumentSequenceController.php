<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Infrastructure\Sequences\DocumentSequenceFormats;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Préfixes et largeurs de numérotation ; le compteur n'est jamais modifiable (docs/05 §2.2). */
final class DocumentSequenceController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DocumentSequenceFormats $formats,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->formats->all($this->tenant->requireCompanyId())]);
    }

    public function update(Request $request, CompanyDirectory $companies, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'formats' => ['required', 'array', 'min:1', 'max:'.count(DocumentType::cases())],
            'formats.*.doc_type' => ['required', 'distinct', Rule::enum(DocumentType::class)],
            'formats.*.prefix' => ['required', 'string', 'regex:/^[A-Z0-9]{1,12}$/'],
            'formats.*.padding' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $companyId = $this->tenant->requireCompanyId();
        $timezone = $companies->find($companyId)->timezone ?? 'Africa/Dakar';
        $year = (int) Carbon::now($timezone)->format('Y');
        $before = collect($this->formats->all($companyId))->keyBy('doc_type');

        foreach ($validated['formats'] as $format) {
            $type = DocumentType::from($format['doc_type']);
            $previous = $before->get($type->value);

            if ($previous !== null && $previous['prefix'] === $format['prefix'] && $previous['padding'] === (int) $format['padding']) {
                continue;
            }

            $this->formats->update($companyId, $type, $format['prefix'], (int) $format['padding'], $year, $request->user()?->getAuthIdentifier());
            $audit->record('document_sequence.format_changed', 'document_sequence_format', null, $previous ?? [], $format);
        }

        return new JsonResponse(['data' => $this->formats->all($companyId)]);
    }
}
