<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\SecretsController;

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
//    dd(env('APP_URL'));
    dd(isset(Config::get('secrets')->key2)?Config::get('secrets')->key2:null);
    return view('welcome');
});

Route::get('/manual/update/{key}', function ($key) {
    dd(isset(Config::get('secrets')->$key)?Config::get('secrets')->$key:null);
});

Route::get('/automatic/update/{key}/{value}', function ($key, $expectedValue) {
    function apiCallSimulation($key, $expectedValue, $secondTry = false){
        if (!isset(Config::get('secrets')->$key)) return null;
        if (Config::get('secrets')->$key === $expectedValue){
            return Config::get('secrets')->$key;
        }else {
            $secretsController = new SecretsController;
            if ($secretsController->isLatest()){
                return 'Latest secret from aws does not match with the expected value';
            }
            if (!$secondTry){
                return apiCallSimulation($key, $expectedValue,true);
            }
            // todo add something that registers this api key as not working so that we can stop pinging AWS everytime
            return 'Unknown failure/unable to save latest secrets from aws';
        }
    }
    dd(apiCallSimulation($key, $expectedValue));
});


Route::get('/test', function () {
     function apiCall($secondTry = false){
        if (Config::get('secrets')->key2 === 'valueB'){
            return 'API call success';
        }else {
            $secretsController = new SecretsController;
            if ($secretsController->isLatest()){
                return 'API called failed: we have latest secret from aws';
            }
            if (!$secondTry){
                return apiCall(true);
            }
            return 'API call failed';
        }
    }
    $final = apiCall();
    dd($final);
});
