<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    public function index()
    {
        return DocumentType::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:identity,business,license,tax,bank,agreement,other',
            'requires_expiry' => 'boolean',
            'is_mandatory' => 'boolean',
            'status' => 'in:active,inactive',
        ]);

        return response()->json(DocumentType::create($data), 201);
    }

    public function show(DocumentType $documentType)
    {
        return $documentType;
    }

    public function update(Request $request, DocumentType $documentType)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|in:identity,business,license,tax,bank,agreement,other',
            'requires_expiry' => 'boolean',
            'is_mandatory' => 'boolean',
            'status' => 'in:active,inactive',
        ]);

        $documentType->update($data);

        return $documentType;
    }

    public function destroy(DocumentType $documentType)
    {
        $documentType->delete();

        return response()->json(null, 204);
    }
}
