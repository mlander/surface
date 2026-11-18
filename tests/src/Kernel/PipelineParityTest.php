<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Surface;
use Drupal\surface\Entries;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Pipeline\UnknownKeysException;
use Drupal\surface\Pipeline\Storage\PluginConfigurationStorage;
use Drupal\surface\Pipeline\Storage\StateStorage;
use Drupal\surface_test\ConfigurableHostPlugin;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that every caller reaches storage through the same pipeline.
 *
 * The stages a non-form caller depends on: refusing undeclared keys,
 * merging over stored values, preparing without writing, and leaving
 * host-owned storage keys alone. That a generated form and a raw payload
 * carrying the same strings produce the same values is held by
 * InputCoercionMatrixTest (the form door and the tool door) and
 * SurfacePipelineCastingTest.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class PipelineParityTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'surface', 'surface_test'];

  /**
   * The state key the state storage writes to.
   */
  protected const STATE_KEY = 'surface.parity';

  /**
   * Builds a data surface covering every shape the coercion rules touch.
   *
   * A string, an integer, a float, a boolean, a select with labeled
   * choices, a nested map carrying a default, and a locked key.
   */
  protected function surface(): SurfaceInterface {
    $badge = DataDefinition::create('string')
      ->setLabel('Badge')
      ->addConstraint('Choice', ['choices' => ['star', 'flame']]);
    DefinitionMetadata::setDefaultValue($badge, 'flame');
    $extras = MapDataDefinition::create()->setLabel('Extras');
    $extras->setPropertyDefinition('badge', $badge);
    $extras->setPropertyDefinition('weight', DataDefinition::create('integer')
      ->setLabel('Weight'));

    $definitions = [
      'title' => DataDefinition::create('string')->setLabel('Title')->setRequired(TRUE),
      'count' => DataDefinition::create('integer')->setLabel('Count'),
      'ratio' => DataDefinition::create('float')->setLabel('Ratio'),
      'active' => DataDefinition::create('boolean')->setLabel('Active'),
      'mode' => DataDefinition::create('integer')
        ->setLabel('Mode')
        ->addConstraint('LabeledChoice', [
          'choices' => [0, 1, 2],
          'labels' => [0 => 'Disabled', 1 => 'Optional', 2 => 'Required'],
        ]),
      'extras' => $extras,
      'flavor' => DataDefinition::create('string')->setLabel('Flavor'),
    ];
    DefinitionMetadata::setDefaultValue($definitions['title'], 'Hello');
    DefinitionMetadata::setDefaultValue($definitions['mode'], 1);
    DefinitionMetadata::setDefaultValue($definitions['flavor'], 'vanilla');

    return new Surface(
      Entries::fromArrays(
        schemas: $definitions,
        locked: ['flavor'],
      ),
    );
  }

  /**
   * The raw payload a non-form caller would send.
   *
   * Everything arrives as a string, which is what a query string, a
   * config action, or an agent that read the data surface would produce.
   *
   * @return array
   *   The payload.
   */
  protected function payload(): array {
    return [
      'title' => 'Typed title',
      'count' => '7',
      'ratio' => '1.5',
      'active' => '1',
      'mode' => '2',
      'extras' => ['badge' => 'star', 'weight' => '3'],
      // Tampering with a locked key is ignored on both paths.
      'flavor' => 'chocolate',
    ];
  }

  /**
   * Tests an undeclared key is refused rather than dropped.
   */
  public function testUnknownKeysAreRefused(): void {
    $surface = $this->surface();
    $storage = new StateStorage($this->container->get('state'), self::STATE_KEY);

    $result = $this->pipeline()->submit($surface, ['title' => 'Fine', 'bogus' => 'x'], $storage);
    $this->assertFalse($result->isValid());
    $this->assertSame(['bogus'], $result->violations->keys());
    $this->assertSame('Unknown key bogus.', (string) $result->violations->byKey('bogus')[0]->message);
    $this->assertSame('', $result->violations->byKey('bogus')[0]->path);
    $this->assertNull($result->prepared);
    $this->assertFalse($result->committed);
    $this->assertNull($this->container->get('state')->get(self::STATE_KEY));

    // A nested undeclared key is reported on its top-level key, with the
    // rest of its path, exactly like a constraint violation inside a map.
    $nested = $this->pipeline()->submit($surface, ['extras' => ['badge' => 'star', 'bogus' => 1]], $storage);
    $this->assertSame(['extras'], $nested->violations->keys());
    $this->assertSame('Unknown key extras.bogus.', (string) $nested->violations->byKey('extras')[0]->message);
    $this->assertSame('bogus', $nested->violations->byKey('extras')[0]->path);

    // The exception itself names the keys and the level they were found
    // in, for callers that would rather catch than read violations.
    try {
      $this->pipeline()->accept($surface, ['extras' => ['bogus' => 1]]);
      $this->fail('Expected an UnknownKeysException.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame(['bogus'], $e->getKeys());
      $this->assertSame('extras', $e->getPath());
    }
  }

  /**
   * Tests partial input merges over the values the storage holds.
   */
  public function testPartialInputMergesOverCurrent(): void {
    $surface = $this->surface();
    $state = $this->container->get('state');
    $state->set(self::STATE_KEY, [
      'title' => 'Stored title',
      'count' => 3,
      'ratio' => 0.5,
      'active' => TRUE,
      'mode' => 2,
      'extras' => ['badge' => 'star', 'weight' => 2],
      'flavor' => 'vanilla',
    ]);
    $storage = new StateStorage($state, self::STATE_KEY);

    $result = $this->pipeline()->submit($surface, ['count' => '9', 'extras' => ['weight' => '4']], $storage);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    // Only what was sent moved; the stored siblings survived, at both
    // levels, and the untouched default did not overwrite storage.
    $this->assertSame([
      'title' => 'Stored title',
      'count' => 9,
      'ratio' => 0.5,
      'active' => TRUE,
      'mode' => 2,
      'extras' => ['badge' => 'star', 'weight' => 4],
      'flavor' => 'vanilla',
    ], $result->values);
    $this->assertSame($result->values, $state->get(self::STATE_KEY));
  }

  /**
   * Tests a dry run prepares the artifact and writes nothing.
   */
  public function testDryRunPreparesWithoutCommitting(): void {
    $surface = $this->surface();
    $state = $this->container->get('state');
    $storage = new StateStorage($state, self::STATE_KEY);

    $result = $this->pipeline()->submit($surface, $this->payload(), $storage, TRUE);

    $this->assertTrue($result->isValid());
    $this->assertFalse($result->committed);
    $this->assertNotNull($result->prepared);
    $this->assertSame($result->values, $result->prepared->artifact);
    $this->assertSame([], $result->prepared->dependencies);
    $this->assertNull($state->get(self::STATE_KEY));

    // The same submission without the dry run writes exactly what the
    // dry run previewed.
    $committed = $this->pipeline()->submit($surface, $this->payload(), $storage);
    $this->assertTrue($committed->committed);
    $this->assertSame($result->prepared->artifact, $state->get(self::STATE_KEY));
  }

  /**
   * Tests a plugin's host-owned configuration keys survive a submit.
   */
  public function testPluginConfigurationStorageKeepsHostKeys(): void {
    $surface = $this->surface();
    $plugin = new ConfigurableHostPlugin();
    $storage = new PluginConfigurationStorage($plugin);

    // Loading narrows to the data surface's keys, so the host's own keys
    // never reach accept() and cannot be refused as unknown.
    $this->assertSame(['title' => 'Stored title', 'count' => 3], $storage->load($surface));

    $result = $this->pipeline()->submit($surface, $this->payload(), $storage);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    $this->assertSame([
      'title' => 'Typed title',
      'count' => 7,
      'ratio' => 1.5,
      'active' => TRUE,
      'mode' => 2,
      'extras' => ['badge' => 'star', 'weight' => 3],
      'flavor' => 'vanilla',
      'id' => 'example_block',
      'label' => 'Example',
      'provider' => 'surface',
    ], $plugin->getConfiguration());
  }

}
