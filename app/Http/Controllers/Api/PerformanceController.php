<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\PerformanceMetric;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    use ScopesToRequester;

    public function index(Request $request)
    {
        $query = PerformanceMetric::with('partner');
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        if ($request->filled('period')) {
            $query->where('period', $request->string('period'));
        }
        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->integer('partner_id'));
        }

        return $query->orderByDesc('period')->orderBy('rank')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'partner_id' => 'required|exists:partners,id',
            'period' => 'required|string|max:20',
            'leads_received' => 'nullable|integer|min:0',
            'leads_contacted' => 'nullable|integer|min:0',
            'conversions' => 'nullable|integer|min:0',
            'sales_value' => 'nullable|numeric|min:0',
            'assignments_completed' => 'nullable|integer|min:0',
            'response_time_avg' => 'nullable|numeric|min:0',
            'completion_rate' => 'nullable|numeric|min:0|max:100',
            'avg_rating' => 'nullable|numeric|min:0|max:5',
            'complaints_count' => 'nullable|integer|min:0',
            'commission_generated' => 'nullable|numeric|min:0',
        ]);

        $partner = \App\Models\Partner::findOrFail($data['partner_id']);
        $data['conversion_rate'] = ($data['leads_contacted'] ?? 0) > 0
            ? round((($data['conversions'] ?? 0) / $data['leads_contacted']) * 100, 2)
            : 0;

        $metric = PerformanceMetric::updateOrCreate(
            ['partner_id' => $data['partner_id'], 'period' => $data['period']],
            $data + ['business_id' => $partner->business_id]
        );

        $this->recomputeRanks($partner->business_id, $data['period']);

        return response()->json($metric->fresh(), 201);
    }

    public function show(PerformanceMetric $performanceMetric)
    {
        return $performanceMetric->load('partner');
    }

    protected function recomputeRanks(int $businessId, string $period): void
    {
        $metrics = PerformanceMetric::where('business_id', $businessId)
            ->where('period', $period)
            ->orderByDesc('commission_generated')
            ->get();

        foreach ($metrics as $index => $metric) {
            $metric->update(['rank' => $index + 1]);
        }
    }
}
