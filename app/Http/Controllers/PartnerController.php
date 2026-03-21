<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    public function index()
    {
        $partners = Partner::all();
        return view('partners.index', compact('partners'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|max:100',
            'type' => 'required|in:customer,supplier',
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|max:20',
        ]);

        Partner::create($validated);
        return redirect()->route('partners.index')->with('success', 'Partner sikeresen hozzáadva!');
    }

    public function destroy($id)
    {
        Partner::findOrFail($id)->delete();
        return redirect()->route('partners.index')->with('success', 'Partner törölve!');
    }
}