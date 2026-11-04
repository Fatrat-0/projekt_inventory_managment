<?php

namespace App\Http\Controllers;

use App\Http\Requests\PartnerRequest;
use App\Models\Partner;
use App\Queries\PartnerListing;
use App\Services\PartnerDuplicateCheck;
use App\Services\MasterDataEditor;
use App\Support\PartnerListContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerController extends Controller
{
    private const FIELDS = ['name', 'type', 'email', 'phone', 'contact_name', 'country', 'postal_code', 'city', 'street_address', 'notes'];

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
        $all = $listing->baseQuery()->orderBy('name')->orderBy('id')->get(['id', 'name', 'email', 'phone', 'contact_name', 'city']);
        $suggestions = $all->map(fn ($partner) => ['value' => (string) $partner->id, 'name' => $partner->name,
            'detail' => collect([$partner->contact_name, $partner->email, $partner->phone, $partner->city, '#'.$partner->id])
                ->map(fn ($value) => \Illuminate\Support\Str::trim($value ?? ''))->reject(fn ($value) => $value === '')->implode(' · ')])->values();
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
        $partner?->loadCount(['movements', 'movements as receipts_count' => fn ($q) => $q->where('type', 'in'),
            'movements as issues_count' => fn ($q) => $q->where('type', 'out')]);
        return view('partners.form', compact('partner', 'context', 'returnUrl', 'defaultType', 'duplicates', 'duplicateAcknowledged'));
    }

    public function store(PartnerRequest $request)
    {
        if ($warning = $this->duplicateWarning($request)) return $warning;
        Partner::create($this->partnerData($request));
        return redirect(PartnerListContext::url($request->input('context')))->with('success', 'Partner létrehozva.');
    }

    public function update(PartnerRequest $request, Partner $partner)
    {
        if ($warning = $this->duplicateWarning($request, $partner)) return $warning;
        MasterDataEditor::update($partner, $request, $this->partnerData($request),
            route('partners.edit', ['partner' => $partner, 'context' => PartnerListContext::token($request->input('context'))]));
        return redirect(PartnerListContext::url($request->input('context')))->with('success', 'Partner adatai frissítve.');
    }

    public function destroy(Request $request, Partner $partner)
    {
        $deleted = DB::transaction(function () use ($partner) {
            $locked = Partner::lockForUpdate()->findOrFail($partner->id);
            if ($locked->movements()->exists()) return false;
            return $locked->delete();
        }, 3);
        if (!$deleted) return redirect(PartnerListContext::url($request->input('context')))
            ->withErrors(['partner' => 'A partnerhez készletmozgás tartozik, ezért nem törölhető. Használd az archiválást.']);
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
            ->withInput($request->only([...self::FIELDS, 'context', 'lock_version', 'review_version', 'confirm_edit_conflict']))->with('duplicate_warning', true);
    }

    private function partnerData(PartnerRequest $request): array
    {
        return array_replace(array_fill_keys(array_diff(self::FIELDS, ['name', 'type']), null), $request->safe()->only(self::FIELDS));
    }

    public function archive(Request $request, Partner $partner) { return $this->changeState($request, $partner, true); }
    public function restore(Request $request, Partner $partner) { return $this->changeState($request, $partner, false); }

    private function changeState(Request $request, Partner $partner, bool $archive)
    {
        DB::transaction(function () use ($partner, $archive) {
            $locked = Partner::lockForUpdate()->findOrFail($partner->id);
            if ($locked->isArchived() === $archive) return;
            $locked->forceFill(['archived_at' => $archive ? now() : null, 'lock_version' => (int) $locked->lock_version + 1])->save();
        }, 3);
        return redirect(PartnerListContext::url($request->input('context'), ['partner_id', 'page']))
            ->with('success', $archive ? 'Partner archiválva. Később visszaállítható.' : 'Partner visszaállítva az aktívak közé.');
    }
}
