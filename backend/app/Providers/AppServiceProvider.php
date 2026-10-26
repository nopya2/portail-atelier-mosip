<?php

namespace App\Providers;

use App\Services\Sms\LogSmsSender;
use App\Services\Sms\MailpitSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Passerelle SMS choisie par SMS_DRIVER (voir config/portail.php)
        $this->app->bind(SmsSender::class, fn () => match (config('portail.sms_driver')) {
            'log' => new LogSmsSender,
            default => new MailpitSmsSender,
        });
    }

    public function boot(): void
    {
        // Limiteurs nommés : chaque usage a son propre compteur
        RateLimiter::for('logs', fn (Request $r) => Limit::perMinute(60)->by('logs|'.$r->ip()));
        RateLimiter::for('esignet', fn (Request $r) => Limit::perMinute(30)->by('esignet|'.$r->ip()));
    }
}
