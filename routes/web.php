<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/panel', 'panel.inicio')->name('panel.inicio');
