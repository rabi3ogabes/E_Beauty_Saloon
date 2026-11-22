<?php

use App\Http\Controllers\Billing\InvoicePdfController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->get('/admin/invoices/{invoice}/pdf', InvoicePdfController::class)->name('admin.invoices.pdf');
