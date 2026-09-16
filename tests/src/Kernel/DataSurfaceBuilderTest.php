<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceFactoryInterface;
use Drupal\data_surface\Plugin\Validation\Constraint\LabeledChoiceConstraint;
use Drupal\data_surface_test\CasingVariantRefiner;
use Drupal\data_surface_test\VariantPolicyFilter;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the builder: the seal guard, map properties, and contributions.
 *
 * The claim these tests hold the code to is the one the module makes
 * loudest: a surface is immutable once advertised. That means the
 * builder behind it has to refuse every later change rather than
 * quietly accept one, and the factory has to refuse to advertise the
 * same builder twice — otherwise "immutable after seal" is a convention
 * and not a contract.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceBuilderTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface', 'data_surface_test'];

  /**
   * Gets the surface factory.
   *
   * @return \Drupal\data_surface\DataSurfaceFactoryInterface
   *   The factory.
   */
  protected function factory(): DataSurfaceFactoryInterface {
    return $this->container->get('data_surface.factory');
  }

  /**
   * Builds a builder holding one flat key, one map, and one choice list.
   *
   * @return \Drupal\data_surface\DataSurfaceBuilderInterface
   *   The builder, unsealed.
   */
  protected function builder(): DataSurfaceBuilderInterface {
    return new DataSurfaceBuilder([
      'casing' => DataDefinition::create('string')
        ->setLabel('Casing')
        ->setRequired(FALSE)
        ->addConstraint('Choice', ['choices' => ['none', 'uppercase']]),
      'extras' => MapDataDefinition::create()->setLabel('Extras')->setRequired(FALSE),
    ]);
  }

  /**
   * Tests that seal() answers with the same surface every time.
   */
  public function testSealIsIdempotent(): void {
    $builder = $this->builder();

    $sealed = $builder->seal();

    $this->assertFalse($this->builder()->isSealed());
    $this->assertTrue($builder->isSealed());
    $this->assertSame($sealed, $builder->seal());
  }

  /**
   * Tests that every mutator refuses to run after seal().
   */
  public function testEveryMutatorRefusesAfterSeal(): void {
    $definition = DataDefinition::create('string')->setRequired(FALSE);
    $mutations = [
      'setDefinition' => static fn (DataSurfaceBuilderInterface $b) => $b->setDefinition('added', clone $definition),
      'setPropertyDefinitions' => static fn (DataSurfaceBuilderInterface $b) => $b->setPropertyDefinitions('extras', ['badge' => clone $definition]),
      'setPropertyDefinition' => static fn (DataSurfaceBuilderInterface $b) => $b->setPropertyDefinition('extras', 'badge', clone $definition),
      'setDefault' => static fn (DataSurfaceBuilderInterface $b) => $b->setDefault('casing', 'none'),
      'lock' => static fn (DataSurfaceBuilderInterface $b) => $b->lock('casing'),
      'extendChoices' => static fn (DataSurfaceBuilderInterface $b) => $b->extendChoices('casing', ['lowercase'], 'other'),
      'setThirdPartyDefinition' => static fn (DataSurfaceBuilderInterface $b) => $b->setThirdPartyDefinition('other', 'badge', clone $definition),
      'addRefinement' => static fn (DataSurfaceBuilderInterface $b) => $b->addRefinement('casing', ['extras']),
      'addRefiner' => static fn (DataSurfaceBuilderInterface $b) => $b->addRefiner('casing', new CasingVariantRefiner()),
      'addFilter' => static fn (DataSurfaceBuilderInterface $b) => $b->addFilter(new VariantPolicyFilter('casing', ['none'])),
      'addCacheableDependency' => static fn (DataSurfaceBuilderInterface $b) => $b->addCacheableDependency(new CacheableMetadata()),
    ];

    foreach ($mutations as $name => $mutation) {
      $builder = $this->builder();
      $surface = $builder->seal();
      try {
        $mutation($builder);
        $this->fail(sprintf('%s() changed a sealed builder.', $name));
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString('sealed', $e->getMessage());
      }
      // The advertised surface is what it was: nothing half-applied.
      $this->assertSame(['casing', 'extras'], $surface->getDefinitions()->names());
    }
  }

  /**
   * Tests that the factory refuses to advertise one builder twice.
   */
  public function testFactoryRefusesSealedBuilder(): void {
    $builder = $this->builder();

    $this->factory()->build($builder, static::class, 'data_surface_builder_test');

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('already been sealed');
    $this->factory()->build($builder, static::class, 'data_surface_builder_test');
  }

  /**
   * Tests that map properties set before seal reach the sealed surface.
   */
  public function testPropertyDefinitionsReachTheSealedSurface(): void {
    $builder = $this->builder();
    $badge = DataDefinition::create('string')->setLabel('Badge')->setRequired(FALSE);
    $note = DataDefinition::create('string')->setLabel('Note')->setRequired(FALSE);

    $builder->setPropertyDefinitions('extras', ['badge' => $badge]);
    $builder->setPropertyDefinition('extras', 'note', $note);
    $surface = $this->factory()->build($builder, static::class, 'data_surface_builder_test');

    $extras = $surface->getDefinition('extras');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $extras);
    $this->assertSame(['badge', 'note'], array_keys($extras->getPropertyDefinitions()));
    $this->assertSame('Badge', (string) $extras->getPropertyDefinitions()['badge']->getLabel());
  }

  /**
   * Tests that a key with no properties to set is refused by name.
   */
  public function testPropertyDefinitionsRefuseFlatKey(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('does not take property definitions');
    $this->builder()->setPropertyDefinitions('casing', ['badge' => DataDefinition::create('string')]);
  }

  /**
   * Tests that property definitions on an unknown key are refused.
   */
  public function testPropertyDefinitionsRefuseAnUnknownKey(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown surface definition "nothing"');
    $this->builder()->setPropertyDefinitions('nothing', []);
  }

  /**
   * Tests that a contribution widens a plain Choice with bare values.
   */
  public function testExtendChoicesWidensChoice(): void {
    $surface = $this->builder()
      ->extendChoices('casing', ['lowercase', 'sentence'], 'other')
      ->seal();

    $this->assertSame(
      ['none', 'uppercase', 'lowercase', 'sentence'],
      $surface->getDefinition('casing')->getConstraints()['Choice']['choices'],
    );
    // And the surface knows whose values those two are, which is what
    // lets refinement hand them to that module's refiner and nobody
    // else's.
    $this->assertSame(
      ['casing' => ['other' => ['lowercase', 'sentence']]],
      $surface->getDefinitions()->contributions(),
    );
  }

  /**
   * Tests that a contribution widens a LabeledChoice, labels and all.
   */
  public function testExtendChoicesWidensLabeledChoice(): void {
    $builder = new DataSurfaceBuilder([
      'mode' => DataDefinition::create('string')
        ->setLabel('Mode')
        ->setRequired(FALSE)
        ->addConstraint('LabeledChoice', [
          'choices' => ['off' => 'Off', 'on' => 'On'],
          'descriptions' => ['off' => 'Nothing happens.'],
        ]),
    ]);

    $surface = $builder->extendChoices('mode', ['auto' => 'Automatic'], 'other')->seal();

    $constraint = $surface->getDefinition('mode')->getConstraints()['LabeledChoice'];
    // Written back in the canonical spelling, whichever one it was
    // declared in: the values are a list and the labels say what they
    // are called.
    $this->assertSame(['off', 'on', 'auto'], $constraint['choices']);
    $this->assertSame(
      ['off' => 'Off', 'on' => 'On', 'auto' => 'Automatic'],
      $constraint['labels'],
    );
    // What the constraint said beyond its choices is still said.
    $this->assertSame(['off' => 'Nothing happens.'], $constraint['descriptions']);
    // And the widened list is one list: what validates is what is
    // offered, so the resolver reads the contribution too.
    $options = $this->container->get('data_surface.options')
      ->resolve($surface->getDefinition('mode'));
    $this->assertSame(['off', 'on', 'auto'], array_keys($options->options));
    $this->assertCount(
      0,
      $this->container->get('data_surface.pipeline')->validate($surface, ['mode' => 'auto']),
    );
  }

  /**
   * Tests that one value has one owner.
   *
   * Two modules contributing the same value is not a merge to be
   * resolved: it is two modules each believing they answer for how that
   * value behaves, and only one of them can be right.
   */
  public function testExtendChoicesRefusesTwoOwnersForOneValue(): void {
    $builder = new DataSurfaceBuilder([
      'mode' => DataDefinition::create('string')
        ->setRequired(FALSE)
        ->addConstraint('LabeledChoice', ['choices' => ['on' => 'On']]),
    ]);
    $builder->extendChoices('mode', ['auto' => 'Automatic'], 'first');

    // An existing value keeps the label it already had, and the module
    // that has it is named.
    try {
      $builder->extendChoices('mode', ['auto' => 'Automatically'], 'second');
      $this->fail('A second contributor took over an existing value.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('already contributed by first', $e->getMessage());
    }
    try {
      $builder->extendChoices('mode', ['on' => 'Switched on'], 'second');
      $this->fail('A contributor took over one of the owner\'s values.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('already contributed by the surface owner', $e->getMessage());
    }

    $surface = $builder->seal();
    $this->assertSame(
      ['on' => 'On', 'auto' => 'Automatic'],
      $surface->getDefinition('mode')->getConstraints()['LabeledChoice']['labels'],
    );
    // The constraint class is core-shaped and knows nothing of this.
    $this->assertTrue(class_exists(LabeledChoiceConstraint::class));
  }

  /**
   * Tests that a contribution on an unknown key is refused.
   */
  public function testExtendChoicesRefusesAnUnknownKey(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown surface definition "nothing"');
    $this->builder()->extendChoices('nothing', ['a'], 'other');
  }

  /**
   * Tests that a key with nothing to extend is refused, saying why.
   *
   * A key that names no list of allowed values allows everything its
   * type allows. Giving it a list is not a contribution, it is a
   * narrowing of somebody else's contract, and a contributor may not do
   * that at all.
   */
  public function testExtendChoicesRefusesAnOpenKey(): void {
    $builder = new DataSurfaceBuilder([
      'note' => DataDefinition::create('string')->setRequired(FALSE),
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('declares no list of allowed values');
    $builder->extendChoices('note', ['a'], 'other');
  }

  /**
   * Tests that a refinement cycle is refused at seal, naming the cycle.
   *
   * A cycle is not an infinite loop — refinement walks each target once
   * — which is precisely why it has to be refused: the answer would
   * depend on the order the keys happened to be read in, and it would
   * look like it worked.
   */
  public function testSealRefusesRefinementCycles(): void {
    $builder = new DataSurfaceBuilder([
      'a' => DataDefinition::create('string')->setRequired(FALSE),
      'b' => DataDefinition::create('string')->setRequired(FALSE),
      'c' => DataDefinition::create('string')->setRequired(FALSE),
    ]);
    $builder->addRefinement('a', ['b']);
    $builder->addRefinement('b', ['c']);
    $builder->addRefinement('c', ['a']);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The refinement map has a cycle: a -> b -> c -> a');
    $builder->seal();
  }

  /**
   * Tests that a map two keys both depend on is not read as a cycle.
   */
  public function testSealAcceptsDiamonds(): void {
    $builder = new DataSurfaceBuilder([
      'entity_type' => DataDefinition::create('string')->setRequired(FALSE),
      'bundle' => DataDefinition::create('string')->setRequired(FALSE),
      'field' => DataDefinition::create('string')->setRequired(FALSE),
    ]);
    $builder->addRefinement('bundle', ['entity_type']);
    $builder->addRefinement('field', ['entity_type', 'bundle']);

    $this->assertSame(
      ['bundle' => ['entity_type'], 'field' => ['entity_type', 'bundle']],
      $builder->seal()->getDefinitions()->refinements(),
    );
  }

  /**
   * Tests that what the builder was told it depends on reaches the surface.
   */
  public function testCacheabilityReachesTheSealedSurface(): void {
    $builder = $this->builder();
    $builder->addCacheableDependency((new CacheableMetadata())
      ->setCacheTags(['config:system.site'])
      ->setCacheContexts(['languages:language_interface'])
      ->setCacheMaxAge(120));

    $surface = $builder->seal();

    $this->assertSame(['config:system.site'], $surface->getCacheTags());
    $this->assertSame(['languages:language_interface'], $surface->getCacheContexts());
    $this->assertSame(120, $surface->getCacheMaxAge());
  }

}
