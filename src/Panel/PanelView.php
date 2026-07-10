<?php

declare(strict_types=1);

namespace App\Panel;

use App\Http\ApiException;
use App\Http\Response;

/**
 * Admin panel sayfa render'ı (06_Admin_Panel.md). Sunucu yalnızca HTML iskeleti üretir;
 * tüm veri istemcide localStorage'daki panel JWT'siyle fetch edilir (06§2). Sayfa şablonları
 * `views/panel/` altındadır ve ortak layout'u kendileri include eder.
 */
final class PanelView
{
    public static function render(string $page, array $vars = []): Response
    {
        $file = dirname(__DIR__, 2) . '/views/panel/' . $page . '.php';
        if (!is_file($file)) {
            throw new ApiException('not_found', 'Page not found.', 404);
        }

        extract($vars);
        ob_start();
        require $file;

        return Response::html((string) ob_get_clean());
    }
}
