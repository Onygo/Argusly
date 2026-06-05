<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class Brand
{
    public static function product(): string
    {
        return (string) config('brand.product_name', 'Argusly');
    }

    public static function parent(): string
    {
        return (string) config('brand.parent_name', 'Onygo');
    }

    public static function parentUrl(): ?string
    {
        return config('brand.parent_url');
    }

    public static function parentLinked(?string $class = null): HtmlString
    {
        $name = e(self::parent());
        $url = self::parentUrl();

        if (! $url) {
            return new HtmlString($name);
        }

        $classAttr = $class ? sprintf(' class="%s"', e($class)) : '';

        return new HtmlString(sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer"%s>%s</a>',
            e($url),
            $classAttr,
            $name
        ));
    }

    public static function full(): string
    {
        if (! (bool) config('brand.show_parent_branding', true)) {
            return self::product();
        }

        return sprintf('%s by %s', self::product(), self::parent());
    }
}
