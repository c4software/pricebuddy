<?php

use App\Console\Commands\FetchAll;
use App\Models\UrlResearch;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Yoeriboven\LaravelLogDb\Models\LogMessage;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Check for new prices
        $schedule->command(FetchAll::COMMAND, ['--log'])->everyTwoHours();
        // Prune old log messages
        $schedule->command('model:prune', ['--model' => [LogMessage::class]])->daily();
        // Prune search research results.
        $schedule->command('model:prune', ['--model' => [UrlResearch::class]])->daily();
    })
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
