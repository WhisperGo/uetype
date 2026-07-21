<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Satu sumber aturan validasi username.
 *
 * Dipakai dua tempat berbeda jenis: Settings::saveUsername (Livewire) dan
 * GoogleAuthController::storeUsername (controller). FormRequest hanya pas untuk
 * yang kedua, jadi bentuknya method statik yang bisa diteruskan ke `validate()`
 * Livewire MAUPUN `$request->validate()`. Sebelumnya array-nya disalin di kedua
 * tempat -- ubah satu (mis. panjang minimum, tambah filter kata terlarang) dan
 * yang lain diam-diam menyimpang.
 */
final class UsernameRules
{
    /**
     * @param  int|null  $ignoreId  Id user yang dikecualikan dari cek unique.
     *                              Settings meneruskan id user agar username
     *                              miliknya sendiri tak dianggap bentrok;
     *                              register (belum punya baris) tak meneruskannya.
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
