<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\FicheMatiere\EcValueResolver;
use App\FicheMatiere\FicheMatiereEditor;
use App\FicheMatiere\FicheMatiereForms;
use App\FicheMatiere\FicheMatiereUsage;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Repository\FicheMatiereRepository;
use App\Twig\RoleExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/** Liste des fiches d'un parcours et page de saisie d'une fiche matière. */
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

    /**
     * Fiches du parcours : portées par lui, restées sans porteur, puis mutualisées vers lui.
     * Le paramètre `parcours` porte le nid ; son absence désigne le parcours unique.
     */
    #[Route('/formations/{fid}/fiches-matieres', name: 'fiche_matiere_index', methods: ['GET'])]
    public function index(
        #[MapEntity(mapping: ['fid' => 'id'])] Formation $formation,
        Request $request,
        Maquette $maquette,
        FicheMatiereRepository $fiches,
    ): Response {
        $nid = $request->query->get('parcours');
        $nid = \is_string($nid) && '' !== $nid ? $nid : null;
        $q = trim((string) $request->query->get('q', ''));

        $doc = $maquette->open($formation);
        $existing = array_map(static fn (TreeNode $parcours): string => $parcours->nid, $doc->parcoursNodes());
        // sans parcours en query : vue de la formation (fiches sans porteur comprises), toujours permise
        if (null !== $nid) {
            $this->assertParcoursOf($formation, $nid, $existing);
        }
        $existing[] = null;

        $matches = static fn (FicheMatiere $fiche): bool => '' === $q
            || str_contains(mb_strtolower($fiche->getLibelle()), mb_strtolower($q));
        $groups = [
            'portees' => array_filter($fiches->portedBy($formation, $nid), $matches),
            'orphelines' => array_filter($fiches->withoutPorteur($formation, $existing), $matches),
            'mutualisees' => array_filter($fiches->mutualisedTo($formation, $nid), $matches),
        ];

        $counts = [];
        foreach ($groups as $list) {
            foreach ($list as $fiche) {
                $counts[$fiche->getId()] = \count($this->usage->usages($fiche));
            }
        }

        return $this->render('fiche_matiere/index.html.twig', [
            'formation' => $formation,
            'parcours' => null === $nid ? null : $doc->node($nid),
            'parcoursNid' => $nid,
            'groups' => $groups,
            'counts' => $counts,
            'q' => $q,
            'standalone' => 'node-panel' !== $request->headers->get('Turbo-Frame'),
        ]);
    }

    #[Route('/formations/{fid}/fiches-matieres', name: 'fiche_matiere_create', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_ID, tokenKey: self::CSRF_FIELD)]
    public function create(
        #[MapEntity(mapping: ['fid' => 'id'])] Formation $formation,
        Request $request,
        EntityManagerInterface $em,
        Maquette $maquette,
    ): Response {
        $nid = $request->request->get('parcours');
        $nid = \is_string($nid) && '' !== $nid ? $nid : null;
        $this->assertParcoursOf($formation, $nid, array_map(static fn (TreeNode $p): string => $p->nid, $maquette->open($formation)->parcoursNodes()));
        $libelle = trim((string) $request->request->get('libelle', ''));
        if ('' === $libelle || mb_strlen($libelle) > 250) {
            $this->addFlash('warning', 'Le libellé de la fiche est obligatoire et ne peut pas dépasser 250 caractères.');
        } else {
            $fiche = (new FicheMatiere())
                ->setFormation($formation)
                ->setParcoursNid($nid)
                ->setData(['libelle' => $libelle]);
            $em->persist($fiche);
            $em->flush();
            $this->addFlash('success', sprintf('Fiche « %s » créée.', $libelle));
        }

        return $this->backToList($formation, $request);
    }

    #[Route('/fiches-matieres/{id}/dupliquer', name: 'fiche_matiere_duplicate', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_ID, tokenKey: self::CSRF_FIELD)]
    public function duplicate(FicheMatiere $fiche, Request $request, EntityManagerInterface $em): Response
    {
        $formation = $this->portedFormation($fiche);
        $data = $fiche->getData();
        $data['libelle'] = $fiche->getLibelle().' (copie)';
        $copy = (new FicheMatiere())
            ->setFormation($formation)
            ->setParcoursNid($fiche->getParcoursNid())
            ->setHorsDiplome($fiche->isHorsDiplome())
            ->setTypeDiplome($fiche->getTypeDiplome())
            ->setData($data)
            ->setDefaults($fiche->getDefaults());
        foreach (FicheMatiere::IMPOSABLE as $key) {
            $copy->setImposed($key, $fiche->isImposed($key));
        }
        $em->persist($copy);
        $em->flush();
        $this->addFlash('success', sprintf('Fiche « %s » dupliquée.', $copy->getLibelle()));

        return $this->backToList($formation, $request, $fiche->getParcoursNid());
    }

    #[Route('/fiches-matieres/{id}/supprimer', name: 'fiche_matiere_delete', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_ID, tokenKey: self::CSRF_FIELD)]
    public function delete(FicheMatiere $fiche, Request $request, EntityManagerInterface $em): Response
    {
        $formation = $this->portedFormation($fiche);
        $usages = $this->usage->usages($fiche);
        if ([] !== $usages) {
            $labels = array_map(static fn (array $usage): string => $usage['ec']->getDisplayLabel(), $usages);
            $this->addFlash('warning', sprintf(
                'Suppression refusée : la fiche est utilisée par les EC suivants : %s.',
                implode(', ', $labels),
            ));

            return $this->backToList($formation, $request, $fiche->getParcoursNid());
        }

        $em->remove($fiche);
        $em->flush();
        $this->addFlash('success', sprintf('Fiche « %s » supprimée.', $fiche->getLibelle()));

        return $this->backToList($formation, $request, $fiche->getParcoursNid());
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

    private function portedFormation(FicheMatiere $fiche): Formation
    {
        return $fiche->getFormation() ?? throw new NotFoundHttpException('Fiche sans formation porteuse.');
    }

    private function backToList(Formation $formation, Request $request, ?string $nid = null): Response
    {
        $nid ??= $request->query->get('parcours');
        $params = ['fid' => $formation->getId()];
        if (\is_string($nid) && '' !== $nid) {
            $params['parcours'] = $nid;
        }

        return $this->redirectToRoute('fiche_matiere_index', $params);
    }

    /**
     * Le parcours visé appartient à la formation : un nid de parcours existant, ou aucun (parcours unique)
     * pour une formation sans parcours. Sinon 404.
     *
     * @param list<string> $parcoursNids
     */
    private function assertParcoursOf(Formation $formation, ?string $nid, array $parcoursNids): void
    {
        $ok = null === $nid ? $formation->isSansParcours() : \in_array($nid, $parcoursNids, true);
        if (!$ok) {
            throw $this->createNotFoundException('Parcours inconnu dans cette formation.');
        }
    }
}
