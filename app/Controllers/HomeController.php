<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Part;
use App\Models\Day;

final class HomeController extends BaseController
{
    public function index(): void
{
    $partModel = new Part();
    $tiles = $partModel->listForHome();  // <— nuovo metodo

    $base = rtrim($_ENV['APP_URL_BASE'] ?? '/', '/');
    $defaultCover = $base . '/assets/images/cover-default.jpg';

    // Hero fallback
    $heroImg = $defaultCover;

    // Risolvo le immagini dei tile: uso la cover del primo giorno della parte
    foreach ($tiles as &$t) {
        $img = $t['first_day_cover'] ?? null;
        if (!$img) {
            // fallback: qualunque cover del DB
            $img = (new Day())->getFirstCover();
        }
        $t['img'] = $img ? asset_url($img) : $defaultCover;

        // prima reale diventa l'hero
        if ($heroImg === $defaultCover && $img) {
            $heroImg = asset_url($img);
        }
    }
    unset($t);

    $days = (new Day())->listAllWithMeta();

    $this->view('home.twig', [
        'title'      => 'Viaggio USA',
        'body_class' => 'no-header',
        'hero'       => [
            'title' => 'La Nostra Avventura Americana 2026',
            'sub'   => 'Il diario di un viaggio indimenticabile, dalle luci di New York al cuore del Sud, fino alla magia di Orlando.',
            'img'   => $heroImg,
        ],
        'tiles' => $tiles,
        'days'  => $days,
        'meta'  => [
            'title'       => 'Viaggio USA – Itinerario',
            'description' => 'Esplora le parti del viaggio: New York, il Sud, Orlando. Mappa, rendiconto e link utili.',
            'url'         => $base . '/',
            'image'       => $heroImg,
        ],
    ]);
}
}
