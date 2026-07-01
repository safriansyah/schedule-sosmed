<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Run the command in-process (via Artisan::call) instead of spawning a
// subprocess. Many shared hosts disable proc_open, which Schedule::command()
// requires — this avoids that limitation entirely.
Schedule::call(function () {
    Artisan::call('app:auto-post');
})->everyMinute();
