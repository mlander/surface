<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Compute\MachineName;
use Drupal\data_surface\Computed;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Compute;
use Drupal\data_surface\ResourceBuild\ResourceBlueprint;
use Drupal\data_surface\ResourceBuild\Shapes;
use Drupal\data_surface_resource_test\Compute\Broken\TransliteratedCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stock MachineName and the `machine_name` type it computes.
 *
 * MachineName runs as the pipeline runs it, through Computed with the
 * container guarded: transliterated, lowercased, each run outside
 * `[a-z0-9_]` one underscore, cut to the key's Length, trimmed of
 * underscores, as core's machine-name.js does. The guard admits
 * transliteration to it and to nothing else.
 *
 * @see docs/decisions.md#a-machine-name-is-a-computed-identity
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class MachineNameTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_resource_test',
  ];

  /**
   * Computes a machine name from a label as the pipeline does.
   *
   * @param string|null $label
   *   The label.
   * @param \Drupal\Core\TypedData\DataDefinition|null $key
   *   The computed key's definition; NULL for none.
   *
   * @return mixed
   *   The machine name, or NULL.
   */
  protected static function machineName(?string $label, ?DataDefinition $key = NULL): mixed {
    return (new Computed(['label'], MachineName::class, Compute::UNLESS_SENT))->compute(['label' => $label], $key);
  }

  /**
   * Tests a label with accents is transliterated, as the browser does.
   */
  public function testTransliteratesAsTheBrowserDoes(): void {
    // cspell:ignore Straße strasse platz
    $this->assertSame('cafe_deja_vu', static::machineName('Café Déjà Vu'));
    $this->assertSame('strasse_und_platz', static::machineName('Straße und Platz'));
    $this->assertSame('hello_world', static::machineName('  --Hello,   World!!  '), 'Each run of what it may not hold is one underscore, trimmed at both ends.');
    $this->assertSame('a__b', static::machineName('a_ b'), 'An underscore is kept, as machine-name.js keeps it.');
    $this->assertNull(static::machineName(''));
    $this->assertNull(static::machineName('!!!'));
    $this->assertNull(static::machineName(NULL));
  }

  /**
   * Tests a 200 character label is cut to the key's Length, or to 166.
   */
  public function testCutsToTheLengthOfTheKey(): void {
    $label = str_repeat('Grand Hall ', 18) . 'Annex';
    $this->assertGreaterThanOrEqual(200, strlen($label));
    $name = static::machineName($label);
    $this->assertSame(MachineName::MAX_LENGTH, strlen($name), 'No Length stated: a machine_name\'s 166.');
    $this->assertSame(substr(str_repeat('grand_hall_', 18), 0, 166), $name);
    $short = static::machineName($label, DataDefinition::create('string')->addConstraint('Length', ['max' => 32]));
    $this->assertSame('grand_hall_grand_hall_grand_hall', $short, 'Cut to the key\'s 32, then trimmed.');
    $this->assertSame('grand_hall_grand_hall_grand', static::machineName($label, DataDefinition::create('string')->addConstraint('Length', ['max' => 28])), 'An underscore left at the cut is trimmed, as the browser trims it.');
  }

  /**
   * Tests the guard admits transliteration to MachineName and nothing else.
   */
  public function testTheGuardAdmitsTransliterationToMachineNameAlone(): void {
    $this->assertSame('ada', static::machineName('Ada'));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The computed key\'s ' . TransliteratedCode::class . '::compute() asked for the service transliteration.');
    (new Computed(['first'], TransliteratedCode::class))->compute(['first' => 'Ada']);
  }

  /**
   * Tests a key of the `machine_name` type holds the type's constraints.
   */
  public function testTheTypeIsCoresConfigSchemaType(): void {
    $shapes = new Shapes($this->container->get('data_surface.resource_registry'), $this->container->get('class_resolver'), $this->container->get('config.typed'));
    $blueprint = (new ResourceBlueprint(new DataSurfaceBuilder(), $this->container->get('typed_data_manager')))->useShapes($shapes);
    $blueprint->add('label', 'string', 'Name');
    $key = $blueprint->compute('id', 'machine_name', 'Machine name', from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT);
    $this->assertSame('string', $key->getDataType(), 'The string underneath.');
    $this->assertSame('machine_name', DefinitionMetadata::configTypeOf($key));
    $constraints = $key->getConstraints();
    $this->assertSame('/^[a-z0-9_]+$/D', $constraints['Regex']['pattern'], 'Core\'s pattern, read with D, which both engines read alike.');
    $this->assertSame(['max' => 166], $constraints['Length']);
    $this->assertSame(['Regex', 'Length'], array_keys($key->toArray()['constraints']), 'The type\'s two, and nothing else declared.');
    $this->assertNull($shapes->configType('string', $this->container->get('typed_data_manager')), 'A type typed data knows is typed data\'s.');
    $this->assertNull($shapes->configType('mapping', $this->container->get('typed_data_manager')), 'A mapping is no one value.');
  }

}
