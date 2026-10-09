<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Formation;
use App\Maquette\AttributeCatalog;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\MaquetteBuilder;
use App\Maquette\NodeClipboard;
use App\Repository\NodeTypeRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nœuds de la structure pédagogique. Le nœud n'est plus une ligne en base :
 * il vit dans le document JSON `Formation::arbre`, chargé/persisté via le
 * service App\Maquette\Maquette. Les routes sont donc portée-formation :
 * `/formations/{fid}/nodes/{nid}`.
 */
final class NodeController extends AbstractController
{
    public function __construct(
        private readonly Maquette $maquette,
        private readonly MaquetteBuilder $builder,
        private readonly AttributeCatalog $catalog,
    ) {
    }

    #[Route('/formations/{fid}/nodes/{nid}', name: 'node_panel', methods: ['GET'])]
    public function panel(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $node = $this->node($formation, $nid);

        return $this->render('node/panel.html.twig', [
            'view' => $this->builder->locate($formation, $nid) ?? $this->builder->buildSubtree($node),
            'node' => $node,
            'catalog' => $this->catalog->all(),
            'domains' => $this->catalog->domains(),
        ]);
    }

    /** Ouvre l'éditeur (formation ou parcours) sur ce nœud — lien partageable, ex. depuis « Vérifier ». */
    #[Route('/formations/{fid}/nodes/{nid}/ouvrir', name: 'node_open', methods: ['GET'])]
    public function open(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        return $this->editorRedirect($this->pick($this->maquette->open($formation), $nid));
    }

    #[Route('/formations/{fid}/nodes/{nid}/parametres', name: 'node_params_form', methods: ['GET'])]
    public function paramsForm(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $node = $this->node($formation, $nid);

        return $this->render('node/_params_form.html.twig', [
            'node' => $node,
            'view' => $this->builder->buildSubtree($node),
        ]);
    }

    #[Route('/formations/{fid}/nodes/{nid}', name: 'node_save', methods: ['POST'])]
    public function save(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);

        // le libellé d'un type temporel est calculé (jamais saisi) : ne pas l'écraser
        if (!$node->getType()->isTemporel()) {
            $node->setLabel(trim((string) $request->request->get('label')));
        }
        $node->setCode(trim((string) $request->request->get('code')) ?: null);

        if ($request->request->has('parentId')) {
            $targetNid = trim((string) $request->request->get('parentId'));
            $target = $targetNid !== '' ? $doc->node($targetNid) : null;
            if ($target !== $node->getParent()
                && !$node->isLocked('move')
                && !$this->isDescendant($target, $node)
                && $this->reparentAllowed($node, $target)) {
                $doc->moveNode($node, $target, PHP_INT_MAX);
            }
        }

        $attrs = $node->getAttributes();
        $caps = $node->effectiveCapabilities();
        // consolidation : squelette seulement (noms, codes, emplacement) — le contenu attend la construction
        $catalog = $formation->getPhase() === \App\Enum\FormationPhase::Consolidation ? [] : $this->catalog->all();
        foreach ($catalog as $key => $def) {
            if (!($caps[$key] ?? false)) {
                unset($attrs[$key]);
                continue;
            }
            $attrs[$key] = match ($def['field']) {
                'number' => $this->numOrNull($request->request->get("attr_$key")),
                'hours' => $this->readHours($request),
                'mccc' => $this->readMccc($request),
                'competencies' => array_values(array_filter(
                    array_map(static fn ($v) => trim((string) $v), $request->request->all('attr_competencies')),
                )),
                'checkbox' => array_values(array_filter(
                    array_map(static fn ($v) => trim((string) $v), $request->request->all("attr_$key")),
                )),
                // « valeur libre » : prioritaire sur la sélection si renseignée (choice/radio adossés à un référentiel avec allowExtra)
                default => ($def['allowExtra'] ?? false) && '' !== trim((string) $request->request->get("attr_{$key}_autre"))
                    ? trim((string) $request->request->get("attr_{$key}_autre"))
                    : (trim((string) $request->request->get("attr_$key")) ?: null),
            };
        }

        if ($node->isParcours()) {
            unset($attrs['anneeDebut'], $attrs['anneeFin']);
            // périodes éditées depuis l'arborescence des parcours (formation) : ce
            // formulaire ne les soumet plus, ne pas les effacer si absentes.
            foreach (['periodeDebut', 'periodeFin'] as $k) {
                if (!$request->request->has("attr_$k")) {
                    continue;
                }
                $v = $request->request->get("attr_$k");
                if ($v === null || $v === '') {
                    unset($attrs[$k]);
                } else {
                    $attrs[$k] = max(1, (int) $v);
                }
            }
        }

        $node->setAttributes(array_filter($attrs, static fn ($v) => $v !== null && $v !== '' && $v !== []));

        if ($node->isParcours() && $request->request->has('parcoursParentId')) {
            $this->applyParcoursParent($doc, $node, trim((string) $request->request->get('parcoursParentId')));
        }

        $this->maquette->save($formation, $doc);

        return $this->backToEditor($node, 'ELP enregistré.');
    }

    /**
     * Ramification centralisée : périodes + parcours parent de TOUS les
     * parcours de la formation, en un seul enregistrement (tableau de
     * l'arborescence des parcours, ou glisser-déposer sur son graphe — les
     * deux ne font que remplir les mêmes champs de ce formulaire unique).
     * Périodes d'abord (pour que la validation des parents s'appuie sur des
     * périodes à jour), puis parents via applyParcoursParent() — mêmes règles,
     * mêmes messages, qu'un enregistrement individuel d'avant.
     */
    #[Route('/formations/{fid}/parcours-ramification', name: 'node_ramification_save', methods: ['POST'])]
    public function ramificationSave(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, Request $request): Response
    {
        $doc = $this->maquette->open($formation);
        $periodesDebut = $request->request->all('periodeDebut');
        $periodesFin = $request->request->all('periodeFin');
        $parents = $request->request->all('parentId');

        foreach ($doc->parcoursNodes() as $p) {
            $nid = $p->getId();
            $attrs = $p->getAttributes();
            if (isset($periodesDebut[$nid]) && '' !== $periodesDebut[$nid]) {
                $attrs['periodeDebut'] = max(1, (int) $periodesDebut[$nid]);
            }
            if (isset($periodesFin[$nid]) && '' !== $periodesFin[$nid]) {
                $attrs['periodeFin'] = max(1, (int) $periodesFin[$nid]);
            }
            $p->setAttributes(array_filter($attrs, static fn ($v) => $v !== null && $v !== '' && $v !== []));
        }

        foreach ($doc->parcoursNodes() as $p) {
            $this->applyParcoursParent($doc, $p, trim((string) ($parents[$p->getId()] ?? '')));
        }

        $this->maquette->save($formation, $doc);
        $this->addFlash('success', 'Ramification des parcours enregistrée.');

        return $this->redirectToRoute('formation_parcours_graph', ['id' => $formation->getId()]);
    }

    #[Route('/formations/{fid}/nodes', name: 'node_add', methods: ['POST'])]
    public function add(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, Request $request, NodeTypeRepository $types): Response
    {
        $doc = $this->maquette->open($formation);
        $parentNid = trim((string) $request->request->get('parentId'));
        $parent = $parentNid !== '' ? $doc->node($parentNid) : null;

        $typeKey = trim((string) $request->request->get('typeKey'));
        if ('' === $typeKey || 'auto' === $typeKey) {
            $typeKey = null !== $parent
                ? ($parent->allowedChildTypeKeys()[0] ?? null)
                : $formation->getVisibleRootTypeKey();
        }

        // toujours revalidé (même quand typeKey vient explicitement du client, ex. « Bloc de choix ») :
        // un nœud imbriqué dans des blocs de choix n'a pas forcément le même type prévu qu'à la racine.
        $allowed = null !== $typeKey && (null !== $parent ? $parent->acceptsChildType($typeKey) : $formation->canBeRootType($typeKey));
        $type = $allowed ? $types->findOneByKey($typeKey) : null;
        if ($type === null) {
            $this->addFlash('warning', null !== $parent
                ? sprintf('« %s » ne peut pas contenir d’enfant.', $parent->getDisplayLabel())
                : 'Définissez d’abord le squelette dans « Configuration de la structure ».');

            return null !== $parent
                ? $this->editorRedirect($parent)
                : $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
        }

        // structure imposée : un nœud existant de ce type à cet emplacement peut fermer la position à tout autre ajout
        $siblings = $parent?->getChildren() ?? $doc->roots;
        foreach ($siblings as $sibling) {
            if ($sibling->typeKey === $typeKey && $sibling->isLocked('add')) {
                $this->addFlash('warning', sprintf(
                    'La structure imposée par le diplôme ne permet pas d’ajouter un autre « %s » ici.',
                    $type->getLabel(),
                ));

                return null !== $parent
                    ? $this->editorRedirect($parent)
                    : $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'param' => 'structure']);
            }
        }

        $node = $doc->addNode($parent?->getId(), $typeKey, trim((string) $request->request->get('label')));
        // « ajouter après » (depuis une feuille) : placé juste derrière le frère de référence
        $after = $doc->node(trim((string) $request->request->get('afterId')));
        if (null !== $after && $after->getParent() === $parent) {
            $siblings = $parent?->getChildren() ?? $doc->roots;
            $index = array_search($after, $siblings, true);
            if (false !== $index) {
                $doc->moveNode($node, $parent, $index + 1);
            }
        }
        $this->applyChoiceNature($node, $parent);
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('%s ajouté.', $type->getLabel()));
        $this->offerUndo($formation);

        return $this->editorRedirect($node);
    }

    #[Route('/formations/{fid}/nodes/{nid}/move', name: 'node_move', methods: ['POST'])]
    public function move(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): JsonResponse
    {
        $doc = $this->maquette->open($formation);
        $node = $doc->node($nid);
        if ($node === null) {
            return new JsonResponse(['ok' => false, 'error' => 'not-found'], 404);
        }

        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        $newParent = !empty($payload['parentId']) ? $doc->node((string) $payload['parentId']) : null;

        if ($this->isDescendant($newParent, $node)) {
            return new JsonResponse(['ok' => false, 'error' => 'cycle'], 422);
        }
        if (!$this->reparentAllowed($node, $newParent)) {
            return new JsonResponse(['ok' => false, 'error' => 'hierarchy'], 422);
        }
        // nœud imposé : réordonnancement entre frères OK, changement de parent refusé
        if ($node->isLocked('move') && $newParent !== $node->getParent()) {
            return new JsonResponse(['ok' => false, 'error' => 'locked'], 422);
        }

        $doc->moveNode($node, $newParent, (int) ($payload['index'] ?? 0));
        $this->applyChoiceNature($node, $newParent);
        $this->maquette->save($formation, $doc);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Monte / descend un nœud d'un cran parmi ses frères de même famille
     * (alternative clavier au glisser-déposer). Autorisé sur un nœud imposé :
     * seul le changement de parent lui est interdit.
     */
    #[Route('/formations/{fid}/nodes/{nid}/shift/{dir}', name: 'node_shift', methods: ['POST'], requirements: ['dir' => 'up|down'])]
    public function shift(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, string $dir): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);
        $parent = $node->getParent();
        $all = $parent?->getChildren() ?? $doc->roots;
        $peers = array_values(array_filter(
            $all,
            static fn (TreeNode $n) => $n->isCompetenceNode() === $node->isCompetenceNode(),
        ));

        $pos = array_search($node, $peers, true);
        $target = $peers[$pos + ('up' === $dir ? -1 : 1)] ?? null;
        if (null === $target) {
            return $this->editorRedirect($node);
        }

        // index dans la liste complète AVANT détachement : monter = prendre la place
        // du précédent ; descendre = se poser après le suivant (qui recule d'un cran)
        $doc->moveNode($node, $parent, (int) array_search($target, $all, true));
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('« %s » déplacé.', $node->getDisplayLabel()));
        $this->offerUndo($formation);

        return $this->editorRedirect($node);
    }

    #[Route('/formations/{fid}/nodes/{nid}/duplicate', name: 'node_duplicate', methods: ['POST'])]
    public function duplicate(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);

        if ($node->isLocked('duplicate')) {
            $this->addFlash('warning', sprintf(
                '« %s » fait partie de la structure imposée par le diplôme : il ne peut pas être dupliqué.',
                $node->getDisplayLabel(),
            ));

            return $this->editorRedirect($node);
        }

        $copy = $doc->duplicateNode($node);
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('« %s » dupliqué.', $node->getDisplayLabel()));
        $this->offerUndo($formation);

        return $this->editorRedirect($copy);
    }

    #[Route('/formations/{fid}/nodes/{nid}/delete', name: 'node_delete', methods: ['POST'])]
    public function delete(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);

        if ($node->isLocked('delete')) {
            $this->addFlash('warning', sprintf(
                '« %s » fait partie de la structure imposée par le diplôme : il ne peut pas être supprimé.',
                $node->getDisplayLabel(),
            ));

            return $this->editorRedirect($node);
        }

        $parcours = $this->parcoursAncestor($node);
        if (!$doc->removeNode($node)) {
            $this->addFlash('warning', sprintf(
                '« %s » contient un élément imposé par le diplôme : il ne peut pas être supprimé.',
                $node->getDisplayLabel(),
            ));

            return $this->editorRedirect($node);
        }
        $this->maquette->save($formation, $doc);
        $this->addFlash('info', 'ELP supprimé.');
        $this->offerUndo($formation);

        return $parcours !== null && $parcours !== $node
            ? $this->redirectToRoute('parcours_editor', ['fid' => $formation->getId(), 'nid' => $parcours->getId()])
            : $this->redirectToRoute('formation_editor', ['id' => $formation->getId()]);
    }

    #[Route('/formations/{fid}/nodes/{nid}/mutualize', name: 'node_mutualize', methods: ['POST'])]
    public function mutualize(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);
        $node->setMutualized(!$node->isMutualized());
        $this->maquette->save($formation, $doc);

        return $this->backToEditor($node, $node->isMutualized() ? 'ELP mutualisé.' : 'ELP retiré de la mutualisation.');
    }

    #[Route('/formations/{fid}/nodes/{nid}/params', name: 'node_params', methods: ['POST'])]
    public function params(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, Request $request): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);

        $checked = $request->request->all('capabilities');
        $overrides = $node->getCapabilityOverrides() ?? [];
        foreach (array_keys($node->getType()->getCapabilities()) as $cap) {
            if ($node->getType()->isCapabilityLocked($cap)) {
                unset($overrides[$cap]);
                continue;
            }
            $overrides[$cap] = \in_array($cap, $checked, true);
        }
        foreach ($overrides as $cap => $val) {
            if ($val === $node->getType()->capabilityDefault($cap)) {
                unset($overrides[$cap]);
            }
        }
        $node->setCapabilityOverrides($overrides === [] ? null : $overrides);
        $this->maquette->save($formation, $doc);

        return $this->backToEditor($node, 'Paramètre de l’ELP enregistré.');
    }

    #[Route('/formations/{fid}/nodes/{nid}/raccrocher', name: 'node_attach_index', methods: ['GET'])]
    public function attachIndex(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $node = $this->node($formation, $nid);
        $childKey = $formation->getChildTypeKey($node->getEffectiveHostTypeKey());
        $candidates = $childKey === null ? [] : $this->maquette->findMutualized($formation, $childKey);

        return $this->render('node/attach.html.twig', ['node' => $node, 'candidates' => $candidates]);
    }

    #[Route('/formations/{fid}/nodes/{nid}/raccrocher/{sfid}/{snid}', name: 'node_attach', methods: ['POST'])]
    public function attach(
        #[MapEntity(mapping: ['fid' => 'id'])] Formation $formation,
        string $nid,
        #[MapEntity(mapping: ['sfid' => 'id'])] Formation $sourceFormation,
        string $snid,
    ): Response {
        $doc = $this->maquette->open($formation);
        $target = $this->pick($doc, $nid);

        $source = $this->maquette->open($sourceFormation)->node($snid);
        if ($source === null) {
            throw $this->createNotFoundException();
        }

        $copy = $doc->importSubtree($source, $target);
        $copy->setMutualizedFrom($sourceFormation->getId().':'.$snid);
        $copy->setAttribute('_sourceHash', $source->contentHash());
        if (\App\Enum\FormationPhase::Consolidation === $formation->getPhase()) {
            $this->stripContent($copy); // consolidation : squelette seulement
        }
        $this->applyChoiceNature($copy, $target);
        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('« %s » raccroché depuis « %s ».', $source->getDisplayLabel(), $sourceFormation->getName()));

        return $this->backToEditor($copy, '');
    }

    /**
     * Transforme un ELP existant en bloc de choix : il devient la première
     * alternative d'un nouveau bloc de choix créé à sa place exacte (même
     * parent, même position). Répond à l'attente naturelle qu'a un
     * responsable de formation en cochant « À choix restreint » sur un ELP
     * déjà en place — la case seule ne crée aucune structure, ce geste le fait.
     */
    /**
     * Remplace une copie mutualisée par l'état actuel de sa source (même
     * emplacement). Les modifications locales de la copie sont perdues.
     */
    #[Route('/formations/{fid}/nodes/{nid}/resynchroniser', name: 'node_attach_refresh', methods: ['POST'])]
    public function refreshFromSource(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);
        $info = $this->maquette->mutualizationSource($node);
        if (null === $info || null === $info['node']) {
            $this->addFlash('warning', 'La source de cet élément est introuvable (supprimée ?).');

            return $this->editorRedirect($node);
        }

        $parent = $node->getParent();
        $siblings = $parent?->getChildren() ?? $doc->roots;
        $index = (int) array_search($node, $siblings, true);
        $from = $node->getMutualizedFrom();

        $copy = $doc->importSubtree($info['node'], $parent);
        $copy->setMutualizedFrom($from);
        $copy->setAttribute('_sourceHash', $info['node']->contentHash());
        $copy->locked = $node->locked; // un emplacement imposé le reste après mise à jour
        $doc->moveNode($copy, $parent, $index);
        $doc->removeNode($node, true);
        $this->applyChoiceNature($copy, $parent);
        $this->maquette->save($formation, $doc, sprintf('« %s » mis à jour depuis sa source', $copy->getDisplayLabel()));

        return $this->backToEditor($copy, 'Copie mise à jour depuis la source.');
    }

    /**
     * « Copier » : retient les données remplies de l'élément (hors libellé et code) pour les
     * coller sur un autre élément du même type. Ne modifie rien : pas d'historique ni d'annulation.
     */
    #[Route('/formations/{fid}/nodes/{nid}/copier', name: 'node_copy', methods: ['POST'])]
    public function copy(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, NodeClipboard $clipboard): Response
    {
        $node = $this->pick($this->maquette->open($formation), $nid);
        $clip = $clipboard->copy($node);
        $this->addFlash('info', [] === $clip['attributes']
            ? sprintf('« %s » ne contient encore aucune donnée à copier.', $node->getDisplayLabel())
            : sprintf('Données de « %s » copiées (%d champ(s)). Ouvrir un autre %s et « Coller ».', $node->getDisplayLabel(), \count($clip['attributes']), $clip['typeLabel']));

        return $this->editorRedirect($node);
    }

    /** « Coller » : applique les données copiées sur cet élément (même type d'ELP seulement). */
    #[Route('/formations/{fid}/nodes/{nid}/coller', name: 'node_paste', methods: ['POST'])]
    public function paste(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid, NodeClipboard $clipboard): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);

        if (null !== ($reason = $clipboard->blockedReason($node))) {
            $this->addFlash('warning', match ($reason) {
                NodeClipboard::BLOCKED_EMPTY => 'Rien à coller : copier d\'abord les données d\'un élément.',
                NodeClipboard::BLOCKED_TYPE => sprintf('Les données copiées viennent d\'un « %s » : elles ne se collent que sur le même type d\'élément.', $clipboard->get()['typeLabel'] ?? '?'),
                NodeClipboard::BLOCKED_PHASE => 'Consolidation de l\'offre : seul le squelette est modifiable, le contenu se colle à la construction.',
                NodeClipboard::BLOCKED_SELF => 'Ce sont les données de cet élément : rien à coller.',
                default => 'Aucun champ copié n\'est utilisé par cet élément.',
            });

            return $this->editorRedirect($node);
        }

        $from = $clipboard->get()['label'] ?? '';
        $count = $clipboard->paste($node);
        $this->maquette->save($formation, $doc, sprintf('Données de « %s » collées sur « %s »', $from, $node->getDisplayLabel()));

        return $this->backToEditor($node, sprintf('%d champ(s) collé(s) depuis « %s ».', $count, $from));
    }

    #[Route('/formations/{fid}/nodes/{nid}/convertir-choix', name: 'node_convert_choice', methods: ['POST'])]
    public function convertToChoice(#[MapEntity(mapping: ['fid' => 'id'])] Formation $formation, string $nid): Response
    {
        $doc = $this->maquette->open($formation);
        $node = $this->pick($doc, $nid);
        $parent = $node->getParent();

        if ($parent === null || $node->isChoiceBloc() || !$parent->acceptsChildType('bloc_choix')) {
            $this->addFlash('warning', sprintf('« %s » ne peut pas être transformé en bloc de choix ici.', $node->getDisplayLabel()));

            return $this->editorRedirect($node);
        }

        // ECTS EFFECTIF de l'ELP avant tout déplacement — pas sa valeur brute,
        // déjà ignorée s'il a des enfants (elle vaudrait alors la somme de ceux-ci,
        // pas le champ « ects » stocké dessus). C'est cette valeur affichée
        // jusqu'ici qu'il faut reporter sur le bloc, pour que la restructuration
        // ne fasse pas à elle seule bouger les totaux déjà remontés plus haut.
        $effectiveEcts = $this->builder->buildSubtree($node)->totalEcts;

        $index = $node->getPosition();
        $bloc = $doc->addNode($parent->getId(), 'bloc_choix', '');
        if ($bloc->can('ects') && $effectiveEcts > 0) {
            $bloc->setAttribute('ects', $effectiveEcts);
        }
        if ($bloc->can('choiceCount')) {
            $bloc->setAttribute('choiceCount', 1);
        }
        $doc->moveNode($bloc, $parent, $index);
        $doc->moveNode($node, $bloc, 0);
        $this->applyChoiceNature($node, $bloc);

        $this->maquette->save($formation, $doc);
        $this->addFlash('success', sprintf('« %s » transformé en bloc de choix — ajoutez ses autres alternatives.', $node->getDisplayLabel()));

        return $this->editorRedirect($bloc);
    }

    // ─── helpers ──────────────────────────────────────────────

    private function node(Formation $formation, string $nid): TreeNode
    {
        return $this->pick($this->maquette->open($formation), $nid);
    }

    private function pick(MaquetteDoc $doc, string $nid): TreeNode
    {
        $node = $doc->node($nid);
        if ($node === null) {
            throw $this->createNotFoundException();
        }

        return $node;
    }

    private function parcoursAncestor(TreeNode $node): ?TreeNode
    {
        for ($c = $node; $c !== null; $c = $c->getParent()) {
            if ($c->isParcours()) {
                return $c;
            }
        }

        return null;
    }

    private function editorRedirect(TreeNode $node): Response
    {
        $formation = $node->getFormation();
        for ($c = $node; $c !== null; $c = $c->getParent()) {
            if ($c->isParcours()) {
                return $this->redirectToRoute('parcours_editor', [
                    'fid' => $formation->getId(), 'nid' => $c->getId(), 'focus' => $node->getId(),
                ]);
            }
        }

        return $this->redirectToRoute('formation_editor', ['id' => $formation->getId(), 'focus' => $node->getId()]);
    }

    private function backToEditor(TreeNode $node, string $message): Response
    {
        if ($message !== '') {
            $this->addFlash('success', $message);
            $this->offerUndo($node->getFormation());
        }

        return $this->editorRedirect($node);
    }

    /** Retire les attributs de contenu (ECTS, heures, MCCC…) d'un sous-arbre ; garde les attributs internes « _… ». */
    private function stripContent(TreeNode $node): void
    {
        $node->setAttributes(array_filter($node->getAttributes(), static fn ($k) => str_starts_with((string) $k, '_'), \ARRAY_FILTER_USE_KEY));
        foreach ($node->getChildren() as $child) {
            $this->stripContent($child);
        }
    }

    /** Le toast de confirmation proposera « Annuler » (dernière révision de cette formation). */
    private function offerUndo(?Formation $formation): void
    {
        if (null !== $formation?->getId()) {
            $this->addFlash('undo', (string) $formation->getId());
        }
    }

    private function reparentAllowed(TreeNode $node, ?TreeNode $newParent): bool
    {
        $formation = $node->getFormation();
        $typeKey = $node->getType()->getKey();

        return $newParent === null
            ? $formation->canBeRootType($typeKey)
            : $newParent->acceptsChildType($typeKey);
    }

    /**
     * Devenir enfant direct d'un bloc de choix, c'est devenir une alternative
     * « à choix restreint » — posé par défaut à chaque fois qu'un ELP entre
     * dans un bloc de choix (création, glisser-déposer, raccrochement),
     * pour que les deux notions restent cohérentes sans geste supplémentaire.
     */
    private function applyChoiceNature(TreeNode $node, ?TreeNode $parent): void
    {
        if (null !== $parent && $parent->isChoiceBloc() && $node->can('nature')) {
            $node->setAttribute('nature', 'choix_restreint');
        }
    }

    private function applyParcoursParent(MaquetteDoc $doc, TreeNode $node, string $parentNid): void
    {
        if ($parentNid === '') {
            $node->setParcoursParent(null);

            return;
        }

        $pp = $doc->node($parentNid);
        if ($pp === null || $pp === $node || !$pp->isParcours() || $this->isParcoursDescendant($pp, $node)) {
            return;
        }

        if (!TreeNode::parcoursPeriodsAllowChild($pp->getPeriodeDebut(), $pp->getPeriodeFin(), $node->getPeriodeDebut(), $node->getPeriodeFin())) {
            $this->addFlash('warning', sprintf(
                'Ramification ignorée : « %s » couvre les périodes %d–%d. Un parcours enfant doit commencer après la période %d et se prolonger au moins jusqu’à la période %d.',
                $pp->getDisplayLabel(),
                $pp->getPeriodeDebut(),
                $pp->getPeriodeFin(),
                $pp->getPeriodeDebut(),
                $pp->getPeriodeFin(),
            ));

            return;
        }

        $node->setParcoursParent($pp);
    }

    private function isDescendant(?TreeNode $candidate, TreeNode $of): bool
    {
        for ($cursor = $candidate; $cursor !== null; $cursor = $cursor->getParent()) {
            if ($cursor === $of) {
                return true;
            }
        }

        return false;
    }

    private function isParcoursDescendant(TreeNode $candidate, TreeNode $of): bool
    {
        $seen = [];
        for ($cursor = $candidate; $cursor !== null && !isset($seen[$cursor->getId()]); $cursor = $cursor->getParcoursParent()) {
            if ($cursor === $of) {
                return true;
            }
            $seen[$cursor->getId()] = true;
        }

        return false;
    }

    private function numOrNull(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    /**
     * @return array<string, mixed>
     */
    private function readHours(Request $request): array
    {
        $raw = $request->request->all('attr_hours');
        if (!empty($raw['none'])) {
            return ['none' => true];
        }

        $out = [];
        foreach (array_keys(AttributeCatalog::HOUR_PLACES) as $p) {
            foreach (array_keys(AttributeCatalog::HOUR_MODALITIES) as $m) {
                $val = (float) ($raw[$p][$m] ?? 0);
                if ($val > 0) {
                    $out[$p][$m] = $val;
                }
            }
        }
        $te = (float) ($raw['te'] ?? 0);
        if ($te > 0) {
            $out['te'] = $te;
        }

        return $out;
    }

    /**
     * MCCC : le type choisi, et si ce type comporte des épreuves à pondérer,
     * la liste des épreuves saisies (`attr_mccc[evaluations][][type|weight]`).
     * Les lignes vides (ni type, ni coefficient) sont ignorées.
     *
     * @return array<string, mixed>
     */
    private function readMccc(Request $request): array
    {
        $raw = $request->request->all('attr_mccc');
        $type = trim((string) ($raw['type'] ?? ''));
        if ('' === $type) {
            return [];
        }

        $out = ['type' => $type];
        $evaluations = [];
        foreach ((array) ($raw['evaluations'] ?? []) as $row) {
            $etype = \is_array($row) ? trim((string) ($row['type'] ?? '')) : '';
            $weight = \is_array($row) ? (float) str_replace(',', '.', (string) ($row['weight'] ?? '')) : 0.0;
            $dureeRaw = \is_array($row) ? trim((string) ($row['duree'] ?? '')) : '';
            if ('' === $etype && $weight <= 0 && '' === $dureeRaw) {
                continue;
            }
            $entry = ['type' => $etype, 'weight' => $weight];
            if ('' !== $dureeRaw) {
                $entry['duree'] = (float) str_replace(',', '.', $dureeRaw);
            }
            $evaluations[] = $entry;
        }
        if ([] !== $evaluations) {
            $out['evaluations'] = $evaluations;
        }

        return $out;
    }
}
