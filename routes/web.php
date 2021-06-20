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

Route::get('/manual/{key}', function ($key) {
    $secretsController = new SecretsController;
    dd($secretsController->get($key));
});

Route::get('/automatic/{key}/{value}', function ($key, $expectedValue) {
    function apiCallSimulation($key, $expectedValue, $secondTry = false){
        $secretsController = new SecretsController;
        if ($secretsController->get($key) === $expectedValue){
            $secretsController->markWorking($key); // todo check if this can be achieved without marking here .. .like saving another property in cache
            return $secretsController->get($key);
        }else {
            if ($secretsController->isLatest($key)){
                // todo add something that registers this api key as not working so that we can stop pinging AWS until it is resolved
                return 'Latest secret from aws does not match with the expected value';
            }
            if (!$secondTry){
                return apiCallSimulation($key, $expectedValue,true);
            }
            // todo add something that registers this api key as not working so that we can stop pinging AWS until it is resolved
            return 'Unknown failure/unable to save latest secrets from aws';
        }
    }
    dd(apiCallSimulation($key, $expectedValue));
});


Route::get('/test', function () {
    $a = array(
        'key1' => array(
            'value' => 'value1',
            'retry_count' => 1,
            'status' => 'active',
        ),
        'key2' => array(
            'value' => 'value2',
            'retry_count' => 3,
            'status' => 'active',
        ),
        'key3' => array(
            'value' => 'value3',
            'retry_count' => 11,
            'status' => 'failed',
        )
    );
    dd(isset($a['key4']));

    $secretsController = new SecretsController;
    $secret = $secretsController->get('key2');
    dd($secret);
    Config::set(['secrets' => $secrets]);
    $retryCount = 'asd';
    switch (true) {
//        case !is_numeric($retryCount):
//            $final = 'yes';
//            break;

        case $retryCount <= 10:
            $final = 'less';
            break;

        case $retryCount > 10:
            return 'lsks';
            break;

        default:
            $final = 'none';
            break;
    }
    dd($retryCount);
});
