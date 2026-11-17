<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\DefinitionMap;
use Drupal\surface\Entry;
use Drupal\surface\Options\OptionSet;
use Drupal\surface\Options\SurfaceOptionsResolverInterface;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface_examples\Venues;
use PortableSchema\Builder\Schema;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;

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
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class SurfaceOptionsResolverTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'entity_test', 'surface'];

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
    // The names are translated into the interface language, so the list
    // is good for one language at a time.
    $this->assertContains('languages:language_interface', $set->getCacheContexts());

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

  /**
   * Tests a resolver is handed the key's schema, which it may read.
   *
   * Handed the definition alone, the service hands each resolver the schema
   * it reads from the definition: the title and the keywords the key
   * declares. Handed the key's schema, it hands that very schema on, and the
   * constraints it reads are the schema's.
   */
  public function testTheResolverIsHandedTheKeysSchema(): void {
    $recorder = new class () implements SurfaceOptionsResolverInterface {

      /**
       * The schemas handed, in order.
       *
       * @var \PortableSchema\Builder\Schema[]
       */
      public array $handed = [];

      /**
       * {@inheritdoc}
       */
      public function applies(Constraint $constraint): bool {
        return $constraint instanceof Choice;
      }

      /**
       * {@inheritdoc}
       */
      public function resolve(Constraint $constraint, Schema $schema): OptionSet {
        assert($constraint instanceof Choice);
        $this->handed[] = $schema;
        return new OptionSet(array_combine($constraint->choices, $constraint->choices));
      }

    };
    $options = $this->options();
    (new \ReflectionProperty($options, 'resolvers'))->setValue($options, ['recorder' => $recorder]);
    $definition = DataDefinition::create('string')
      ->setLabel('Room')
      ->addConstraint('Choice', ['choices' => ['east', 'west']])
      ->addConstraint('Length', ['max' => 4]);

    $this->assertSame(['east' => 'east', 'west' => 'west'], $options->resolve($definition)->options);
    $this->assertCount(1, $recorder->handed);
    $schema = $recorder->handed[0];
    $this->assertSame('Room', (string) $schema->get('title'));
    $this->assertSame(4, $schema->get('maxLength'));
    $this->assertSame(['string', 'null'], $schema->get('type'), 'Not required, so it may be NULL.');
    $this->assertTrue(DefinitionSchema::same(DefinitionSchema::of($definition), $schema), 'Read, it is the definition\'s schema.');

    foreach ([FALSE, TRUE] as $schema_first) {
      $derived = new \ReflectionProperty(Entry::class, 'definition');
      $entry = DefinitionMap::fromArrays(['room' => (clone $definition)->setLabel('Hall')], schemaFirst: $schema_first)->entry('room');
      $recorder->handed = [];
      $this->assertSame(['east' => 'east', 'west' => 'west'], $options->resolve($entry->schema())->options);
      $this->assertSame([$entry->schema()], $recorder->handed, 'Handed the key\'s schema, the service hands that very schema on.');
      $this->assertSame('Hall', (string) $entry->schema()->get('title'), 'The entry\'s own schema, its own title.');
      if ($schema_first) {
        $this->assertNull($derived->getValue($entry), 'Handed a schema, the service derives no definition.');
      }
    }

    // The constraints read are the schema's: a schema that holds a list no
    // definition stands beside answers with that list.
    $schema = DefinitionSchema::of(DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['north']]));
    $this->assertSame(['north' => 'north'], $options->resolve($schema)->options);
  }

  /**
   * Tests a refined key and its declared key resolve apart.
   *
   * A refined entry holds the refined definition and reads its schema from
   * it, so its schema is another object than the declared entry's: the memo
   * keyed by the schema serves each its own list, in either order and with
   * the declared entry in either form, and narrowing is never served a
   * stale list.
   */
  public function testRefinedAndDeclaredKeysResolveApart(): void {
    $options = $this->options();
    foreach ([FALSE, TRUE] as $schema_first) {
      $room = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['east', 'west', 'north']]);
      $declared = DefinitionMap::fromArrays(['room' => $room], schemaFirst: $schema_first)->entry('room');
      // What refinement makes of a key: a copy, narrowed, and an entry of it.
      $refined = $declared->withDefinition((clone $declared->definition())->addConstraint('Choice', ['choices' => ['east']]));
      $this->assertNotSame($declared->schema(), $refined->schema());
      $this->assertSame(['east', 'west', 'north'], array_keys($options->resolve($declared->schema())->options));
      $this->assertSame(['east'], array_keys($options->resolve($refined->schema())->options), 'The refined key is not served the declared list.');
      $this->assertSame(['east', 'west', 'north'], array_keys($options->resolve($declared->schema())->options), 'Nor the declared key the refined one.');
    }

    // The same through a resource's own refiner: example 3's room is
    // narrowed to the rooms of the venue chosen.
    $this->enableModules(['serialization', 'tool', 'surface_http', 'surface_examples']);
    $this->installConfig(['surface_examples']);
    // A built resource's entries are schema-first (the seal makes them so).
    $resources = $this->container->get('surface.resources');
    $surface = $resources->build(RegistrationStep3Resource::class, $resources->operation(RegistrationStep3Resource::class, 'configure'));
    $options = $this->options();
    $declared = $surface->getDefinitions()->entry('room');
    $refined = $surface->refine(['venue' => 'library'])->getDefinitions()->entry('room');
    $this->assertNotNull($declared);
    $this->assertNotNull($refined);
    $all = array_keys($options->resolve($declared->schema())->options);
    $library = array_keys($options->resolve($refined->schema())->options);
    $this->assertSame(array_keys(Venues::rooms()), $all);
    $this->assertSame(array_keys(Venues::rooms('library')), $library);
    $this->assertNotSame($all, $library);
    $this->assertSame($all, array_keys($options->resolve($declared->schema())->options));
  }

  /**
   * Tests invalidateTags() forgets an answer kept under a schema.
   *
   * A list read from the site carries the tags it was read under; once one
   * of them is invalidated, the key is resolved again, whether it was
   * asked by its schema or by its definition.
   */
  public function testInvalidatingForgetsSchemaKeyedAnswer(): void {
    $tagged = new class () implements SurfaceOptionsResolverInterface {

      /**
       * The lists it resolved.
       */
      public int $calls = 0;

      /**
       * {@inheritdoc}
       */
      public function applies(Constraint $constraint): bool {
        return $constraint instanceof Choice;
      }

      /**
       * {@inheritdoc}
       */
      public function resolve(Constraint $constraint, Schema $schema): OptionSet {
        assert($constraint instanceof Choice);
        $this->calls++;
        return new OptionSet(array_combine($constraint->choices, $constraint->choices), [], (new CacheableMetadata())->addCacheTags(['surface_test_rooms']));
      }

    };
    $options = $this->options();
    (new \ReflectionProperty($options, 'resolvers'))->setValue($options, ['tagged' => $tagged]);
    $definition = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['east']]);
    $schema = DefinitionSchema::of($definition);

    $options->resolve($schema);
    $options->resolve($schema);
    $options->resolve($definition);
    $this->assertSame(2, $tagged->calls, 'Each key is resolved once: the schema and the definition are two keys.');
    $options->invalidateTags(['some_other_tag']);
    $options->resolve($schema);
    $this->assertSame(2, $tagged->calls, 'A tag the list did not name forgets nothing.');
    $options->invalidateTags(['surface_test_rooms']);
    $options->resolve($schema);
    $options->resolve($definition);
    $this->assertSame(4, $tagged->calls, 'The tag forgets the answer under the schema and under the definition.');
  }

}
