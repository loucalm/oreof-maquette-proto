<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FicheMatiere;
use App\FicheMatiere\EcValueResolver;
use App\FicheMatiere\FicheMatiereEditor;
use App\FicheMatiere\FicheMatiereForms;
use App\FicheMatiere\FicheMatiereUsage;
use App\Twig\RoleExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/** Page de saisie d'une fiche matière : ses onglets, l'enregistrement par onglet et le bandeau d'usage. */
final class FicheMatiereController extends AbstractController
{
    /** Jeton CSRF sans état (cf. config/packages/csrf.yaml) des POST de la fiche. */
    public const CSRF_ID = 'fiche_matiere';
    public const CSRF_FIELD = '_csrf_token';

    public function __construct(
        private readonly FicheMatiereForms $forms,
        private readonly FicheMatiereEditor $editor,
        private readonly FicheMatiereUsage $usage,
        private readonly RoleExtension $roles,
    ) {
    }

    #[Route('/fiches-matieres/{id}/{tab}', name: 'fiche_matiere_edit', methods: ['GET'], defaults: ['tab' => 'identite'])]
    public function edit(FicheMatiere $fiche, string $tab): Response
    {
        $this->denyHorsDiplome($fiche);

        return $this->page($fiche, $this->validTab($fiche, $tab), $this->values($fiche));
    }

    /**
     * @param array<string, mixed>       $values  valeurs affichées dans les champs
     * @param array<string, list<string>> $issues  erreurs par champ
     * @param list<array<string, mixed>>  $impacts EC dont la valeur propre serait masquée par une imposition
     */
    private function page(FicheMatiere $fiche, string $tab, array $values, array $issues = [], array $impacts = [], int $status = 200): Response
    {
        return $this->render('fiche_matiere/edit.html.twig', [
            'fiche' => $fiche,
            'tab' => $tab,
            'tabs' => $this->tabs($fiche),
            'fields' => $this->editor->fields($fiche, $tab),
            'values' => $values,
            'issues' => $issues,
            'impacts' => $impacts,
            'usages' => $this->usage->usages($fiche),
            'status' => $this->usage->status($fiche),
            'saveUrl' => $this->generateUrl('fiche_matiere_save', ['id' => $fiche->getId(), 'tab' => $tab]),
        ], new Response(status: $status));
    }

    #[Route('/fiches-matieres/{id}/{tab}', name: 'fiche_matiere_save', methods: ['POST'], defaults: ['tab' => 'identite'])]
    #[IsCsrfTokenValid(self::CSRF_ID, tokenKey: self::CSRF_FIELD)]
    public function save(FicheMatiere $fiche, string $tab, Request $request): Response
    {
        $this->denyHorsDiplome($fiche);
        // un POST vise un onglet existant pour cette fiche (pas de repli silencieux sur « identite »)
        if (!\in_array($tab, $this->tabs($fiche), true)) {
            throw $this->createNotFoundException();
        }
        $posted = $request->request->all('p');
        $issues = $this->editor->issues($fiche, $this->editor->fields($fiche, $tab), $posted);

        $impacts = [];
        if ([] === $issues) {
            $impacts = $this->editor->save($fiche, $tab, $posted, $request->request->getBoolean('confirmImpact'))->impacts;
        }

        if ('json' === $request->getPreferredFormat()) {
            return $this->json([
                'status' => $this->usage->status($fiche),
                'issues' => $issues,
                'impacts' => $impacts,
            ]);
        }

        // erreurs ou imposition à confirmer : la page est réaffichée avec la saisie, les erreurs et les EC touchés
        if ([] !== $issues || [] !== $impacts) {
            return $this->page($fiche, $tab, $posted + $this->values($fiche), $issues, $impacts, 422);
        }

        return $this->redirectToRoute('fiche_matiere_edit', ['id' => $fiche->getId(), 'tab' => $tab]);
    }

    /** Valeurs affichées : `defaults` pour les quatre clés de valeurs, `imposed` pour les cases, `data` pour le reste. */
    private function values(FicheMatiere $fiche): array
    {
        $values = $fiche->getData();
        foreach (EcValueResolver::KEYS as $key) {
            $values[$key] = $fiche->getDefault($key);
        }
        foreach (FicheMatiereEditor::IMPOSING_FIELDS_MAP as $field => $key) {
            $values[$field] = $fiche->isImposed($key) ? 'oui' : 'non';
        }

        return $values;
    }

    /** @return list<string> */
    private function tabs(FicheMatiere $fiche): array
    {
        return array_values(array_filter(
            FicheMatiereForms::TABS,
            static fn (string $t): bool => FicheMatiereForms::TAB_COMPETENCES !== $t || null !== $fiche->getFormation(),
        ));
    }

    private function validTab(FicheMatiere $fiche, string $tab): string
    {
        return \in_array($tab, $this->tabs($fiche), true) ? $tab : 'identite';
    }

    /** Une fiche hors diplôme n'est modifiable (et visible) que par l'admin. */
    private function denyHorsDiplome(FicheMatiere $fiche): void
    {
        if ($fiche->isHorsDiplome() && !$this->roles->isAdmin()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException("Fiche hors diplôme : modification réservée à l’administration.");
        }
    }
}
