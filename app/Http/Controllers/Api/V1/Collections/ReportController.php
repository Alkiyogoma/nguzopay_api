<?php

namespace App\Http\Controllers\Api\V1\Collections;

use App\Domain\Business\Models\Business;
use App\Domain\Collections\Reports\CustomersReport;
use App\Domain\Collections\Reports\GroupsReport;
use App\Domain\Collections\Reports\InvoicesReport;
use App\Domain\Collections\Reports\PaymentsReport;
use App\Domain\Collections\Reports\Report;
use App\Domain\Collections\Reports\RequestsReport;
use App\Domain\Collections\Reports\SettlementsReport;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Print (JSON for the browser's print view) and Excel export of any list page,
 * with the same filters as the page.
 */
class ReportController extends Controller
{
    public const REPORTS = [
        'customers' => CustomersReport::class,
        'groups' => GroupsReport::class,
        'invoices' => InvoicesReport::class,
        'payments' => PaymentsReport::class,
        'requests' => RequestsReport::class,
        'settlements' => SettlementsReport::class,
    ];

    public static function make(string $key): Report
    {
        abort_unless(isset(self::REPORTS[$key]), 404);

        return app(self::REPORTS[$key]);
    }

    public function export(Request $request, Business $business, string $report, AuditLogger $audit): Response
    {
        $this->authorize('viewPayments', $business);
        $definition = self::make($report);
        $format = $request->validate(['format' => ['nullable', 'in:xlsx,json']])['format'] ?? 'xlsx';
        $filters = $definition->filters($request);

        $query = $definition->query($business, $filters);
        $summary = $definition->summary(clone $query, $business);
        $total = (clone $query)->reorder()->count();
        $columns = $definition->columns();
        $rows = (clone $query)->limit(Report::EXPORT_LIMIT)->get()->map(fn ($m) => $definition->row($m));

        $audit->record('report.exported', $business, ['report' => $report, 'format' => $format, 'rows' => $rows->count()]);

        $meta = [
            'title' => $definition->title(),
            'business' => $business->displayName(),
            'merchant_code' => $business->code,
            'generated_at' => now(config('nguzopay.settlement.timezone'))->format('j M Y, H:i'),
            'filters' => $definition->filterLabels($filters),
            'total' => $total,
            'truncated' => $total > $rows->count(),
        ];

        if ($format === 'json') {
            return response()->json(['data' => $meta + [
                'summary' => array_map(fn ($c) => $c + ['display' => Report::display($c['value'], $c['format'])], $summary),
                'columns' => array_map(fn ($c, $key) => ['key' => $key, 'label' => $c[0], 'format' => $c[1]], $columns, array_keys($columns)),
                'rows' => $rows->map(fn ($r) => collect($r)->map(fn ($v, $k) => Report::display($v, $columns[$k][1]))->all())->all(),
            ]]);
        }

        return $this->xlsx($meta, $summary, $columns, $rows->all());
    }

    private function xlsx(array $meta, array $summary, array $columns, array $rows): Response
    {
        $keys = array_keys($columns);
        $money = array_keys(array_filter(array_values($columns), fn ($c) => in_array($c[1], ['money', 'number'], true)));
        $sheet = new XlsxWriter($meta['title']);

        $sheet->row([$meta['title'].' · '.$meta['business']], XlsxWriter::TITLE)
            ->row(['Generated '.$meta['generated_at'].($meta['filters'] ? ' · '.implode(' · ', $meta['filters']) : '')], XlsxWriter::MUTED)
            ->row([]);

        foreach ($summary as $card) {
            $sheet->row([$card['label'], $card['format'] === 'percent' ? Report::display($card['value'], 'percent') : $card['value']], XlsxWriter::NORMAL, [1]);
        }
        $sheet->row([])->row(array_map(fn ($c) => $c[0], array_values($columns)), XlsxWriter::BOLD);

        foreach ($rows as $row) {
            $sheet->row(array_map(function ($key) use ($row, $columns) {
                $value = $row[$key] ?? null;

                return match ($columns[$key][1]) {
                    'money', 'number' => $value === null ? null : (int) $value,
                    'date', 'datetime' => $value ? Report::display($value, $columns[$key][1]) : null,
                    default => $value,
                };
            }, $keys), XlsxWriter::NORMAL, $money);
        }

        if ($meta['truncated']) {
            $sheet->row([])->row(["Showing the first {$this->limit()} of {$meta['total']} rows. Narrow the filters to export the rest."], XlsxWriter::MUTED);
        }

        $path = tempnam(sys_get_temp_dir(), 'np-export-');
        $sheet->save($path);
        $name = Str::slug($meta['business'].' '.$meta['title']).'-'.now()->format('Y-m-d').'.xlsx';

        return response()->download($path, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private function limit(): string
    {
        return number_format(Report::EXPORT_LIMIT);
    }
}
