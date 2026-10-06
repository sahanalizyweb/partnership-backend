<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Assignment;
use App\Models\Commission;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\PartnerCategory;
use App\Models\PartnerType;
use App\Models\Payment;
use App\Models\PerformanceMetric;
use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ReportController extends Controller
{
    use ScopesToRequester;

    protected const REPORTS = [
        'partners', 'partner-types', 'categories', 'locations', 'assignments',
        'commissions', 'payments', 'ledger', 'settlements', 'performance',
        'outstanding-balances', 'agreements',
    ];

    public function show(Request $request, string $type)
    {
        abort_unless(in_array($type, self::REPORTS, true), 404);

        return response()->json($this->data($request, $type));
    }

    public function export(Request $request, string $type)
    {
        abort_unless(in_array($type, self::REPORTS, true), 404);

        $rows = $this->data($request, $type);
        $filename = $type.'-'.now()->format('Ymd_His').'.csv';

        return Response::streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            if ($rows->isNotEmpty()) {
                fputcsv($handle, array_keys((array) $rows->first()));
            }
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, (array) $row));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Consolidated per-partner report bundle for the Partner Details > Report tab. */
    public function partnerReport(Request $request, Partner $partner)
    {
        return response()->json([
            'partner' => $partner->only(['id', 'name', 'partner_code', 'status']),
            'agreements' => $partner->agreements()->orderByDesc('id')->get(
                ['id', 'agreement_number', 'agreement_type', 'status', 'start_date', 'end_date']
            ),
            'commissions' => $partner->commissions()->orderByDesc('id')->limit(200)->get(
                ['id', 'base_amount', 'commission_amount', 'status', 'created_at']
            ),
            'payments' => $partner->payments()->orderByDesc('id')->limit(200)->get(
                ['id', 'payment_type', 'direction', 'amount', 'status', 'payment_date']
            ),
            'settlements' => $partner->settlements()->orderByDesc('id')->get(
                ['id', 'settlement_number', 'period_start', 'period_end', 'net_payable', 'status']
            ),
            'performance' => $partner->performanceMetrics()->orderByDesc('period')->get(),
        ]);
    }

    /** CSV export of one section of a partner's report (?type=agreements|commissions|payments|settlements|performance). */
    public function partnerExport(Request $request, Partner $partner)
    {
        $type = $request->string('type', 'commissions')->toString();

        $rows = match ($type) {
            'agreements' => $partner->agreements()->orderByDesc('id')->get()
                ->map(fn ($a) => [
                    'agreement_number' => $a->agreement_number, 'type' => $a->agreement_type,
                    'status' => $a->status, 'start_date' => $a->start_date, 'end_date' => $a->end_date,
                ]),
            'payments' => $partner->payments()->orderByDesc('id')->get()
                ->map(fn ($p) => [
                    'type' => $p->payment_type, 'direction' => $p->direction, 'amount' => $p->amount,
                    'status' => $p->status, 'payment_date' => $p->payment_date,
                ]),
            'settlements' => $partner->settlements()->orderByDesc('id')->get()
                ->map(fn ($s) => [
                    'settlement_number' => $s->settlement_number, 'period_start' => $s->period_start,
                    'period_end' => $s->period_end, 'net_payable' => $s->net_payable, 'status' => $s->status,
                ]),
            'performance' => $partner->performanceMetrics()->orderByDesc('period')->get()
                ->map(fn ($m) => [
                    'period' => $m->period, 'conversions' => $m->conversions, 'conversion_rate' => $m->conversion_rate,
                    'completion_rate' => $m->completion_rate, 'commission_generated' => $m->commission_generated, 'rank' => $m->rank,
                ]),
            default => $partner->commissions()->orderByDesc('id')->get()
                ->map(fn ($c) => [
                    'base_amount' => $c->base_amount, 'commission_amount' => $c->commission_amount,
                    'status' => $c->status, 'created_at' => $c->created_at,
                ]),
        };

        $filename = $partner->partner_code.'-'.$type.'-'.now()->format('Ymd_His').'.csv';

        return Response::streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            if ($rows->isNotEmpty()) {
                fputcsv($handle, array_keys((array) $rows->first()));
            }
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, (array) $row));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    protected function data(Request $request, string $type)
    {
        return match ($type) {
            'partners' => $this->scopeBusiness(Partner::with('partnerType', 'partnerCategory'), $request)
                ->get()->map(fn (Partner $p) => [
                    'partner_code' => $p->partner_code, 'name' => $p->name, 'type' => $p->partnerType?->name,
                    'category' => $p->partnerCategory?->name, 'status' => $p->status, 'phone' => $p->phone,
                    'created_at' => $p->created_at,
                ]),
            'partner-types' => PartnerType::withCount('partners')->get()
                ->map(fn ($t) => ['name' => $t->name, 'partners_count' => $t->partners_count, 'status' => $t->status]),
            'categories' => PartnerCategory::withCount('partners')->with('partnerType:id,name')->get()
                ->map(fn ($c) => ['name' => $c->name, 'partner_type' => $c->partnerType?->name, 'partners_count' => $c->partners_count]),
            'locations' => \App\Models\Location::withCount('children')->get()
                ->map(fn ($l) => ['type' => $l->type, 'name' => $l->name, 'children_count' => $l->children_count]),
            'assignments' => $this->scopeBusiness(Assignment::with('partner:id,name'), $request)->get()
                ->map(fn ($a) => [
                    'title' => $a->title, 'type' => $a->assignment_type, 'partner' => $a->partner?->name,
                    'status' => $a->status, 'assigned_date' => $a->assigned_date, 'transaction_amount' => $a->transaction_amount,
                ]),
            'commissions' => $this->scopeBusiness(Commission::with('partner:id,name'), $request)->get()
                ->map(fn ($c) => [
                    'partner' => $c->partner?->name, 'base_amount' => $c->base_amount,
                    'commission_amount' => $c->commission_amount, 'status' => $c->status, 'created_at' => $c->created_at,
                ]),
            'payments' => $this->scopeBusiness(Payment::with('partner:id,name'), $request)->get()
                ->map(fn ($p) => [
                    'partner' => $p->partner?->name, 'type' => $p->payment_type, 'direction' => $p->direction,
                    'amount' => $p->amount, 'status' => $p->status, 'payment_date' => $p->payment_date,
                ]),
            'ledger' => $this->scopeBusiness(LedgerEntry::with('partner:id,name'), $request)->orderBy('entry_date')->get()
                ->map(fn ($l) => [
                    'partner' => $l->partner?->name, 'date' => $l->entry_date, 'type' => $l->transaction_type,
                    'debit' => $l->debit, 'credit' => $l->credit, 'running_balance' => $l->running_balance,
                ]),
            'settlements' => $this->scopeBusiness(Settlement::with('partner:id,name'), $request)->get()
                ->map(fn ($s) => [
                    'partner' => $s->partner?->name, 'period_start' => $s->period_start, 'period_end' => $s->period_end,
                    'net_payable' => $s->net_payable, 'status' => $s->status,
                ]),
            'performance' => $this->scopeBusiness(PerformanceMetric::with('partner:id,name'), $request)->get()
                ->map(fn ($m) => [
                    'partner' => $m->partner?->name, 'period' => $m->period, 'conversions' => $m->conversions,
                    'conversion_rate' => $m->conversion_rate, 'commission_generated' => $m->commission_generated, 'rank' => $m->rank,
                ]),
            'outstanding-balances' => $this->scopeBusiness(Partner::query(), $request)->get()
                ->map(fn (Partner $p) => ['partner_code' => $p->partner_code, 'name' => $p->name, 'balance' => $p->currentBalance()])
                ->filter(fn ($row) => $row['balance'] != 0)->values(),
            'agreements' => $this->scopeBusiness(Agreement::with('partner:id,name'), $request)->get()
                ->map(fn ($a) => [
                    'agreement_number' => $a->agreement_number, 'partner' => $a->partner?->name,
                    'status' => $a->status, 'start_date' => $a->start_date, 'end_date' => $a->end_date,
                ]),
            default => collect(),
        };
    }
}
