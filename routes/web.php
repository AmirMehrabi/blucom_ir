<?php
use Illuminate\Support\Facades\Route;
Route::view('/', 'dashboard', ['mode' => 'customer'])->name('home');
Route::view('/portal', 'dashboard', ['mode' => 'customer'])->name('portal');
Route::view('/admin', 'dashboard', ['mode' => 'admin'])->name('admin');
Route::view('/login', 'auth', ['type' => 'login'])->name('login');
Route::view('/register', 'auth', ['type' => 'register'])->name('register');
