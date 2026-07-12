<?php

namespace App\Http\Controllers;

use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches the UI language: validates the requested locale, stores it in the
 * session, and redirects back. See App\Support\Locale for supported values.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->input('locale');

        if (Locale::isSupported($locale)) {
            $request->session()->put('locale', $locale);

            $request->user()?->setPreference('locale', $locale);
        }

        return back();
    }
}
