<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TranslationFileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition admin des textes de l'appli : chaque texte affiché dans les
 * templates est une clé de traduction (fichiers YAML de `translations/`),
 * modifiable ici sans toucher au code.
 */
#[Route('/administration/traductions')]
final class TranslationController extends AbstractController
{
    public function __construct(private readonly TranslationFileManager $tm)
    {
    }

    #[Route('', name: 'admin_traductions', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('translation/index.html.twig', [
            'files' => $this->tm->listFiles(),
        ]);
    }

    #[Route('/recherche', name: 'admin_traductions_recherche', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $results = [];

        if ($search !== '') {
            foreach ($this->tm->listFiles() as $file) {
                foreach ($this->tm->read($file->getFilename()) as $key => $value) {
                    if (str_contains($key, $search) || str_contains($value, $search)) {
                        $results[$file->getFilename()][$key] = $value;
                    }
                }
            }
        }

        return $this->render('translation/_recherche.html.twig', [
            'search' => $search,
            'results' => $results,
        ]);
    }

    #[Route('/{filename}', name: 'admin_traductions_edit', requirements: ['filename' => '[\w.-]+\.yaml'], methods: ['GET'])]
    public function edit(string $filename): Response
    {
        return $this->render('translation/edit.html.twig', [
            'filename' => $filename,
            'translations' => $this->tm->read($filename),
        ]);
    }

    #[Route('/{filename}/sauver', name: 'admin_traductions_save_one', requirements: ['filename' => '[\w.-]+\.yaml'], methods: ['POST'])]
    public function saveOne(string $filename, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $key = trim((string) ($data['key'] ?? ''));
            $value = (string) ($data['value'] ?? '');

            if ($key === '') {
                return $this->json(['success' => false, 'error' => 'La clé ne peut pas être vide.'], 422);
            }

            $this->tm->writeSingle($filename, $key, $value);

            return $this->json(['success' => true]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/{filename}/supprimer', name: 'admin_traductions_delete_one', requirements: ['filename' => '[\w.-]+\.yaml'], methods: ['DELETE'])]
    public function deleteOne(string $filename, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $key = trim((string) ($data['key'] ?? ''));

            if ($key === '') {
                return $this->json(['success' => false, 'error' => 'Clé manquante.'], 422);
            }

            $this->tm->deleteKey($filename, $key);

            return $this->json(['success' => true]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
