<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return $router->app->version();
});

$router->get('/key', function() {
    return str_random(32);
});

$router->get('/webhook', 'BotController@verify_token');
$router->post('/webhook', 'BotController@handle_query');

$router->get('/weather', 'WeatherController@verify_token');
$router->post('/weather', 'WeatherController@handle_query');

$router->post('/FbAccount', 'BotController@FacebookAccount');
$router->get('/FbAccount', 'BotController@FacebookAccount');

$router->post('/FBCallback', 'BotController@FacebookCallback');

$router->get('/test', 'BotController@test');
