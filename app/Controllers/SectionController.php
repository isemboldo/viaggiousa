<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Section;

final class SectionController extends BaseController
{
    public function show(string $id): void
    {
        $secId = (int)$id;
        if ($secId <= 0) {
            http_response_code(404);
            echo "Sezione non trovata";
            return;
        }

        $model = new Section();
        $section = $model->findById($secId);
        if (!$section) {
            http_response_code(404);
            echo "Sezione non trovata";
            return;
        }

        $nav = $model->getPrevNext($secId);

        $defaultCover = ($_ENV['APP_URL_BASE'] ?? '/') . '/assets/images/cover-default.jpg';
        $snippet      = strip_tags((string)($section['testo'] ?? ''));
        $metaImage    = !empty($section['immagine']) ? asset_url($section['immagine']) : $defaultCover;

        $this->view('section.twig', [
            'section' => $section,
            'nav'     => $nav,
            'meta'    => [
                'title'       => $section['titolo'] ?? 'Sezione',
                'description' => mb_substr($snippet, 0, 160),
                'url'         => ($_ENV['APP_URL_BASE'] ?? '/') . "/sezione/{$section['id']}",
                'image'       => $metaImage,
            ],
        ]);
    }
}
