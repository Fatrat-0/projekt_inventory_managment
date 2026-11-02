<?php

namespace App\Http\Controllers;

use App\Http\Requests\PartnerRequest;
use App\Models\Partner;
use App\Queries\PartnerListing;
use App\Services\PartnerDuplicateCheck;
use App\Support\PartnerListContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $all = $listing->baseQuery()->orderBy('name')->orderBy('id')->get(['id', 'name', 'email', 'phone']);
        $suggestions = $all->map(fn ($partner) => ['value' => (string) $partner->id, 'name' => $partner->name,
            'detail' => collect([$partner->email, $partner->phone, '#'.$partner->id])->reject(fn ($value) => blank($value))->implode(' · ')])->values();
        $selectedPartner = $filters['partner_id'] !== '' ? Partner::find($filters['partner_id']) : null;
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
        $duplicates = app(PartnerDuplicateCheck::class)->check(old('name', $partner?->name ?? ''), old('email', $partner?->email ?? ''), $partner);
        $oldToken = old('duplicate_token');
        $duplicateAcknowledged = $duplicates['total'] > 0 && in_array(old('confirm_duplicates'), [true, 1, '1'], true)
            && is_string($oldToken) && hash_equals($duplicates['token'], $oldToken);
        return view('partners.form', compact('partner', 'context', 'returnUrl', 'defaultType', 'duplicates', 'duplicateAcknowledged'));
    }

    public function store(PartnerRequest $request)
    {
        if ($warning = $this->duplicateWarning($request)) return $warning;
        Partner::create(array_replace(['email' => null, 'phone' => null], $request->safe()->only(['name', 'type', 'email', 'phone'])));
        return redirect(PartnerListContext::url($request->input('context')))->with('success', 'Partner létrehozva.');
    }

    public function update(PartnerRequest $request, Partner $partner)
    {
        if ($warning = $this->duplicateWarning($request, $partner)) return $warning;
        $partner->update(array_replace(['email' => null, 'phone' => null], $request->safe()->only(['name', 'type', 'email', 'phone'])));
        return redirect(PartnerListContext::url($request->input('context')))->with('success', 'Partner adatai frissítve.');
    }

    public function destroy(Request $request, Partner $partner)
    {
        $partner->delete();
        return redirect(PartnerListContext::url($request->input('context'), ['partner_id', 'page']))->with('success', 'Partner törölve.');
    }

    public function duplicates(Request $request, ?Partner $partner = null)
    {
        $data = $request->validate(['name' => 'nullable|string|max:100', 'email' => 'nullable|string|max:100']);
        return response()->json(app(PartnerDuplicateCheck::class)->check($data['name'] ?? '', $data['email'] ?? '', $partner))
            ->header('Cache-Control', 'no-store, private');
    }

    private function duplicateWarning(PartnerRequest $request, ?Partner $partner = null)
    {
        $check = app(PartnerDuplicateCheck::class)->check($request->input('name'), $request->input('email'), $partner);
        $token = $request->input('duplicate_token');
        if ($check['total'] === 0 || ($request->boolean('confirm_duplicates') && is_string($token) && hash_equals($check['token'], $token))) return null;
        $params = ['context' => PartnerListContext::token($request->input('context'))];
        if ($partner) $params['partner'] = $partner;
        return redirect()->route($partner ? 'partners.edit' : 'partners.create', $params)
            ->withInput($request->only(['name', 'type', 'email', 'phone', 'context']))->with('duplicate_warning', true);
    }

    public function archive(Request $request, Partner $partner) { return $this->changeState($request, $partner, true); }
    public function restore(Request $request, Partner $partner) { return $this->changeState($request, $partner, false); }

    private function changeState(Request $request, Partner $partner, bool $archive)
    {
        DB::transaction(function () use ($partner, $archive) {
            $locked = Partner::lockForUpdate()->findOrFail($partner->id);
            if ($locked->isArchived() === $archive) return;
            $locked->forceFill(['archived_at' => $archive ? now() : null])->save();
        }, 3);
        return redirect(PartnerListContext::url($request->input('context'), ['partner_id', 'page']))
            ->with('success', $archive ? 'Partner archiválva. Később visszaállítható.' : 'Partner visszaállítva az aktívak közé.');
    }
}
