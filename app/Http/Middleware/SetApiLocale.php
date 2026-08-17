<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetApiLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('locale')
            ?? $this->fromAcceptLanguageHeader($request)
            ?? $request->user()?->locale
            ?? config('app.locale');

        if (in_array($locale, SetLocale::SUPPORTED_LOCALES, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /**
     * The primary language subtag of the first offered language, e.g.
     * "ar-SA,ar;q=0.9,en;q=0.8" -> "ar". Mobile clients send a single
     * simple tag, so this doesn't need full RFC 4647 negotiation.
     */
    private function fromAcceptLanguageHeader(Request $request): ?string
    {
        $header = $request->header('Accept-Language');

        if ($header === null) {
            return null;
        }

        return Str::lower(Str::before(Str::before($header, ','), '-'));
    }
}
