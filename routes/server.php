<?php
use Illuminate\Support\Facades\Route;
use ArmmModsets\Http\Controllers\ModsetController;

Route::get('/modsets', [ModsetController::class, 'modsets']);
Route::get('/assignment', [ModsetController::class, 'assignment']);
Route::put('/assignment', [ModsetController::class, 'assign']);
Route::post('/sync', [ModsetController::class, 'sync']);
