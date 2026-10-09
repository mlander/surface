<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Pipeline\VariantMismatchException;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceAttachment;
use Drupal\data_surface\SurfaceBuild\SurfaceShape;
use Drupal\data_surface\SurfaceBuild\SurfaceShapeAdditions;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\data_surface_surface_test\Surface\Pantry\JarSurface;
use Drupal\data_surface_surface_test\Surface\Pantry\LabelSurface;
use Drupal\data_surface_surface_test\Surface\Pantry\PantrySurface;
use Drupal\data_surface_surface_test\Surface\Pantry\ShelfSurface;
use Drupal\data_surface_surface_test\Surface\Pantry\TinSurface;
use Drupal\data_surface_surface_test\Target\PantryLabelTarget;
use Drupal\data_surface_surface_test\Target\PantryTarget;
use Drupal\data_surface_surface_test\Target\PantryTinTarget;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests subsurfaces: attach(), attachBy(), and what each does.
 *
 * The pantry fixture has one of everything: a shelf attached by class
 * with a refiner and an alter of its own, a label attached by class with
 * a target of its own, and an open slot of kind settings filled by a jar
 * (stored by the pantry) and a tin (stored apart). The wall between a
 * parent and its child is tested by the broken fixtures in
 * SurfaceBuildTest; what passes through the one door, identity in the
 * child's context, is tested here with the targets.
 *
 * @see \Drupal\data_surface_surface_test\Surface\Pantry\PantrySurface
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SubsurfaceTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_surface_test',
  ];

  /**
   * Gets the build step.
   *
   * @return \Drupal\data_surface\SurfaceBuild\SurfacesInterface
   *   The service.
   */
  protected function surfaces(): SurfacesInterface {
    return $this->container->get('data_surface.surfaces');
  }

  /**
   * Builds the pantry.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext|null $context
   *   The context, or NULL for the add situation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The pantry.
   */
  protected function pantry(?SurfaceContext $context = NULL): DataSurfaceInterface {
    return $this->surfaces()->build(PantrySurface::class, $context ?? PantrySurface::add());
  }

  /**
   * Tests a child attached by class: its own shape, alters and defaults.
   */
  public function testAttachByClass(): void {
    $surface = $this->pantry();
    $entry = $surface->getDefinitions()->entry('shelf');
    $this->assertSame(ShelfSurface::class, $entry->attachment->source);
    $this->assertSame(LabelSurface::class, $surface->getDefinitions()->entry('label')->attachment->source);

    // Declared where defineInputs() put it, as a map of the child's keys,
    // the child's own alter included under its module, as on any surface.
    $this->assertSame(['pantry', 'kind', 'shelf', 'label', 'kind_settings'], $surface->getDefinitions()->names());
    $shelf = $surface->getDefinition('shelf');
    $this->assertInstanceOf(MapDataDefinition::class, $shelf);
    $this->assertSame(['unit', 'height', 'third_party_settings'], array_keys($shelf->getPropertyDefinitions()));
    // The alter of the child reworded the child's key, not the parent.
    $this->assertSame('Measured inside.', (string) $shelf->getPropertyDefinition('height')->getDescription());

    // It starts from the child's own defaults.
    $this->assertSame([
      'unit' => 'cm',
      'height' => 30,
      'third_party_settings' => ['data_surface_surface_test' => ['sturdy' => TRUE]],
    ], $surface->getDefault('shelf'));
    $this->assertSame(['text' => 'Pantry'], $surface->getDefault('label'));
  }

  /**
   * Tests that a child's refiner runs in the child's own frame.
   *
   * It watches the child's own sibling, by the child's own name, with the
   * child's value; the violation it causes comes back dotted under the
   * parent's key.
   */
  public function testChildRefinesInItsOwnFrame(): void {
    $surface = $this->pantry();
    $max = function (array $values) use ($surface): int {
      $shelf = $surface->refine($values)->getDefinition('shelf');
      $this->assertInstanceOf(MapDataDefinition::class, $shelf);
      return $shelf->getPropertyDefinition('height')->getConstraints()['Range']['max'];
    };
    // With nothing said, from the child's defaults.
    $this->assertSame(ShelfSurface::MAX['cm'], $max([]));
    $this->assertSame(ShelfSurface::MAX['in'], $max(['shelf' => ['unit' => 'in']]));
    // Nothing in the parent is a sibling of the child's keys: a parent
    // key of the same name as the child's is not what the child reads.
    $this->assertSame(ShelfSurface::MAX['cm'], $max(['unit' => 'in']));

    $values = $this->pipeline()->accept($surface, [
      'pantry' => 'larder',
      'shelf' => ['unit' => 'in', 'height' => '100'],
    ]);
    $this->assertSame([
      'unit' => 'in',
      'height' => 100,
      'third_party_settings' => ['data_surface_surface_test' => ['sturdy' => TRUE]],
    ], $values['shelf']);
    $violations = $this->pipeline()->validate($surface, $values);
    $this->assertSame(['shelf.height'], array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($violations, FALSE)));
    $this->assertSame('shelf', iterator_to_array($violations, FALSE)[0]->key);
  }

  /**
   * Tests the open slot: filled from discovery, advertised as a table.
   */
  public function testOpenSlotIsAdvertisedAndResolves(): void {
    $surface = $this->pantry();
    $entry = $surface->getDefinitions()->entry('kind_settings');
    $this->assertSame('kind', $entry->slot->by);
    $this->assertEqualsCanonicalizing(['jar', 'tin'], $entry->slot->variantIds());
    $this->assertSame(JarSurface::class, $entry->slot->variant('jar')->source);
    $this->assertSame(TinSurface::class, $entry->slot->variant('tin')->source);

    // Unresolved, it is a placeholder that says it is a slot, and which
    // key decides; its label and description are the owner's describe().
    $placeholder = $surface->getDefinition('kind_settings');
    $this->assertSame('any', $placeholder->getDataType());
    $this->assertSame('kind', DefinitionMetadata::slotOf($placeholder));
    $this->assertSame('Kind settings', (string) $placeholder->getLabel());
    // The deciding key gained the variants as its choices, narrower than
    // what it declared: a sack has no settings, so it is not offered.
    $this->assertEqualsCanonicalizing(['jar', 'tin'], $surface->getDefinition('kind')->getConstraints()['Choice']['choices']);
    $this->assertSame(['kind_settings' => ['kind']], $surface->getDefinitions()->refinements());
    // It starts as the variant the deciding key starts on.
    $this->assertSame(['lid' => 'screw', 'volume' => 500], $surface->getDefault('kind_settings'));

    // Resolved, it is exactly that variant's map, under the slot's label.
    $tin = $surface->refine(['kind' => 'tin'])->getDefinition('kind_settings');
    $this->assertInstanceOf(MapDataDefinition::class, $tin);
    $this->assertSame(['opener', 'volume'], array_keys($tin->getPropertyDefinitions()));
    $this->assertSame('Kind settings', (string) $tin->getLabel());
    $this->assertNull(DefinitionMetadata::slotOf($tin));
    $jar = $surface->refine(['kind' => 'jar'])->getDefinition('kind_settings');
    $this->assertInstanceOf(MapDataDefinition::class, $jar);
    $this->assertSame(['lid', 'volume'], array_keys($jar->getPropertyDefinitions()));
    // A deciding value naming no variant leaves it unresolved, and the
    // deciding key's own Choice refuses that value.
    $this->assertSame('any', $surface->refine(['kind' => 'sack'])->getDefinition('kind_settings')->getDataType());
    $this->assertContains('kind', $this->pipeline()->validate($surface, ['pantry' => 'a', 'kind' => 'sack'] + $surface->getDefaultValues())->keys());
  }

  /**
   * Tests that another variant's keys are refused by name.
   */
  public function testVariantMismatchIsPathAwareViolation(): void {
    $surface = $this->pantry();
    try {
      $this->pipeline()->accept($surface, ['pantry' => 'a', 'kind' => 'jar', 'kind_settings' => ['opener' => TRUE]]);
      $this->fail('A tin\'s key was accepted for a jar.');
    }
    catch (VariantMismatchException $e) {
      $this->assertSame(['opener' => ['tin']], $e->getOwners());
    }

    $result = $this->pipeline()->submit($surface, [
      'pantry' => 'a',
      'kind' => 'jar',
      'kind_settings' => ['opener' => TRUE],
    ], $this->nowhere());
    $violation = iterator_to_array($result->violations, FALSE)[0];
    $this->assertSame('kind_settings', $violation->key);
    $this->assertSame('opener', $violation->path);
    $this->assertSame('kind_settings.opener belongs to the tin variant, but kind chose jar.', (string) $violation->message);

    // Validation says the same of a value that reached it some other way,
    // and judges the keys the chosen variant does declare by its rules.
    $violations = $this->pipeline()->validate($surface, [
      'pantry' => 'a',
      'kind' => 'jar',
      'kind_settings' => ['opener' => TRUE, 'volume' => 9000],
    ] + $surface->getDefaultValues());
    $this->assertEqualsCanonicalizing(
      ['kind_settings.opener', 'kind_settings.volume'],
      array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($violations, FALSE)),
    );

    // A key no variant declares is simply unknown.
    $result = $this->pipeline()->submit($surface, ['pantry' => 'a', 'kind' => 'jar', 'kind_settings' => ['nope' => 1]], $this->nowhere());
    $this->assertSame('Unknown key kind_settings.nope.', (string) iterator_to_array($result->violations, FALSE)[0]->message);
  }

  /**
   * Tests that a changed deciding key resets what the slot held.
   */
  public function testDecidingKeyResetsTheSlot(): void {
    $surface = $this->pantry();
    $current = ['pantry' => 'a', 'kind' => 'jar', 'kind_settings' => ['lid' => 'clip', 'volume' => 900]];

    // Unchanged, what is stored stands.
    $this->assertSame(['lid' => 'clip', 'volume' => 900], $this->pipeline()->accept($surface, [], $current)['kind_settings']);
    // Moved to a tin, the jar's settings mean nothing: the tin's defaults.
    $accepted = $this->pipeline()->accept($surface, ['kind' => 'tin'], $current);
    $this->assertSame(['opener' => FALSE, 'volume' => 400], $accepted['kind_settings']);
    // And what is sent is read over those defaults, not over the jar's.
    $accepted = $this->pipeline()->accept($surface, [
      'kind' => 'tin',
      'kind_settings' => ['opener' => TRUE],
    ], $current);
    $this->assertSame(['opener' => TRUE, 'volume' => 400], $accepted['kind_settings']);

    // A slot holding a value while its deciding key holds nothing has no
    // shape to be read by, and says so.
    $violations = $this->pipeline()->validate($surface, [
      'pantry' => 'a',
      'kind' => NULL,
      'kind_settings' => ['lid' => 'clip'],
    ]);
    $this->assertSame(['kind_settings'], $violations->keys());
    $this->assertSame('Kind settings depends on Kind, which holds no value.', (string) iterator_to_array($violations, FALSE)[0]->message);
  }

  /**
   * Tests that a child's cacheability is its parent's.
   */
  public function testCacheabilityMerges(): void {
    $child = new DataSurfaceBuilder();
    $child->setDefinition('note', DataDefinition::create('string'));
    $child->addCacheableDependency((new CacheableMetadata())->setCacheTags(['child_list']));
    $variant = new DataSurfaceBuilder();
    $variant->setDefinition('size', DataDefinition::create('integer'));
    $variant->addCacheableDependency((new CacheableMetadata())->setCacheContexts(['user.permissions']));

    $parent = new DataSurfaceBuilder();
    $parent->setDefinition('kind', DataDefinition::create('string'));
    $parent->attach('child', new SurfaceAttachment($child->seal()));
    $parent->attachBy('slot', 'kind', ['big' => new SurfaceAttachment($variant->seal())]);
    $surface = $parent->seal();

    $this->assertContains('child_list', $surface->getCacheTags());
    $this->assertContains('user.permissions', $surface->getCacheContexts());
  }

  /**
   * Tests describe(): the one change to an existing key anyone may make.
   */
  public function testDescribeRewordsAndNothingElse(): void {
    $builder = new DataSurfaceBuilder();
    $typed_data = $this->container->get('typed_data_manager');
    $builder->setDefinition('limit', DataDefinition::create('integer')
      ->setLabel('Limit')
      ->setRequired(TRUE)
      ->addConstraint('Range', ['max' => 5]));
    $first = new SurfaceShapeAdditions($builder, $typed_data, 'first_module');
    $first->add('badge', 'string', 'Badge');
    $second = new SurfaceShapeAdditions($builder, $typed_data, 'second_module');

    // An owner's key, by name: wording only.
    $second->describe('limit', label: 'How many', description: 'At most five.');
    $limit = $builder->getDefinition('limit');
    $this->assertSame('How many', (string) $limit->getLabel());
    $this->assertSame('At most five.', (string) $limit->getDescription());
    $this->assertTrue($limit->isRequired());
    $this->assertSame(['max' => 5], $limit->getConstraints()['Range']);
    // NULL leaves a part alone.
    $second->describe('limit', description: 'Five, at most.');
    $this->assertSame('How many', (string) $limit->getLabel());
    // Its own key, by name; another alter's, by its mounted path.
    $first->describe('badge', label: 'Ribbon');
    $second->describe('third_party_settings.first_module.badge', description: 'Beside the title.');
    $badge = $builder->getThirdPartyDefinition('first_module', 'badge');
    $this->assertSame('Ribbon', (string) $badge->getLabel());
    $this->assertSame('Beside the title.', (string) $badge->getDescription());

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('describe() was asked to reword the input "badge", which nothing has declared.');
    $second->describe('badge', label: 'Not mine');
  }

  /**
   * Tests a module's mount is one fieldset, titled by the module.
   *
   * The map the mounts sit in only groups them, so it is marked to be
   * drawn as nothing of its own. Each module's map is titled with the
   * module's name until its alter names it, by the mount's path, which
   * only answers once the module has added a key.
   */
  public function testDescribeTitlesEachModuleMount(): void {
    $builder = new DataSurfaceBuilder();
    $typed_data = $this->container->get('typed_data_manager');
    $first = new SurfaceShapeAdditions($builder, $typed_data, 'first_module');
    $second = new SurfaceShapeAdditions($builder, $typed_data, 'data_surface_surface_test');
    $first->add('badge', 'string', 'Badge');
    $second->add('ribbon', 'string', 'Ribbon');
    $first->describe('third_party_settings.first_module', label: 'Badges', description: 'How the title is decorated.');
    $mount = $builder->seal()->getDefinition('third_party_settings');
    $this->assertInstanceOf(MapDataDefinition::class, $mount);
    $this->assertTrue(DefinitionMetadata::isGrouping($mount));
    // Kept for a reader with the definition alone, such as the Tool API.
    $this->assertSame('Third party settings', (string) $mount->getLabel());
    $badges = $mount->getPropertyDefinition('first_module');
    $this->assertInstanceOf(MapDataDefinition::class, $badges);
    $this->assertSame('Badges', (string) $badges->getLabel());
    $this->assertSame('How the title is decorated.', (string) $badges->getDescription());
    $this->assertFalse(DefinitionMetadata::isGrouping($badges));
    $this->assertSame(['badge'], array_keys($badges->getPropertyDefinitions()));
    // Unnamed, a mount is titled with the module's human name, and no
    // more: no "settings" after it.
    $this->assertSame('Data Surface Surface Test', (string) $mount->getPropertyDefinition('data_surface_surface_test')->getLabel());

    $third = new SurfaceShapeAdditions(new DataSurfaceBuilder(), $typed_data, 'third_module');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('describe() was asked to reword the input "third_party_settings.third_module", which nothing has declared.');
    $third->describe('third_party_settings.third_module', label: 'Too soon');
  }

  /**
   * Tests describe() places an alter's own mount, and nothing else.
   *
   * Placement says where one module's fieldset is drawn, so only that
   * module's alter says it, of its own input mount, after a top-level
   * key of the owner's shape. Every other use is refused, naming why.
   */
  public function testDescribePlacesOnlyItsOwnMount(): void {
    $typed_data = $this->container->get('typed_data_manager');
    $builder = new DataSurfaceBuilder();
    $owner = new SurfaceShape($builder, $typed_data);
    $owner->add('title', 'string', 'Title');
    $owner->add('capacity', 'integer', 'Capacity');
    $first = new SurfaceShapeAdditions($builder, $typed_data, 'first_module');
    $second = new SurfaceShapeAdditions($builder, $typed_data, 'second_module');
    $outputs = new SurfaceShapeAdditions($builder, $typed_data, 'first_module', TRUE);
    $first->add('badge', 'string', 'Badge');
    $second->add('ribbon', 'string', 'Ribbon');
    $outputs->add('shown', 'string', 'Shown');

    $first->describe('third_party_settings.first_module', label: 'Badges', after: 'capacity');
    $mounts = $builder->seal()->getDefinition('third_party_settings');
    $this->assertInstanceOf(MapDataDefinition::class, $mounts);
    $this->assertSame('capacity', DefinitionMetadata::getPlacedAfter($mounts->getPropertyDefinition('first_module')));
    $this->assertSame('Badges', (string) $mounts->getPropertyDefinition('first_module')->getLabel());
    // Where it is drawn, not where it is stored: the values stay under
    // the mount, and an unplaced mount says nothing.
    $this->assertSame(['first_module', 'second_module'], array_keys($mounts->getPropertyDefinitions()));
    $this->assertNull(DefinitionMetadata::getPlacedAfter($mounts->getPropertyDefinition('second_module')));
    $this->assertNull(DefinitionMetadata::getPlacedAfter($mounts));

    $refusals = [
      // Another module's mount.
      [
        $first, 'third_party_settings.second_module', 'title',
        'Only an alter places, and only its own input mount, third_party_settings.<module>: this alter\'s is third_party_settings.first_module.',
      ],
      // A key, not a mount.
      [$first, 'badge', 'title', 'describe() was asked to place "badge" after "title".'],
      [$first, 'title', 'capacity', 'describe() was asked to place "title" after "capacity".'],
      // The owner orders its own keys.
      [$owner, 'title', 'capacity', 'an owner orders its own keys by declaring them in order.'],
      // An output mount is never drawn.
      [
        $outputs, 'third_party_outputs.first_module', 'title',
        'outputs are never drawn, so they have nowhere to be placed.',
      ],
      // After nothing the owner declared, after the mounts themselves,
      // or after another alter's key.
      [
        $first, 'third_party_settings.first_module', 'missing',
        'describe() was asked to place third_party_settings.first_module after "missing", which is no top-level key of the owner\'s shape.',
      ],
      [
        $first, 'third_party_settings.first_module', 'third_party_settings',
        'after "third_party_settings", which is no top-level key',
      ],
      [$first, 'third_party_settings.first_module', 'badge', 'after "badge", which is no top-level key'],
    ];
    foreach ($refusals as [$shape, $key, $after, $message]) {
      try {
        $shape->describe($key, after: $after);
        $this->fail(sprintf('Refused: %s', $message));
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage());
      }
    }
  }

  /**
   * Gets a target that stores nothing, for submissions that must fail.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The target.
   */
  protected function nowhere(): DataSurfaceTargetInterface {
    return new class() implements DataSurfaceTargetInterface {

      /**
       * {@inheritdoc}
       */
      public function load(DataSurfaceInterface $surface): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
        return new PreparedValues($values, $values);
      }

      /**
       * {@inheritdoc}
       */
      public function commit(PreparedValues $prepared): void {
        throw new \LogicException('Nothing is committed here.');
      }

    };
  }

  /**
   * Tests that targets compose along the tree, editing.
   *
   * The pantry's target loads and stores what is its own and the jar's
   * settings, which have no target; the label and the tin, which have,
   * are loaded from and committed to theirs, in the context the pantry's
   * hands them, so they load by the same identity.
   */
  public function testTargetsComposeWhenEditing(): void {
    $state = $this->container->get('state');
    $state->set(PantryTarget::key('larder'), [
      'values' => [
        'pantry' => 'larder',
        'kind' => 'jar',
        'shelf' => ['unit' => 'in', 'height' => 20],
        'kind_settings' => ['lid' => 'clip', 'volume' => 250],
      ],
    ]);
    $state->set(PantryTarget::key('larder', PantryLabelTarget::PART), ['values' => ['text' => 'Larder']]);

    $context = PantrySurface::edit('larder');
    $surface = $this->pantry($context);
    $target = $this->surfaces()->target(PantrySurface::class, $context, $surface);
    $loaded = $target->load($surface);
    $this->assertSame(['unit' => 'in', 'height' => 20], $loaded['shelf']);
    $this->assertSame(['text' => 'Larder'], $loaded['label']);
    $this->assertSame(['lid' => 'clip', 'volume' => 250], $loaded['kind_settings']);

    // Moved to a tin: the tin is stored apart, by its own target, and the
    // pantry no longer holds kind settings of its own.
    $result = $this->pipeline()->submit($surface, [
      'label' => ['text' => 'Big larder'],
      'kind' => 'tin',
      'kind_settings' => ['volume' => '300'],
    ], $target);
    $this->assertTrue($result->isValid(), implode(', ', $result->violations->keys()));
    $this->assertTrue($result->committed);

    $pantry = $state->get(PantryTarget::key('larder'));
    $this->assertSame('tin', $pantry['values']['kind']);
    $this->assertArrayNotHasKey('label', $pantry['values']);
    $this->assertArrayNotHasKey('kind_settings', $pantry['values']);
    $this->assertSame([
      'unit' => 'in',
      'height' => 20,
      'third_party_settings' => ['data_surface_surface_test' => ['sturdy' => TRUE]],
    ], $pantry['values']['shelf']);
    $label = $state->get(PantryTarget::key('larder', PantryLabelTarget::PART));
    $this->assertSame(['text' => 'Big larder'], $label['values']);
    $this->assertSame('larder', $label['known']['pantry']);
    $tin = $state->get(PantryTarget::key('larder', PantryTinTarget::PART));
    $this->assertSame(['opener' => FALSE, 'volume' => 300], $tin['values']);

    // The tin, loaded back through the composed target, from its own.
    $this->assertSame(['opener' => FALSE, 'volume' => 300], $this->surfaces()->target(PantrySurface::class, $context)->load($surface)['kind_settings']);
  }

  /**
   * Tests that a child stored apart learns its new parent's identity.
   *
   * A creating context does not know the pantry yet, so neither does the
   * label's; the pantry's accepted identity is handed to the label's
   * context when it is committed, after the pantry.
   */
  public function testChildLearnsCreatedParentsIdentity(): void {
    $context = PantrySurface::add();
    $surface = $this->pantry($context);
    $target = $this->surfaces()->target(PantrySurface::class, $context, $surface);
    $this->assertSame([], $target->load($surface));

    $result = $this->pipeline()->submit($surface, ['pantry' => 'cellar', 'label' => ['text' => 'Cellar']], $target);
    $this->assertTrue($result->committed, implode(', ', $result->violations->keys()));
    $state = $this->container->get('state');
    $this->assertSame('cellar', $state->get(PantryTarget::key('cellar'))['values']['pantry']);
    $label = $state->get(PantryTarget::key('cellar', PantryLabelTarget::PART));
    $this->assertSame(['text' => 'Cellar'], $label['values']);
    $this->assertSame(['pantry' => 'cellar'], $label['known']);
    // The jar has no target: its settings stayed with the pantry.
    $this->assertSame(['lid' => 'screw', 'volume' => 500], $state->get(PantryTarget::key('cellar'))['values']['kind_settings']);
    $this->assertNull($state->get(PantryTarget::key('cellar', PantryTinTarget::PART)));
  }

}
