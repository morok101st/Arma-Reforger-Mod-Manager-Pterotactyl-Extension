<?php
use Illuminate\Support\Facades\Route;
use ArmmModsets\Http\Controllers\ModsetController;

Route::get('/modsets', [ModsetController::class, 'modsets']);
// Both actions log in to ARMM on every call; limit them per user.
Route::post('/modsets/refresh', [ModsetController::class, 'refreshModsets'])->middleware('throttle:10,1');
Route::get('/assignment', [ModsetController::class, 'assignment']);
Route::put('/assignment', [ModsetController::class, 'assign']);
Route::post('/sync', [ModsetController::class, 'sync'])->middleware('throttle:5,1');
