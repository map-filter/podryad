<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('permits:notify')
    ->dailyAt(config('permits.notify_time', '09:00'))
    ->timezone('Asia/Almaty')
    ->withoutOverlapping()
    ->onOneServer();
