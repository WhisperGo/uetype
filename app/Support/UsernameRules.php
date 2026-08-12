<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * A single source for username validation rules.
 *
 * Used by two different kinds of caller: Settings::saveUsername (Livewire) and
 * GoogleAuthController::storeUsername (controller). A FormRequest only fits the second,
 * so this is a static method that can be passed to both Livewire's `validate()` AND
 * `$request->validate()`. Previously the array was copied in both places -- change one
 * (e.g. the minimum length, add a banned-word filter) and the other silently drifts.
 */
final class UsernameRules
{
    /**
     * @param  int|null  $ignoreId  User id to exclude from the unique check. Settings passes
     *                              the user's id so their own username isn't treated as a
     *                              clash; registration (no row yet) doesn't pass it.
     */
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'required',
            'string',
            'alpha_dash',
            'min:3',
            'max:20',
            Rule::unique('users', 'username')->ignore($ignoreId),
        ];
    }
}
