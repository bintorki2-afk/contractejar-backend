<?php

namespace App\Http\Resources\Admin\V2\Api\Reports;

use Illuminate\Http\Request;

class PerformanceReportResource extends ReportJsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge($this->periodFields(), [
            'period_label' => $this->resource['period_label'] ?? null,
            'kpis' => $this->resolveItem($this->resource['kpis'] ?? [], PerformanceKpisResource::class),
            'conversion_funnel' => $this->resolveList($this->resource['conversion_funnel'] ?? [], ReportLabeledValueResource::class),
            'conversion_leakage' => $this->resolveItem($this->resource['conversion_leakage'] ?? [], PerformanceConversionLeakageResource::class),
            'funnel_summary' => $this->resource['funnel_summary'] ?? null,
            'conversion_rates' => $this->resolveList($this->resource['conversion_rates'] ?? [], ReportLabeledValueResource::class),
            'daily_orders' => $this->resolveList($this->resource['daily_orders'] ?? [], ReportLabeledValueResource::class),
            'orders_by_status' => $this->resolveList($this->resource['orders_by_status'] ?? [], ReportLabeledValueResource::class),
            'by_contract_type' => $this->resolveList($this->resource['by_contract_type'] ?? [], ReportLabeledValueResource::class),
            'by_employee' => $this->resolveList($this->resource['by_employee'] ?? [], ReportEmployeeStatResource::class),
            'operational_metrics' => $this->resolveItem($this->resource['operational_metrics'] ?? [], ReportOperationalMetricsResource::class),
            'revenue_by_payment_method' => $this->resolveList($this->resource['revenue_by_payment_method'] ?? [], ReportPaymentMethodResource::class),
            'pnl' => $this->resolveList($this->resource['pnl'] ?? [], ReportLabeledValueResource::class),
            'unit_economics' => $this->resolveList($this->resource['unit_economics'] ?? [], ReportUnitEconomicsResource::class),
            'unit_economics_note' => $this->resource['unit_economics_note'] ?? null,
            'financial_summary' => $this->resolveList($this->resource['financial_summary'] ?? [], ReportLabeledValueResource::class),
            'by_document_type' => $this->resolveList($this->resource['by_document_type'] ?? [], ReportLabeledValueResource::class),
            'correction_errors' => $this->resolveList($this->resource['correction_errors'] ?? [], ReportLabeledValueResource::class),
            'refund_requests_by_status' => $this->resolveList($this->resource['refund_requests_by_status'] ?? [], ReportLabeledValueResource::class),
            'refund_requests_total' => $this->resource['refund_requests_total'] ?? 0,
        ]);
    }
}
