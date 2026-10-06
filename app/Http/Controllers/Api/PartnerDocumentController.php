<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\Partner;
use App\Models\PartnerDocument;
use Illuminate\Http\Request;

class PartnerDocumentController extends Controller
{
    public function index(Request $request, Partner $partner)
    {
        return $partner->documents()->with('documentType', 'verifiedBy')->orderByDesc('id')->get();
    }

    public function store(Request $request, Partner $partner)
    {
        $data = $request->validate([
            'document_type' => 'required|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'file' => 'required|file|max:10240',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
        ]);

        // Document Type is a free-text field on upload rather than a fixed dropdown;
        // reuse an existing type of the same name (case-insensitive) or create one.
        $documentType = DocumentType::firstOrCreate(
            ['name' => trim($data['document_type'])],
            ['category' => 'other', 'status' => 'active']
        );

        $path = $request->file('file')->store("partner-documents/{$partner->id}", 'local');

        $document = $partner->documents()->create([
            'document_type_id' => $documentType->id,
            'document_number' => $data['document_number'] ?? null,
            'file_path' => $path,
            'issue_date' => $data['issue_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'verification_status' => 'pending',
        ]);

        return response()->json($document->load('documentType'), 201);
    }

    public function verify(Request $request, PartnerDocument $document)
    {
        $data = $request->validate(['notes' => 'nullable|string']);

        $document->update([
            'verification_status' => 'verified',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'notes' => $data['notes'] ?? $document->notes,
        ]);

        if ($document->partner->status === 'applied') {
            $document->partner->update(['status' => 'verification_pending']);
        }

        return $document->load('documentType', 'verifiedBy');
    }

    public function reject(Request $request, PartnerDocument $document)
    {
        $data = $request->validate(['notes' => 'nullable|string']);

        $document->update([
            'verification_status' => 'rejected',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'notes' => $data['notes'] ?? $document->notes,
        ]);

        return $document->load('documentType', 'verifiedBy');
    }

    public function destroy(PartnerDocument $document)
    {
        $document->delete();

        return response()->json(null, 204);
    }
}
