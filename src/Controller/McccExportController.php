<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Export\Mccc\McccTableBuilder;
use App\Export\Mccc\McccXlsxWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/** Tableau des MCCC d'une formation au format Excel (cf. McccTableBuilder). */
final class McccExportController extends AbstractController
{
    #[Route('/formations/{id}/export/mccc.xlsx', name: 'formation_export_mccc', methods: ['GET'])]
    public function xlsx(
        Formation $formation,
        McccTableBuilder $builder,
        McccXlsxWriter $writer,
        #[MapQueryParameter] ?string $feuille = null,
        #[MapQueryParameter] ?string $parcours = null,
    ): StreamedResponse {
        $table = $builder->build($formation, $feuille, $parcours);

        $response = new StreamedResponse(static fn () => $writer->write($table, 'php://output'));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $table->fileName, 'MCCC.xlsx'));

        return $response;
    }
}
