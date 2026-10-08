<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        if (auth()->check() && auth()->user()->locale) {
            return auth()->user()->locale;
        }

        if ($request->session()->has('locale')) {
            return $request->session()->get('locale');
        }

        $allowed = config('app.translatable_locales', ['zh_CN', 'en', 'es', 'fr', 'pt']);
        $preferred = $request->getPreferredLanguage($allowed);

        if ($preferred) {
            return $preferred;
        }

        return config('app.locale', 'zh_CN');
    }
}
