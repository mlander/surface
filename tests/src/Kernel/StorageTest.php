<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Surface;
use Drupal\surface\DefinitionMap;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Pipeline\Storage\CompositeStorage;
use Drupal\surface\Pipeline\Storage\StateStorage;
use Drupal\surface_test\RecordingStorage;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the composite storage class.
 *
 * One of the engine's destinations: several storage classes at once. It
 * reads what it stores back in the data surface's shape, it shapes values
 * without writing, and it writes only when told to. The config entity
 * storage is a resource's storage now (ConfigEntityStorageTest).
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StorageTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'surface',
  ];

  /**
   * The state key the state storage writes to.
   */
  protected const STATE_KEY = 'surface.storage_test';

  /**
   * The state key a second state storage writes to.
   */
  protected const OTHER_STATE_KEY = 'surface.storage_test_other';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  /**
   * Builds the data surface the composite storage is tested against.
   *
   * A site name and a slogan: two keys, for two destinations.
   */
  protected function siteSurface(): SurfaceInterface {
    $definitions = [
      'name' => DataDefinition::create('string')->setLabel('Site name')->setRequired(TRUE),
      'slogan' => DataDefinition::create('string')->setLabel('Slogan'),
    ];
    DefinitionMetadata::setDefaultValue($definitions['name'], 'Drupal');
    DefinitionMetadata::setDefaultValue($definitions['slogan'], '');
    return new Surface(DefinitionMap::fromArrays(definitions: $definitions));
  }

  /**
   * Tests one value set reaching two destinations, in order.
   */
  public function testCompositeStorage(): void {
    $surface = $this->siteSurface();
    $state = $this->container->get('state');
    $composite = new CompositeStorage([
      [new StateStorage($state, self::OTHER_STATE_KEY), ['name']],
      [new StateStorage($state, self::STATE_KEY), ['slogan']],
    ]);

    // Loading is the two children's answers side by side, each child
    // speaking only for the keys it owns.
    $state->set(self::OTHER_STATE_KEY, ['name' => 'Drupal']);
    $state->set(self::STATE_KEY, ['slogan' => 'From state']);
    $this->assertSame(['name' => 'Drupal', 'slogan' => 'From state'], $composite->load($surface));

    $result = $this->pipeline()->submit($surface, ['name' => 'Both places', 'slogan' => 'Said once'], $composite);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    // Each child was given only the key it was listed with.
    $this->assertSame(['name' => 'Both places'], $state->get(self::OTHER_STATE_KEY));
    $this->assertSame(['slogan' => 'Said once'], $state->get(self::STATE_KEY));

    // The artifact is one prepared set per child, in the listed order.
    $this->assertCount(2, $result->prepared->artifact);
    $this->assertSame(['name' => 'Both places'], $result->prepared->artifact[0]->artifact);
    $this->assertSame(['slogan' => 'Said once'], $result->prepared->artifact[1]->artifact);
  }

  /**
   * Tests that a data surface key no child stores is refused.
   *
   * The silent failure this replaces: the key was accepted, validated,
   * reported committed, and never written anywhere.
   */
  public function testCompositeRefusesUnclaimedKeys(): void {
    $composite = new CompositeStorage([
      [new StateStorage($this->container->get('state'), self::STATE_KEY), ['name']],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('No child of this composite storage stores the slogan key');
    $composite->prepare($this->siteSurface(), ['name' => 'Alone', 'slogan' => 'Nowhere']);
  }

  /**
   * Tests that a data surface key two children store is refused.
   *
   * The other silent failure: the key was written twice, to two
   * destinations that disagree the moment either is edited on its own.
   */
  public function testCompositeRefusesDoubleClaimedKeys(): void {
    $state = $this->container->get('state');
    $composite = new CompositeStorage([
      [new StateStorage($state, self::OTHER_STATE_KEY), ['name', 'slogan']],
      [new StateStorage($state, self::STATE_KEY), ['slogan']],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('More than one child of this composite storage stores the slogan key');
    $composite->load($this->siteSurface());
  }

  /**
   * Tests that a key a child names and the data surface does not is allowed.
   *
   * A destination may legitimately be told where a key would go before
   * any data surface mounts one there, which is how the node type demo names
   * the third party settings mount nothing has used yet.
   */
  public function testCompositeAllowsUndeclaredKeys(): void {
    $composite = new CompositeStorage([
      [new StateStorage($this->container->get('state'), self::STATE_KEY), ['name', 'slogan', 'third_party_settings']],
    ]);

    $prepared = $composite->prepare($this->siteSurface(), ['name' => 'Fine', 'slogan' => '']);
    $this->assertCount(1, $prepared->artifact);
  }

  /**
   * Tests a composite commit happening in the order the children were listed.
   */
  public function testCompositeCommitOrder(): void {
    $surface = $this->siteSurface();
    /** @var \ArrayObject<int, string> $log */
    $log = new \ArrayObject();
    $first = $this->recordingStorage('first', $log);
    $second = $this->recordingStorage('second', $log);
    $composite = new CompositeStorage([[$first, ['name']], [$second, ['slogan']]]);

    $prepared = $composite->prepare($surface, ['name' => 'Ordered', 'slogan' => '']);
    $this->assertSame(['first prepare', 'second prepare'], $log->getArrayCopy());
    // Every child's dependencies end up in one list.
    $this->assertSame(['module' => ['first', 'second']], $prepared->dependencies);

    $composite->commit($prepared);
    $this->assertSame(
      ['first prepare', 'second prepare', 'first commit', 'second commit'],
      $log->getArrayCopy(),
    );
    // Each child was given exactly the keys it was listed with.
    $this->assertSame(['name' => 'Ordered'], $first->received);
    $this->assertSame(['slogan' => ''], $second->received);
  }

  /**
   * Tests the one child shorthand, which owns every key.
   */
  public function testCompositeBareChildOwnsEverything(): void {
    /** @var \ArrayObject<int, string> $log */
    $log = new \ArrayObject();
    $only = $this->recordingStorage('only', $log);

    $composite = new CompositeStorage([$only]);
    $composite->prepare($this->siteSurface(), ['name' => 'Everything', 'slogan' => 'Mine']);

    $this->assertSame(['name' => 'Everything', 'slogan' => 'Mine'], $only->received);
  }

  /**
   * Builds storage that writes nothing and records what it was asked.
   *
   * @param string $name
   *   The name the storage records itself under.
   * @param \ArrayObject<int, string> $log
   *   The shared log every recording storage appends to.
   *
   * @return \Drupal\surface_test\RecordingStorage
   *   The storage.
   */
  protected function recordingStorage(string $name, \ArrayObject $log): RecordingStorage {
    return new RecordingStorage($name, $log);
  }

}
