<?php

namespace App\Http\Controllers;

use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
