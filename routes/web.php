<?php

use Illuminate\Support\Facades\Route;

// This server is only the API: no public page here, and nothing that says which framework runs it.
Route::get('/', function () {
    abort(404);
});
