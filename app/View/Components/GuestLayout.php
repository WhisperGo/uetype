<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The <x-guest-layout> component wrapping unauthenticated pages (login, username choice).
 */
class GuestLayout extends Component
{
    /**
     * @param  string|null  $backUrl  where the header's back link points; null renders none.
     *
     * Guest pages carry no navigation of their own: layouts.navigation DOES have a guest
     * branch, but only layouts.app includes it. Without this prop the login page's only
     * exits are the wordmark and a terms link, so a visitor who changes their mind has
     * nothing to click.
     */
    public function __construct(public ?string $backUrl = null) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
