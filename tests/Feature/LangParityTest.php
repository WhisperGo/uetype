<?php

use Illuminate\Support\Arr;

function langFileKeys(string $path): array
{
    $keys = array_keys(Arr::dot(require $path));
    sort($keys);

    return $keys;
}

test('every en and id language file has an identical set of keys', function () {
    $enFiles = glob(base_path('lang/en/*.php'));

    expect($enFiles)->not->toBeEmpty();

    foreach ($enFiles as $enPath) {
        $file = basename($enPath);
        $idPath = base_path('lang/id/'.$file);

        expect(file_exists($idPath))->toBeTrue("Missing lang/id/{$file}");

        $enKeys = langFileKeys($enPath);
        $idKeys = langFileKeys($idPath);

        expect($idKeys)->toBe($enKeys, "Key mismatch between en/{$file} and id/{$file}");
    }
});

test('there is no id language file without an en counterpart', function () {
    foreach (glob(base_path('lang/id/*.php')) as $idPath) {
        $file = basename($idPath);
        expect(file_exists(base_path('lang/en/'.$file)))->toBeTrue("Missing lang/en/{$file}");
    }
});
