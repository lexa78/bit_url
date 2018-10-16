<?php

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

Route::get('miniurl',['as'=>'mini_url','uses'=>'HandleUrlController@index']);
Route::post('decrease',['as'=>'decrease_url','uses'=>'HandleUrlController@decrease']);

Route::get('/', 'Controller@index');
