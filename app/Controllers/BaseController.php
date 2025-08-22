<?php
declare(strict_types=1);

namespace App\Controllers;

use View;

abstract class BaseController
{
    protected function view(string $template, array $data = []): void {
        $twig = View::env();
        echo $twig->render($template, $data);
    }
}
