<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\TypedData\DataDefinition;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that option lists are derived from constraints, once.
 *
 * A value list is declared as a constraint and nowhere else. The
 * resolvers are the only code that knows how to read one as a list, so
 * this is where every stock reading is pinned down: the constraint that
 * carries labels, the core one that does not, and the two existence
 * constraints whose lists live in site state rather than in the
 * definition.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceOptionsResolverTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'entity_test', 'data_surface'];

  /**
   * Tests a labeled choice resolves to its own map, permanently.
   *
   * Spelled canonically: the allowed values are Choice's own list and
   * the labels hang beside them, keyed by value. An integer-keyed label
   * map has to be written this way, because it cannot be told from a
   * list of choices.
   */
  public function testLabeledChoiceResolves(): void {
    $definition = DataDefinition::create('integer')
      ->setLabel('Mode')
      ->addConstraint('LabeledChoice', [
        'choices' => [0, 1, 2],
        'labels' => [0 => 'Disabled', 1 => 'Optional', 2 => 'Required'],
        'descriptions' => [2 => 'Every request has to carry one.'],
      ]);

    $set = $this->options()->resolve($definition);

    $this->assertSame([0 => 'Disabled', 1 => 'Optional', 2 => 'Required'], $set->options);
    $this->assertSame([2 => 'Every request has to carry one.'], $set->descriptions);
    // Nothing outside the definition can change the list.
    $this->assertSame([], $set->getCacheTags());
    $this->assertSame([], $set->getCacheContexts());
  }

  /**
   * Tests a core Choice resolves to a list where values are their labels.
   */
  public function testChoiceResolves(): void {
    $definition = DataDefinition::create('string')
      ->setLabel('Badge')
      ->addConstraint('Choice', ['choices' => ['star', 'flame']]);

    $this->assertSame(
      ['star' => 'star', 'flame' => 'flame'],
      $this->options()->resolve($definition)->options,
    );
  }

  /**
   * Tests PluginExists resolves to the definitions of its manager.
   */
  public function testPluginExistsResolves(): void {
    $definition = DataDefinition::create('string')
      ->setLabel('Block')
      ->addConstraint('PluginExists', [
        'manager' => 'plugin.manager.block',
        'interface' => BlockPluginInterface::class,
      ]);
    $manager = $this->container->get('plugin.manager.block');

    $set = $this->options()->resolve($definition);

    // Every block plugin the site has, named the way the plugin names
    // itself — no second list to keep in step with the manager.
    $this->assertSame(array_keys($manager->getDefinitions()), array_keys($set->options));
    $this->assertArrayHasKey('system_powered_by_block', $set->options);
    $this->assertSame('Powered by Drupal', (string) $set->options['system_powered_by_block']);
    // The list is only good for as long as the manager's own answer is.
    $this->assertSame($manager->getCacheTags(), $set->getCacheTags());
  }

  /**
   * Tests EntityBundleExists resolves to the bundles of its entity type.
   */
  public function testEntityBundleExistsResolves(): void {
    $this->container->get('state')->set('entity_test.bundles', [
      'entity_test' => ['label' => 'Entity Test Bundle'],
      'special' => ['label' => 'Special'],
    ]);
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
    $definition = DataDefinition::create('string')
      ->setLabel('Bundle')
      ->addConstraint('EntityBundleExists', ['entityTypeId' => 'entity_test']);

    $set = $this->options()->resolve($definition);

    $this->assertSame(
      ['entity_test' => 'Entity Test Bundle', 'special' => 'Special'],
      array_map('strval', $set->options),
    );
    // A bundle added later invalidates whatever cached the list.
    $this->assertSame(['entity_bundles'], $set->getCacheTags());
  }

  /**
   * Tests LanguageExists resolves to the languages the site has.
   */
  public function testLanguageExistsResolves(): void {
    $definition = DataDefinition::create('string')
      ->setLabel('Language')
      ->addConstraint('LanguageExists');

    $set = $this->options()->resolve($definition);

    $this->assertSame(['en' => 'English'], array_map('strval', $set->options));
    // The locked languages are the absence of a language, so they are
    // not offered unless the constraint asks for them.
    $this->assertArrayNotHasKey('und', $set->options);
    $this->assertArrayNotHasKey('zxx', $set->options);
    // A language added later invalidates whatever cached the list.
    $this->assertSame(['config:configurable_language_list'], $set->getCacheTags());

    $locked = DataDefinition::create('string')
      ->setLabel('Language')
      ->addConstraint('LanguageExists', ['allowLocked' => TRUE]);

    $this->assertSame(
      ['en', 'und', 'zxx'],
      array_keys($this->options()->resolve($locked)->options),
    );
  }

  /**
   * Tests the LanguageExists constraint validating the same set.
   *
   * The list that validates and the list that is offered are the same
   * declaration read twice, which is the whole point of deriving options
   * from constraints: there is nothing here to keep in step.
   */
  public function testLanguageExistsValidates(): void {
    $definition = DataDefinition::create('string')
      ->setLabel('Language')
      ->addConstraint('LanguageExists');
    $manager = $this->container->get('typed_data_manager');

    $this->assertCount(0, $manager->create($definition, 'en')->validate());
    // No value given is not a value out of the set.
    $this->assertCount(0, $manager->create($definition, NULL)->validate());

    $violations = $manager->create($definition, 'und')->validate();
    $this->assertCount(1, $violations);
    $this->assertSame(
      "The 'und' language does not exist.",
      (string) $violations->get(0)->getMessage(),
    );
    $this->assertCount(1, $manager->create($definition, 'nope')->validate());

    $locked = DataDefinition::create('string')
      ->setLabel('Language')
      ->addConstraint('LanguageExists', ['allowLocked' => TRUE]);
    $this->assertCount(0, $manager->create($locked, 'und')->validate());
  }

  /**
   * Tests the convenience spelling of a labeled choice.
   *
   * A map with non-sequential keys and no labels beside it is the way a
   * list with meaning is most often written by hand, so it is read as
   * value => label. The constraint still holds Choice's own list, which
   * is what validates.
   */
  public function testLabeledChoiceMapSpelling(): void {
    $definition = DataDefinition::create('string')
      ->setLabel('Badge')
      ->addConstraint('LabeledChoice', [
        'choices' => ['star' => 'Star', 'flame' => 'Flame'],
      ]);

    $this->assertSame(
      ['star' => 'Star', 'flame' => 'Flame'],
      $this->options()->resolve($definition)->options,
    );
    $manager = $this->container->get('typed_data_manager');
    $this->assertCount(0, $manager->create($definition, 'flame')->validate());
    $this->assertCount(1, $manager->create($definition, 'Flame')->validate());
  }

  /**
   * Tests a choice with no label of its own is offered under its value.
   */
  public function testLabeledChoiceWithoutLabels(): void {
    $definition = DataDefinition::create('string')
      ->setLabel('Badge')
      ->addConstraint('LabeledChoice', [
        'choices' => ['star', 'flame'],
        'labels' => ['star' => 'Star'],
      ]);

    $this->assertSame(
      ['star' => 'Star', 'flame' => 'flame'],
      $this->options()->resolve($definition)->options,
    );
  }

  /**
   * Tests two constraints naming lists resolve to their intersection.
   */
  public function testTwoConstraintsIntersect(): void {
    $definition = DataDefinition::create('string')
      ->setLabel('Badge')
      ->addConstraint('LabeledChoice', [
        'choices' => ['star' => 'Star', 'flame' => 'Flame', 'ribbon' => 'Ribbon'],
      ])
      ->addConstraint('Choice', ['choices' => ['flame', 'ribbon', 'crown']]);

    $set = $this->options()->resolve($definition);

    // Both constraints have to hold at once, so only what both allow is
    // offered — and the labels survive from the constraint that has any.
    $this->assertSame(['flame' => 'Flame', 'ribbon' => 'Ribbon'], $set->options);
  }

  /**
   * Tests a definition whose constraints name no list resolves to NULL.
   */
  public function testNoListResolvesToNothing(): void {
    $definition = DataDefinition::create('string')
      ->setLabel('Title')
      ->addConstraint('Length', ['max' => 40]);

    $this->assertNull($this->options()->resolve($definition));
    $this->assertNull($this->options()->resolve(DataDefinition::create('string')));
  }

}
