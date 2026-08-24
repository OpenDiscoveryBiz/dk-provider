<?php

use App\Http\Controllers\OpenDiscoveryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [OpenDiscoveryController::class, 'frontpage']);
Route::get('/.well-known/opendiscovery/{id}.json', [OpenDiscoveryController::class, 'lookup']);
