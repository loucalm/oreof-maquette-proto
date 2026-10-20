<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Formation;
use App\Enum\FormationPhase;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Copier / coller les données d'un élément sur un autre du même type : le libellé et
 * le code ne passent pas, seuls les champs que le destinataire expose sont collés.
 */
final class NodeClipboardTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    /** @return array{0: Formation, 1: MaquetteDoc} */
    private function reload(Formation $formation): array
    {
        $this->em->clear();
        $fresh = $this->em->find(Formation::class, $formation->getId());

        return [$fresh, static::getContainer()->get(Maquette::class)->open($fresh)];
    }

    /** @return list<TreeNode> */
    private function nodesOfType(Formation $formation, string $type): array
    {
        [, $doc] = $this->reload($formation);

        return array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => $n->typeKey === $type));
    }

    private function url(Formation $formation, TreeNode $node, string $action): string
    {
        return sprintf('/formations/%d/nodes/%s/%s', $formation->getId(), $node->nid, $action);
    }

    private function fill(Formation $formation, TreeNode $node, array $attrs, ?string $label = null): void
    {
        [$fresh, $doc] = $this->reload($formation);
        $target = $doc->node($node->nid);
        $target->setAttributes($attrs + $target->getAttributes());
        if (null !== $label) {
            $target->setLabel($label);
        }
        static::getContainer()->get(Maquette::class)->save($fresh, $doc);
    }

    public function testPasteCopiesFilledDataButNeitherLabelNorCode(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, withLeaves: true);
        [$source, $target] = \array_slice($this->nodesOfType($formation, 'ec'), 0, 2);
        $this->fill($formation, $source, ['ects' => 4, 'nature' => 'obligatoire', 'hours' => ['pres' => ['cm' => 12]]], 'Algorithmique');
        $this->fill($formation, $target, ['ects' => 1], 'Autre EC');

        $this->client->request('POST', $this->url($formation, $source, 'copier'));
        $this->client->request('POST', $this->url($formation, $target, 'coller'));

        [, $doc] = $this->reload($formation);
        $pasted = $doc->node($target->nid);
        self::assertSame(4, $pasted->getAttribute('ects'));
        self::assertSame('obligatoire', $pasted->getAttribute('nature'));
        self::assertSame(['pres' => ['cm' => 12]], $pasted->getAttribute('hours'));
        self::assertSame('Autre EC', $pasted->getLabel(), 'le libellé ne se colle pas');
    }

    public function testPasteRefusedOnAnotherElementType(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, withLeaves: true);
        [$ec] = $this->nodesOfType($formation, 'ec');
        [$ue] = $this->nodesOfType($formation, 'ue');
        $this->fill($formation, $ec, ['ects' => 4]);

        $this->client->request('POST', $this->url($formation, $ec, 'copier'));
        $this->client->request('POST', $this->url($formation, $ue, 'coller'));

        [, $doc] = $this->reload($formation);
        self::assertNull($doc->node($ue->nid)->getAttribute('ects'));
    }

    public function testPasteRefusedDuringConsolidationAndWithEmptyClipboard(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, withLeaves: true);
        [$source, $target] = \array_slice($this->nodesOfType($formation, 'ec'), 0, 2);
        $this->fill($formation, $source, ['ects' => 4]);

        // presse-papiers vide
        $this->client->request('POST', $this->url($formation, $target, 'coller'));
        [, $doc] = $this->reload($formation);
        self::assertNull($doc->node($target->nid)->getAttribute('ects'));

        // consolidation : le contenu attend la construction
        $this->client->request('POST', $this->url($formation, $source, 'copier'));
        [$fresh] = $this->reload($formation);
        $fresh->setPhase(FormationPhase::Consolidation);
        $this->em->flush();
        $this->client->request('POST', $this->url($formation, $target, 'coller'));
        [, $doc] = $this->reload($formation);
        self::assertNull($doc->node($target->nid)->getAttribute('ects'));
    }

    public function testPanelOffersPasteOnlyWhenCompatible(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, withLeaves: true);
        [$source, $other] = \array_slice($this->nodesOfType($formation, 'ec'), 0, 2);
        [$ue] = $this->nodesOfType($formation, 'ue');
        $this->fill($formation, $source, ['ects' => 4]);

        $this->client->request('POST', $this->url($formation, $source, 'copier'));

        $this->client->request('GET', rtrim($this->url($formation, $other, ''), '/'));
        self::assertSelectorExists('form[action$="/coller"] button.panel-move-btn--ready:not([disabled])');

        $this->client->request('GET', rtrim($this->url($formation, $ue, ''), '/'));
        self::assertSelectorExists('form[action$="/coller"] button[disabled]');
    }
}
