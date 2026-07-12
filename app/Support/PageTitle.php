<?php

namespace App\Support;

/**
 * Builds the localized "<Page> | <Brand>" browser title for a route, falling
 * back to the bare brand name for unknown routes.
 */
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
