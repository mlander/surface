<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface_test\AnyToIntegerRefiner;
use Drupal\data_surface_test\CacheableCasingRefiner;
use Drupal\data_surface_test\ConstraintRewritingRefiner;
use Drupal\data_surface_test\MapPropertyRefiner;
use Drupal\data_surface_test\VariantPolicyFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests refinement as the union of what each contribution narrowed to.
 *
 * The three roles of decision D2, each held to one rule. Contributors
 * widen at build time and own what they added. Their refiners narrow
 * their own contribution and nothing else, and the refined surface is
 * the union. Policy filters run last and may only remove. Everything
 * here is checked rather than documented, because the module's loudest
 * claim — a consumer that read the advertisement is never surprised —
 * is worth nothing as a convention.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceContributionTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface', 'data_surface_test'];

  /**
   * The values the owner declares for the variant key.
   */
  protected const OWNED = ['bold', 'strong', 'quiet', 'muted'];

  /**
   * Builds a surface whose variant key carries one contribution.
   *
   * @param array $refiners
   *   Refiners to register, as contributor => refiner.
   * @param \Drupal\data_surface\DataSurfaceFilterInterface[] $filters
   *   Policy filters to register.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed surface.
   */
  protected function surface(array $refiners = [], array $filters = []): DataSurfaceInterface {
    $builder = $this->builder();
    foreach ($refiners as $contributor => $refiner) {
      $builder->addRefiner('variant', $refiner, $contributor === DataSurfaceInterface::OWNER ? NULL : (string) $contributor);
    }
    foreach ($filters as $filter) {
      $builder->addFilter($filter);
    }
    return $builder->seal();
  }

  /**
   * Builds the unsealed builder the tests start from.
   *
   * @return \Drupal\data_surface\DataSurfaceBuilderInterface
   *   The builder, with one contributed value on 'variant'.
   */
  protected function builder(): DataSurfaceBuilderInterface {
    $builder = new DataSurfaceBuilder(
      definitions: [
        'casing' => DataDefinition::create('string')->setLabel('Casing'),
        'variant' => DataDefinition::create('string')
          ->setLabel('Variant')
          ->addConstraint('LabeledChoice', [
            'choices' => self::OWNED,
            'labels' => ['bold' => 'Bold', 'strong' => 'Strong', 'quiet' => 'Quiet', 'muted' => 'Muted'],
          ]),
      ],
      refinements: ['variant' => ['casing']],
    );
    return $builder->extendChoices('variant', ['ribbon' => 'Ribbon'], 'other');
  }

  /**
   * Reads the values a surface offers for the variant key.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface to read.
   *
   * @return array
   *   The allowed values.
   */
  protected function variants(DataSurfaceInterface $surface): array {
    return $surface->getDefinition('variant')->getConstraints()['LabeledChoice']['choices'];
  }

  /**
   * Tests that each contribution is handed its own values and no more.
   */
  public function testEachContributionNarrowsItsOwn(): void {
    // Each refiner answers with exactly what it was handed, so what each
    // one saw is readable in the union.
    $surface = $this->surface([
      DataSurfaceInterface::OWNER => new ConstraintRewritingRefiner(),
      'other' => new ConstraintRewritingRefiner(),
    ]);

    $this->assertSame(
      ['bold', 'strong', 'quiet', 'muted', 'ribbon'],
      $this->variants($surface->refine(['casing' => 'uppercase'])),
    );

    // The owner is handed its own values only: told to keep just the
    // first two, it cannot take the contributed value with it.
    $surface = $this->surface([
      DataSurfaceInterface::OWNER => new ConstraintRewritingRefiner([
        'LabeledChoice' => ['choices' => ['bold', 'strong'], 'labels' => []],
      ]),
      'other' => new ConstraintRewritingRefiner(),
    ]);
    $this->assertSame(['bold', 'strong', 'ribbon'], $this->variants($surface->refine(['casing' => 'uppercase'])));

    // And a contributor that drops everything drops only its own.
    $surface = $this->surface([
      'other' => new ConstraintRewritingRefiner(['LabeledChoice' => ['choices' => [], 'labels' => []]]),
    ]);
    $this->assertSame(self::OWNED, $this->variants($surface->refine(['casing' => 'uppercase'])));
  }

  /**
   * Tests that a contributor cannot hand back a value it was not given.
   */
  public function testContributorCannotReachAnotherContributionsValue(): void {
    $surface = $this->surface([
      'other' => new ConstraintRewritingRefiner([
        'LabeledChoice' => ['choices' => ['ribbon', 'bold'], 'labels' => []],
      ]),
    ]);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Refining "variant" for the other contribution widened what it was given: the LabeledChoice constraint gained the values bold');
    $surface->refine(['casing' => 'uppercase']);
  }

  /**
   * Tests that a surface with one contribution behaves as a plain chain.
   *
   * The overwhelmingly common case: no contributions at all, one owner
   * chain, and an answer identical to the one the chain gave before any
   * of this existed.
   */
  public function testSingleContributionIsThePlainChain(): void {
    $builder = new DataSurfaceBuilder(
      definitions: [
        'casing' => DataDefinition::create('string'),
        'variant' => DataDefinition::create('string'),
      ],
      refinements: ['variant' => ['casing']],
    );
    $builder->addRefiner('variant', new ConstraintRewritingRefiner([
      'Choice' => ['choices' => ['bold']],
    ]));
    $builder->addRefiner('variant', new ConstraintRewritingRefiner(['Length' => ['max' => 4]]));
    $surface = $builder->seal();

    $refined = $surface->refine(['casing' => 'uppercase'])->getDefinition('variant');
    $this->assertSame(['bold'], $refined->getConstraints()['Choice']['choices']);
    $this->assertSame(['max' => 4], $refined->getConstraints()['Length']);
    // And the advertised surface is untouched by any of it.
    $this->assertArrayNotHasKey('Choice', $surface->getDefinition('variant')->getConstraints());
    $this->assertArrayNotHasKey('Length', $surface->getDefinition('variant')->getConstraints());
  }

  /**
   * Tests every verdict the conservative narrowing check gives.
   *
   * @param array $advertised
   *   The constraints the key is advertised with.
   * @param bool $required
   *   Whether the key is advertised as required.
   * @param \Drupal\data_surface_test\ConstraintRewritingRefiner $refiner
   *   The refiner to run.
   * @param string|null $refused
   *   The message fragment the refusal must carry, or NULL when the
   *   refinement is narrower and must be accepted.
   */
  #[DataProvider('narrowingCases')]
  public function testTheNarrowingCheck(array $advertised, bool $required, ConstraintRewritingRefiner $refiner, ?string $refused): void {
    $definition = DataDefinition::create('string')->setLabel('Variant')->setRequired($required);
    foreach ($advertised as $name => $options) {
      $definition->addConstraint($name, $options);
    }
    $builder = new DataSurfaceBuilder(
      definitions: [
        'casing' => DataDefinition::create('string'),
        'variant' => $definition,
      ],
      refinements: ['variant' => ['casing']],
    );
    $surface = $builder->addRefiner('variant', $refiner)->seal();

    if ($refused === NULL) {
      $this->assertNotSame($surface, $surface->refine(['casing' => 'uppercase']));
      return;
    }
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage($refused);
    $surface->refine(['casing' => 'uppercase']);
  }

  /**
   * Supplies the narrowing cases, refused and accepted.
   *
   * @return array
   *   Test cases keyed by what they describe.
   */
  public static function narrowingCases(): array {
    $choices = ['Choice' => ['choices' => ['bold', 'strong']]];
    $length = ['Length' => ['min' => 2, 'max' => 10]];
    return [
      'adding a constraint' => [
        [], FALSE,
        new ConstraintRewritingRefiner(['Length' => ['max' => 4]]),
        NULL,
      ],
      'narrowing to a subset of the allowed values' => [
        $choices, FALSE,
        new ConstraintRewritingRefiner(['Choice' => ['choices' => ['bold']]]),
        NULL,
      ],
      'tightening both bounds' => [
        $length, FALSE,
        new ConstraintRewritingRefiner(['Length' => ['min' => 4, 'max' => 8]]),
        NULL,
      ],
      'adding a bound that was not there' => [
        ['Length' => ['max' => 10]], FALSE,
        new ConstraintRewritingRefiner(['Length' => ['min' => 4, 'max' => 10]]),
        NULL,
      ],
      'adding allowed values' => [
        $choices, FALSE,
        new ConstraintRewritingRefiner(['Choice' => ['choices' => ['bold', 'strong', 'ribbon']]]),
        'the Choice constraint gained the values ribbon',
      ],
      'dropping the list of allowed values' => [
        $choices, FALSE,
        new ConstraintRewritingRefiner(['Choice' => ['multiple' => TRUE]]),
        'the Choice constraint lost its list of allowed values',
      ],
      'removing a constraint' => [
        $length, FALSE,
        new ConstraintRewritingRefiner(['Length' => NULL]),
        'the Length constraint was removed',
      ],
      'lowering a minimum' => [
        $length, FALSE,
        new ConstraintRewritingRefiner(['Length' => ['min' => 1, 'max' => 10]]),
        'the min of the Length constraint moved from 2 to 1',
      ],
      'raising a maximum' => [
        $length, FALSE,
        new ConstraintRewritingRefiner(['Length' => ['min' => 2, 'max' => 40]]),
        'the max of the Length constraint moved from 10 to 40',
      ],
      'widening a range' => [
        ['Range' => ['min' => 1, 'max' => 10]], FALSE,
        new ConstraintRewritingRefiner(['Range' => ['min' => 1, 'max' => 50]]),
        'the max of the Range constraint moved from 10 to 50',
      ],
      'replacing options that cannot be compared' => [
        ['Regex' => ['pattern' => '/^a/']], FALSE,
        new ConstraintRewritingRefiner(['Regex' => ['pattern' => '/^b/']]),
        'the options of the Regex constraint were replaced',
      ],
      'turning required off' => [
        [], TRUE,
        new ConstraintRewritingRefiner(required: FALSE),
        'the required flag was turned off',
      ],
      'changing the data type' => [
        [], FALSE,
        new ConstraintRewritingRefiner(dataType: 'integer'),
        'the data type changed from string to integer',
      ],
    ];
  }

  /**
   * Tests that a policy filter removes from the union, and only removes.
   */
  public function testPolicyFilters(): void {
    $surface = $this->surface(filters: [new VariantPolicyFilter('variant', ['strong', 'ribbon'])]);

    // Every key, not only the refinement targets: a policy is not a
    // dependency of anything, so it does not wait for one.
    $this->assertSame(['bold', 'quiet', 'muted'], $this->variants($surface->refine([])));

    $surface = $this->surface(filters: [new VariantPolicyFilter('variant', [], ['sash'])]);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('for the policy filter Drupal\data_surface_test\VariantPolicyFilter widened');
    $surface->refine([]);
  }

  /**
   * Tests that a refiner cannot reach the definitions it was not handed.
   *
   * Core's MapDataDefinition declares no __clone, so a plain clone hands
   * a refiner the sealed surface's own property definitions.
   */
  public function testTheSealedSurfaceIsNeverReachable(): void {
    $map = MapDataDefinition::create()->setLabel('Extras');
    $map->setPropertyDefinition('note', DataDefinition::create('string'));
    $builder = new DataSurfaceBuilder(
      definitions: [
        'casing' => DataDefinition::create('string'),
        'extras' => $map,
      ],
      refinements: ['extras' => ['casing']],
    );
    $surface = $builder->addRefiner('extras', new MapPropertyRefiner())->seal();

    $refined = $surface->refine(['casing' => 'uppercase']);

    $this->assertSame(['max' => 3], $this->note($refined)->getConstraints()['Length']);
    $this->assertArrayNotHasKey('Length', $this->note($surface)->getConstraints());
  }

  /**
   * Reads the note property of a surface's extras map.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface to read.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The property definition.
   */
  protected function note(DataSurfaceInterface $surface): DataDefinitionInterface {
    $extras = $surface->getDefinition('extras');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $extras);
    return $extras->getPropertyDefinitions()[MapPropertyRefiner::PROPERTY];
  }

  /**
   * Tests that a fresh definition keeps the metadata it never carried.
   *
   * A refiner that answers with a new definition — the normal way to
   * give the 'any' escape hatch a concrete type — would otherwise drop
   * the declared default and the examples, and the rendered form and the
   * accepted value would then disagree about what the key starts from.
   */
  public function testMetadataSurvivesFreshDefinitions(): void {
    $value = DataDefinition::create('any')->setLabel('Value');
    DefinitionMetadata::setDefaultValue($value, 7);
    DefinitionMetadata::setExamples($value, [1, 2]);
    $builder = new DataSurfaceBuilder(
      definitions: ['kind' => DataDefinition::create('string'), 'value' => $value],
      refinements: ['value' => ['kind']],
    );
    $surface = $builder->addRefiner('value', new AnyToIntegerRefiner())->seal();

    $refined = $surface->refine(['kind' => 'count'])->getDefinition('value');

    $this->assertSame('integer', $refined->getDataType());
    $this->assertSame(7, DefinitionMetadata::getDefaultValue($refined));
    $this->assertSame([1, 2], DefinitionMetadata::getExamples($refined));
  }

  /**
   * Tests that a refiner's cacheability reaches the refined surface.
   */
  public function testRefinerCacheabilityReachesTheRefinedSurface(): void {
    $builder = new DataSurfaceBuilder(
      definitions: [
        'casing' => DataDefinition::create('string'),
        'variant' => DataDefinition::create('string'),
      ],
      refinements: ['variant' => ['casing']],
    );
    $builder->addCacheableDependency((new CacheableMetadata())->setCacheTags(['config:system.site']));
    $surface = $builder->addRefiner('variant', new CacheableCasingRefiner())->seal();

    // Nothing has run yet: the surface carries what the build declared.
    $this->assertSame(['config:system.site'], $surface->getCacheTags());
    $this->assertSame([], $surface->getCacheContexts());
    $this->assertSame(Cache::PERMANENT, $surface->getCacheMaxAge());

    $refined = $surface->refine(['casing' => 'uppercase']);

    // And once it has, the refined surface says so: a shape that was
    // read from site state can only be reused for as long as that state
    // holds, and only for the request it was read in.
    $this->assertSame(['config:system.site', 'data_surface_test:casing'], $refined->getCacheTags());
    $this->assertSame(['languages:language_interface'], $refined->getCacheContexts());
    $this->assertSame(CacheableCasingRefiner::MAX_AGE, $refined->getCacheMaxAge());
  }

}
