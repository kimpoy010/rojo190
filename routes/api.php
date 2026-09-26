<?php

use App\Http\Controllers\Api\RfidScanController;
use Illuminate\Support\Facades\Route;

// Called by the local serial bridge process, not a browser — authenticated
// by the terminal's bearer token rather than a logged-in session.
Route::middleware('rfid.terminal')->post('/rfid/scan', [RfidScanController::class, 'store'])->name('api.rfid.scan');
