<?php

use App\Http\Controllers\Api\OpenApiController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/swagger', [OpenApiController::class, 'ui'])->name('swagger.ui');
Route::get('/swagger/openapi.json', [OpenApiController::class, 'specification'])->name('swagger.specification');
