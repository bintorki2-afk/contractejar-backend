<?php

namespace App\Http\Resources\Admin\V2\Api\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportOperationalMetricsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $waitingCount = (int) ($this->resource['waiting_count'] ?? $this->resource['pending_count'] ?? 0);
        $avgWait = (int) ($this->resource['avg_wait_seconds'] ?? $this->resource['avg_receive_seconds'] ?? 0);
        $longestWait = (int) ($this->resource['longest_wait_seconds'] ?? $this->resource['max_wait_seconds'] ?? 0);
        $lateOverSla = (int) ($this->resource['late_over_15m'] ?? $this->resource['late_over_15_count'] ?? 0);
        $lateOverLimit = (int) ($this->resource['late_over_30m'] ?? $this->resource['late_over_30_count'] ?? 0);
        $rawSla = $this->resource['sla_percent'] ?? $this->resource['sla_15m_percent'] ?? null;
        $slaPercent = $rawSla === null ? null : (int) $rawSla;

        return [
            'total_orders' => (int) ($this->resource['total_orders'] ?? 0),
            'waiting_count' => $waitingCount,
            'pending_count' => (int) ($this->resource['pending_count'] ?? $waitingCount),
            'avg_wait_seconds' => $avgWait,
            'avg_receive_seconds' => (int) ($this->resource['avg_receive_seconds'] ?? $avgWait),
            'longest_wait_seconds' => $longestWait,
            'max_wait_seconds' => (int) ($this->resource['max_wait_seconds'] ?? $longestWait),
            'longest_receive_seconds' => (int) ($this->resource['longest_receive_seconds'] ?? 0),
            'late_over_15m' => $lateOverSla,
            'late_over_15_count' => (int) ($this->resource['late_over_15_count'] ?? $lateOverSla),
            'late_over_30m' => $lateOverLimit,
            'late_over_30_count' => (int) ($this->resource['late_over_30_count'] ?? $lateOverLimit),
            'sla_percent' => $slaPercent,
            'sla_15m_percent' => $slaPercent,
            'unclaim_count' => (int) ($this->resource['unclaim_count'] ?? 0),
            'unreceive_count' => (int) ($this->resource['unreceive_count'] ?? 0),
            'delayed_over_24h_count' => (int) ($this->resource['delayed_over_24h_count'] ?? 0),
            'sla_minutes' => (int) ($this->resource['sla_minutes'] ?? 15),
        ];
    }
}
