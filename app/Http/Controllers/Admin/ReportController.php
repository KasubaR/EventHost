<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminReportStatusRequest;
use App\Models\Report;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $reports = Report::query()
            ->with(['user:id,name,email', 'event:id,name'])
            ->when(is_string($status) && $status !== '', function ($q) use ($status): void {
                $q->where('status', $status);
            })
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Report::STATUS_PENDING])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.reports.index', [
            'reports' => $reports,
            'filterStatus' => is_string($status) ? $status : '',
        ]);
    }

    public function show(Report $report): View
    {
        $report->load(['user:id,name,email', 'event:id,name,user_id', 'event.user:id,name,email']);

        return view('admin.reports.show', [
            'report' => $report,
        ]);
    }

    public function update(UpdateAdminReportStatusRequest $request, Report $report): RedirectResponse
    {
        $newStatus = $request->validated()['status'];
        $report->status = $newStatus;
        $report->save();

        AdminActivity::log('Admin updated report status', [
            'report_id' => $report->id,
            'status' => $newStatus,
        ]);

        return redirect()->back()->with('status', 'report-updated');
    }
}
