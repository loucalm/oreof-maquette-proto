<?php

declare(strict_types=1);

namespace App\Tests\Formulaire;

use App\Entity\FieldDef;
use App\Entity\NodeType;
use App\Enum\NodeFamily;
use App\Formulaire\FormResolver;
use App\Formulaire\ResolvedField;
use App\Repository\FieldDefRepository;
use App\Repository\NodeTypeRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Validation\TabValidator;
use App\Validation\ValidationRule;

final class FormResolverTest extends DatabaseTestCase
{
    private FormResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = static::getContainer()->get(FormResolver::class);
    }

    public function testSocleFieldsFollowCapabilitiesAndFieldDefDefaults(): void
    {
        $fields = $this->resolver->fields($this->form('param_presentation_formation'));

        self::assertSame(['objectif', 'resultats', 'contenu', 'sep_pres_rythme', 'rythme', 'rythmePrecision'], $this->keys($fields));
        self::assertSame($this->fieldDef('objectif')->getLabel(), $fields[0]->label);
        self::assertSame([ResolvedField::INHERITED], $fields[0]->origins);
    }

    public function testSocleSettingOverridesOnlyThatForm(): void
    {
        $this->form('param_presentation_formation')->setFieldSetting('objectif', ['label' => 'But']);

        self::assertSame('But', $this->find($this->form('param_presentation_formation'), 'objectif')->label);
        self::assertSame($this->fieldDef('objectif')->getLabel(), $this->find($this->form('param_presentation_parcours'), 'objectif')->label);
        self::assertSame('Objectif', $this->fieldDef('objectif')->getLabel());
    }

    public function testVariantInheritsAndStoresOnlyDifferences(): void
    {
        $v = $this->variantOf('param_presentation_formation');
        $v->setCapabilities(['contenu' => false, 'motsCles' => true])->setFieldSetting('rythme', ['required' => true]);

        $keys = $this->keys($this->resolver->fields($v));
        self::assertNotContains('contenu', $keys);
        self::assertContains('motsCles', $keys);
        self::assertTrue($this->find($v, 'rythme')->required);
        self::assertSame([ResolvedField::INHERITED], $this->find($v, 'objectif')->origins);
        self::assertSame([ResolvedField::ADDED], $this->find($v, 'motsCles')->origins);
        self::assertContains(ResolvedField::REQUIRED, $this->find($v, 'rythme')->origins);
        self::assertTrue($this->find($v, 'contenu', true)->hidden);
        self::assertContains(ResolvedField::HIDDEN, $this->find($v, 'contenu', true)->origins);
    }

    public function testFieldAddedToParentAppearsInVariant(): void
    {
        $parent = $this->form('param_presentation_formation');
        $v = $this->variantOf('param_presentation_formation');
        self::assertSame([], $this->resolver->newParentFields($v));

        $parent->setCapabilities($parent->getCapabilities() + ['motsCles' => true]);

        self::assertContains('motsCles', $this->keys($this->resolver->fields($v)));
        self::assertSame(['motsCles'], $this->keys($this->resolver->newParentFields($v)));
        $this->resolver->acknowledgeParentFields($v);
        self::assertSame([], $this->resolver->newParentFields($v));
    }

    public function testFieldRemovedFromParentDisappearsAndOrphanSettingIgnored(): void
    {
        $parent = $this->form('param_presentation_formation');
        $v = $this->variantOf('param_presentation_formation');
        $v->setFieldSetting('contenu', ['label' => 'Programme']);

        $caps = $parent->getCapabilities();
        unset($caps['contenu']);
        $parent->setCapabilities($caps);

        self::assertNotContains('contenu', $this->keys($this->resolver->fields($v, true)));
    }

    public function testLockedFieldCannotBeHiddenOrMadeOptional(): void
    {
        $v = $this->variantOf('param_organisation_formation');
        $v->setCapabilities(['name' => false])->setFieldSetting('respMention', ['required' => false]);

        self::assertContains('name', $this->keys($this->resolver->fields($v)));
        self::assertTrue($this->find($v, 'respMention')->required);
    }

    public function testLockedFieldMadeRequiredByParentStaysRequiredInVariant(): void
    {
        $this->fieldDef('respMention')->setRequired(false);
        $this->form('param_organisation_formation')->setFieldSetting('respMention', ['required' => true]);
        $v = $this->variantOf('param_organisation_formation');
        $v->setFieldSetting('respMention', ['required' => false]);

        self::assertTrue($this->find($v, 'respMention')->required);
    }

    public function testVariantOfVariantResolvesRecursively(): void
    {
        $v1 = $this->variantOf('param_presentation_formation');
        $v1->setFieldSetting('objectif', ['label' => 'But']);
        $v2 = $this->variantOf($v1);
        $v2->setCapabilities(['contenu' => false]);

        self::assertSame('But', $this->find($v2, 'objectif')->label);
        self::assertNotContains('contenu', $this->keys($this->resolver->fields($v2)));
        self::assertSame(['param_presentation_formation', $v1->getKey(), $v2->getKey()], array_map(static fn (NodeType $t) => $t->getKey(), $this->resolver->lineage($v2)));
    }

    public function testCycleIsRefused(): void
    {
        $v1 = $this->variantOf('param_presentation_formation');
        $v2 = $this->variantOf($v1);

        $this->expectException(\DomainException::class);
        $v1->setParent($v2);
    }

    public function testParentOfAnotherFamilyIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->variantOf('param_presentation_formation')->setParent($this->form('ue'));
    }

    public function testVariantValidationRulesAddToParents(): void
    {
        $v = $this->variantOf('param_organisation_formation');
        $v->addValidationRule(new ValidationRule('contact', ValidationRule::ONE_OF, 'organisation', ['contacts', 'mention']));

        self::assertSame(['rncp_code', 'contact'], array_map(static fn (ValidationRule $r) => $r->id, $this->resolver->validationRules($v)));
    }

    public function testRequiredKeysOverrideFieldDefInTabValidator(): void
    {
        $validator = static::getContainer()->get(TabValidator::class);
        $form = $this->form('param_organisation_formation');
        $value = static fn (string $k): ?string => 'rncp' === $k ? 'oui' : null;

        // codeRncp facultatif : la règle « obligatoire si RNCP = oui » s'applique
        self::assertSame(1, $validator->check($form, $value, ['rncp', 'codeRncp'])['units']);
        // codeRncp rendu obligatoire par le formulaire : la règle est sans objet
        self::assertSame(0, $validator->check($form, $value, ['rncp', 'codeRncp'], ['rncp', 'codeRncp'])['units']);
    }

    private function form(string $key): NodeType
    {
        return static::getContainer()->get(NodeTypeRepository::class)->findOneByKey($key);
    }

    private function fieldDef(string $key): FieldDef
    {
        return static::getContainer()->get(FieldDefRepository::class)->findOneByKey($key);
    }

    private function variantOf(NodeType|string $parent): NodeType
    {
        $parent = \is_string($parent) ? $this->form($parent) : $parent;
        $variant = (new NodeType($parent->getKey().'_v'.bin2hex(random_bytes(3)), $parent->getLabel().' · variante'))
            ->setFamily(NodeFamily::Parameter)
            ->setScope($parent->getScope())
            ->setParent($parent);
        $this->resolver->acknowledgeParentFields($variant);
        $this->em->persist($variant);
        $this->em->flush();

        return $variant;
    }

    private function find(NodeType $form, string $key, bool $withHidden = false): ResolvedField
    {
        foreach ($this->resolver->fields($form, $withHidden) as $field) {
            if ($field->getKey() === $key) {
                return $field;
            }
        }
        self::fail(sprintf('Champ « %s » absent de « %s ».', $key, $form->getKey()));
    }

    /** @param list<ResolvedField> $fields @return list<string> */
    private function keys(array $fields): array
    {
        return array_map(static fn (ResolvedField $f) => $f->getKey(), $fields);
    }
}
