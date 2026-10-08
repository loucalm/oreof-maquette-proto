<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TranslationFileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aide contextuelle par écran : textes rangés dans translations/help.fr.yaml
 * (clés « <route>.title » / « <route>.body »), modifiables par l'admin depuis
 * le tiroir d'aide lui-même.
 */
final class HelpController extends AbstractController
{
    private const FILE = 'help.fr.yaml';

    #[Route('/administration/aide/{route}', name: 'admin_help_save', methods: ['POST'], requirements: ['route' => '[a-z0-9_]+'])]
    public function save(string $route, Request $request, TranslationFileManager $files): Response
    {
        $title = trim((string) $request->request->get('title'));
        $body = trim((string) $request->request->get('body'));

        if ('' === $title && '' === $body) {
            $files->deleteKey(self::FILE, $route.'.title');
            $files->deleteKey(self::FILE, $route.'.body');
            $this->addFlash('info', 'Aide de cet écran supprimée.');
        } else {
            $files->writeSingle(self::FILE, $route.'.title', $title);
            $files->writeSingle(self::FILE, $route.'.body', $body);
            $this->addFlash('success', 'Aide de cet écran enregistrée.');
        }

        $back = (string) $request->request->get('back');

        return $this->redirect(1 === preg_match('#^/(?![/\\\\])#', $back) ? $back : $this->generateUrl('formation_index'));
    }
}
