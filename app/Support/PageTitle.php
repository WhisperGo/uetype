<?php

namespace App\Support;

final class PageTitle
{
    public static function forRoute(?string $routeName): string
    {
        $brand = __('titles.brand');
        $pages = (array) trans('titles.pages');

        if ($routeName === null || ! array_key_exists($routeName, $pages)) {
            return $brand;
        }

        return __('titles.template', [
            'page' => $pages[$routeName],
            'brand' => $brand,
        ]);
    }
}
