<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    dd(Config::get('secrets')->key2);
    return view('welcome');
});


//Route::get('/test', function () {
//    if (Config::get('secrets')->key2 === 'value2'){
//        dd('we have the latest secret');
//    }else {
//        dd('we have an outdated secret');
//    }
//    dd('end');
//});
