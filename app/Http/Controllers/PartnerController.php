<?php

namespace App\Http\Controllers;

use App\Http\Requests\PartnerRequest;
use App\Models\Partner;
use App\Queries\PartnerListing;
use App\Support\PartnerListContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PartnerController extends Controller
{
    public function index(Request $request)
    {
        try { $listing = new PartnerListing($request); }
        catch (ValidationException $e) {
            if ($request->expectsJson()) throw $e;
            return redirect()->route('partners.index')->withErrors(['filters' => 'A keresési feltételek már nem érvényesek. A szűrőket visszaállítottuk.']);
        }
        $filters = $listing->filters;
        $partners = $listing->query()->paginate($filters['per_page'])->appends($filters);
        if ($request->integer('page', 1) > $partners->lastPage()) {
            return redirect()->route('partners.index', $filters + ($partners->lastPage() > 1 ? ['page' => $partners->lastPage()] : []));
        }
        $all = Partner::orderBy('name')->orderBy('id')->get(['id', 'name', 'email', 'phone']);
        $suggestions = $all->map(fn ($partner) => ['value' => (string) $partner->id, 'name' => $partner->name,
            'detail' => collect([$partner->email, $partner->phone, '#'.$partner->id])->reject(fn ($value) => blank($value))->implode(' · ')])->values();
        $selectedPartner = $all->firstWhere('id', $filters['partner_id']);
        $context = PartnerListContext::encode($filters + ['page' => $request->integer('page', 1)]);
        return view('partners.index', compact('partners', 'filters', 'suggestions', 'selectedPartner', 'context'));
    }

    public function create(Request $request) { return $this->form($request); }
    public function edit(Request $request, Partner $partner) { return $this->form($request, $partner); }

    private function form(Request $request, ?Partner $partner = null)
    {
        $context = PartnerListContext::token(old('context', $request->query('context', '')));
        $returnUrl = PartnerListContext::url($context);
        $requestedType = $request->query('type');
        $defaultType = $partner?->type ?? (is_string($requestedType) && isset(Partner::TYPES[$requestedType]) ? $requestedType : 'customer');
        return view('partners.form', compact('partner', 'context', 'returnUrl', 'defaultType'));
    }

    public function store(PartnerRequest $request)
    {
        Partner::create(array_replace(['email' => null, 'phone' => null], $request->validated()));
        return redirect(PartnerListContext::url($request->input('context')))->with('success', 'Partner létrehozva.');
    }

    public function update(PartnerRequest $request, Partner $partner)
    {
        $partner->update(array_replace(['email' => null, 'phone' => null], $request->validated()));
        return redirect(PartnerListContext::url($request->input('context')))->with('success', 'Partner adatai frissítve.');
    }

    public function destroy(Request $request, Partner $partner)
    {
        $partner->delete();
        return redirect(PartnerListContext::url($request->input('context'), ['partner_id', 'page']))->with('success', 'Partner törölve.');
    }
}
