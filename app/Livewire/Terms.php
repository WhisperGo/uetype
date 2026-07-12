<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Like About & Privacy: static, no reactive state. Built as a Livewire full-page
 * component so that {{ $slot }} in layouts/app.blade.php is filled via the
 * #[Layout(...)] attribute below.
 */
#[Layout('layouts.app')]
class Terms extends Component
{
    public function render()
    {
        $sections = trans('terms.sections');

        return view('livewire.terms', compact('sections'));
    }
}