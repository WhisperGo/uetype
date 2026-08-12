<?php

/*
|--------------------------------------------------------------------------
| Umur sesi dipatok di sumber yang TERLACAK git, bukan di .env siapa pun.
|
| Assertion `config('session.lifetime')` akan membaca .env milik developer yang
| menjalankan test -- gagal merah karena alasan yang sama sekali salah, dan hijau
| di mesin yang kebetulan sudah menyetelnya. Yang perlu dijaga adalah defaultnya.
|--------------------------------------------------------------------------
*/

it('mematok umur sesi dua minggu di config dan .env.example', function () {
    expect(file_get_contents(config_path('session.php')))
        ->toContain("env('SESSION_LIFETIME', 20160)")
        ->and(file_get_contents(base_path('.env.example')))
        ->toContain('SESSION_LIFETIME=20160');
});

it('menjaga expire_on_close tetap false, karena itulah yang membuat angka di atas berarti', function () {
    // expire_on_close false = cookie sesi terbit dengan Max-Age = lifetime * 60. Kalau ini
    // pernah jadi true, cookie-nya mati saat browser ditutup dan menaikkan lifetime tak lagi
    // berarti apa-apa bagi browser -- yang tersisa cuma jendela idle di server.
    expect(config('session.expire_on_close'))->toBeFalse();
});
