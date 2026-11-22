<?php

namespace App\Http\Controllers;

class TenantLockedController extends Controller
{
    public function __invoke()
    {
        $tenant = tenant();
        $invoice = $tenant->invoices()->where('status', 'open')->latest()->first();

        return view('tenant.locked', compact('tenant', 'invoice'));
    }
}
