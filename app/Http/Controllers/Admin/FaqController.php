<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FaqRequest;
use App\Models\Faq;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FaqController extends Controller
{
    public function index(): View
    {
        $faqs = Faq::query()
            ->orderBy('placement')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('placement');

        return view('admin.faqs.index', [
            'faqsByPlacement' => $faqs,
            'placements' => Faq::PLACEMENTS,
        ]);
    }

    public function store(FaqRequest $request): RedirectResponse
    {
        $faq = Faq::query()->create($request->validated());

        AdminActivity::log('Admin created an FAQ', [
            'faq_id' => $faq->id,
            'placement' => $faq->placement,
        ]);

        return redirect()
            ->route('admin.faqs.index')
            ->with('status', 'faq-created');
    }

    public function update(FaqRequest $request, Faq $faq): RedirectResponse
    {
        $faq->update($request->validated());

        AdminActivity::log('Admin updated an FAQ', [
            'faq_id' => $faq->id,
            'placement' => $faq->placement,
            'is_published' => $faq->is_published,
        ]);

        return redirect()
            ->route('admin.faqs.index')
            ->with('status', 'faq-updated');
    }

    public function destroy(Faq $faq): RedirectResponse
    {
        $id = $faq->id;
        $placement = $faq->placement;

        $faq->delete();

        AdminActivity::log('Admin deleted an FAQ', [
            'faq_id' => $id,
            'placement' => $placement,
        ]);

        return redirect()
            ->route('admin.faqs.index')
            ->with('status', 'faq-deleted');
    }
}
