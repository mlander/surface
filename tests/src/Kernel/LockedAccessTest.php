<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\ResourceBuild\Resources;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_resource_test\Resource\Gallery\GalleryResource;
use Drupal\data_surface_resource_test\Structure\Gallery\OilFinishStructure;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool_test\Resource\CabinetResource;
use Drupal\data_surface_tool_test\Storage\CabinetStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a resource locked whole, a variant locked, and a read only call.
 *
 * The cabinet's access answers open for a keeper, locked for an auditor
 * and omitted for anyone else; its code lock is offered locked unless the
 * account sets codes; counting its drawers is declared read only.
 *
 * @see docs/decisions.md#locked-at-the-top
 * @see docs/decisions.md#a-variant-is-open-locked-or-not-offered
 * @see docs/decisions.md#an-operation-states-how-it-behaves
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class LockedAccessTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * What the cabinet holds before each test.
   */
  protected const STORED = [
    'label' => 'Filing',
    'drawers' => 6,
    'lock' => 'code',
    'lock_settings' => ['digits' => 5],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The first account is the super user; every test acts as another.
    $this->setUpCurrentUser(admin: TRUE);
    $this->container->get('state')->set(CabinetStorage::KEY, self::STORED);
  }

  /**
   * Acts as an account with these permissions.
   *
   * @param string ...$permissions
   *   The permissions.
   */
  protected function actAs(string ...$permissions): void {
    $this->setUpCurrentUser([], $permissions);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('data_surface.resources');
  }

  /**
   * Submits values to the cabinet's update operation, as a form would.
   *
   * @param array $input
   *   The values.
   * @param string $operation
   *   The operation.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result.
   */
  protected function submit(array $input, string $operation = 'update') {
    $context = $this->resources()->operation(CabinetResource::class, $operation);
    $built = $this->resources()->build(CabinetResource::class, $context);
    return $this->pipeline()->submit($built, $input, $this->resources()->storage(CabinetResource::class, $context, $built), access: $this->resources()->access(CabinetResource::class, $context));
  }

  /**
   * Runs one tool.
   *
   * @param string $id
   *   The plugin id.
   * @param array $inputs
   *   The inputs, by name.
   *
   * @return array{success: bool, message: string, values: mixed}
   *   Whether it succeeded, its message and its values output.
   */
  protected function runTool(string $id, array $inputs = []): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => PlainTextOutput::renderFromHtml((string) $result->getMessage()),
      'values' => $result->getContextValues()[OperationInputs::VALUES] ?? NULL,
    ];
  }

  /**
   * Tests a resource its access locks at the top: shown, fixed, refused.
   */
  public function testLockedAtTheTop(): void {
    $this->actAs('audit cabinet');
    $context = $this->resources()->operation(CabinetResource::class, 'update');
    // Locked is let in: the route and the read tool answer it.
    $this->assertTrue($this->resources()->access(CabinetResource::class, $context)->isAllowed());
    $built = $this->resources()->build(CabinetResource::class, $context);
    $this->assertSame('The cabinet may be audited, not changed.', (string) $built->lockedWhole());
    foreach ($built->getDefinitions()->names() as $key) {
      $this->assertTrue($built->isLocked($key), $key);
      // Held to what is stored, not fixed to a declared default.
      $this->assertTrue($built->getDefinitions()->isHeld($key), $key);
    }

    // Every write door is refused by name before anything is written.
    $result = $this->submit(['drawers' => 9]);
    $this->assertFalse($result->isValid());
    $this->assertFalse($result->committed);
    $violation = $result->violations->byKey('')[0];
    $this->assertSame('', $violation->fullPath());
    $this->assertSame(Violation::LOCKED, $violation->code);
    $this->assertSame('This may be seen and not changed, so nothing was written: The cabinet may be audited, not changed.', (string) $violation->message);
    $this->assertSame(self::STORED, $this->container->get('state')->get(CabinetStorage::KEY));

    $write = $this->runTool('data_surface:tool_test.cabinet:update', [OperationInputs::VALUES => ['drawers' => 9]]);
    $this->assertFalse($write['success']);
    $this->assertStringContainsString('The cabinet may be audited, not changed.', $write['message']);
    $read = $this->runTool('data_surface:tool_test.cabinet:update:read');
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame(self::STORED, $read['values']);

    // Both documents say it at the top and on every key.
    $emitter = $this->container->get('data_surface.contract_emitter');
    $documents = [
      'the schema for these answers' => $emitter->emit($built, self::STORED, 'tool_test.cabinet', 'update')->document['schema'],
      'the full schema' => $emitter->emitFull($this->resources()->buildStatic(CabinetResource::class, $context), 'tool_test.cabinet', 'update')->document['schema'],
    ];
    foreach ($documents as $which => $schema) {
      $this->assertTrue($schema['readOnly'] ?? FALSE, $which);
      $this->assertTrue($schema['x-surface']['locked'] ?? FALSE, $which);
      foreach (['label', 'drawers', 'lock'] as $key) {
        $this->assertTrue($schema['properties'][$key]['readOnly'] ?? FALSE, "$which: $key");
      }
    }
    // The schema for these answers holds the stored values, chosen by
    // what the deciding key holds, not by its default.
    $this->assertSame(6, $documents['the schema for these answers']['properties']['drawers']['const']);
    $this->assertArrayNotHasKey('const', $documents['the full schema']['properties']['drawers']);

    // Open for a keeper, as before, and nothing is said at the top.
    $this->actAs('keep cabinet', 'set cabinet codes');
    $open = $this->resources()->build(CabinetResource::class, $context);
    $this->assertNull($open->lockedWhole());
    $this->assertFalse($open->isLocked('drawers'));
    $this->assertArrayNotHasKey('readOnly', $emitter->emit($open, self::STORED, 'tool_test.cabinet', 'update')->document['schema']);
    $this->assertTrue($this->submit(['drawers' => 9])->committed);

    // Omitted is still refused.
    $this->actAs();
    $this->assertTrue($this->resources()->access(CabinetResource::class, $context)->isForbidden());
  }

  /**
   * Tests a variant its access locks is offered, shown and fixed.
   */
  public function testLockedVariantIsOfferedReadOnly(): void {
    $this->actAs('keep cabinet');
    $built = $this->resources()->buildOperation(CabinetResource::class, 'update');
    // Offered: both values can be chosen.
    $offered = $built->getDefinitions()->entry('lock_settings')?->variants?->variantIds() ?? [];
    sort($offered);
    $this->assertSame(['code', 'key'], $offered);
    $code = static::variant($built, 'code');
    $this->assertSame('Only a locksmith sets a code.', (string) $code->lockedWhole());
    $this->assertTrue($code->isLocked('digits'));
    $this->assertFalse(static::variant($built, 'key')->isLocked('keys'));
    // The parent is not refused by it, and is written whole.
    $this->assertTrue($this->resources()->access(CabinetResource::class, $this->resources()->operation(CabinetResource::class, 'update'))->isAllowed());

    // What a locked variant holds is kept, whatever is posted for it, and
    // the caller is told its value was not taken, once per key.
    $result = $this->submit(['drawers' => 3, 'lock_settings' => ['digits' => 8]]);
    $this->assertTrue($result->committed, implode(' ', array_map(static fn ($v): string => (string) $v->message, iterator_to_array($result->violations))));
    $stored = $this->container->get('state')->get(CabinetStorage::KEY);
    $this->assertSame(3, $stored['drawers']);
    $this->assertSame(['digits' => 5], $stored['lock_settings']);
    $this->assertCount(1, $result->notes);
    $this->assertSame('lock_settings.digits', $result->notes[0]->fullPath());
    $this->assertSame('Digits is locked and kept as stored; the value sent was not taken.', (string) $result->notes[0]->message);
    $this->assertSame([], $this->submit(['lock_settings' => ['digits' => 5]])->notes, 'What it holds, sent back, was taken.');
    $write = $this->runTool('data_surface:tool_test.cabinet:update', [OperationInputs::VALUES => ['lock_settings' => ['digits' => 7]]]);
    $this->assertTrue($write['success'], $write['message']);
    $this->assertSame("Edit the cabinet: the values were accepted, and they are what is stored, so nothing was written.\n/lock_settings/digits: Digits is locked and kept as stored; the value sent was not taken.", $write['message']);

    // Chosen anew from the key lock, it starts from its defaults, fixed.
    $this->container->get('state')->set(CabinetStorage::KEY, ['lock' => 'key', 'lock_settings' => ['keys' => 3]] + self::STORED);
    $result = $this->submit(['lock' => 'code', 'lock_settings' => ['digits' => 8]]);
    $this->assertTrue($result->committed, implode(' ', array_map(static fn ($v): string => (string) $v->message, iterator_to_array($result->violations))));
    $this->assertSame(['digits' => 4], $this->container->get('state')->get(CabinetStorage::KEY)['lock_settings']);
    $this->assertSame(['lock_settings.digits'], array_map(static fn ($note): string => $note->fullPath(), $result->notes));

    // Both documents mark the variant's keys locked.
    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = $emitter->emitFull($this->resources()->buildStatic(CabinetResource::class, $this->resources()->operation(CabinetResource::class, 'update')), 'tool_test.cabinet', 'update')->document['schema'];
    // The code lock's site says its key is fixed; the key lock's says
    // nothing of the kind.
    $sites = [];
    foreach ($full['allOf'] as $conditional) {
      $sites[$conditional['if']['properties']['lock']['const']] = json_encode($conditional['then']);
    }
    $this->assertStringContainsString('"digits":{"default":4,"readOnly":true', $sites['code']);
    $this->assertStringNotContainsString('readOnly', $sites['key']);

    // A locksmith finds it open.
    $this->actAs('keep cabinet', 'set cabinet codes');
    $built = $this->resources()->buildOperation(CabinetResource::class, 'update');
    $this->assertNull(static::variant($built, 'code')->lockedWhole());
  }

  /**
   * Tests an operation declared read only writes nothing at any door.
   */
  public function testReadOnlyOperationWritesNothing(): void {
    $this->actAs('keep cabinet');
    $built = $this->resources()->buildOperation(CabinetResource::class, 'count');
    $this->assertTrue($built->changesNothing());
    $this->assertFalse($this->resources()->buildOperation(CabinetResource::class, 'update')->changesNothing());

    // Accepted, validated and prepared, as a dry run is, never committed.
    $result = $this->submit(['drawers' => 9], 'count');
    $this->assertTrue($result->isValid(), implode(' ', array_map(static fn ($v): string => (string) $v->message, iterator_to_array($result->violations))));
    $this->assertFalse($result->committed);
    $this->assertNotNull($result->prepared);
    $this->assertTrue($result->prepared->changesNothing);
    $this->assertSame(self::STORED, $this->container->get('state')->get(CabinetStorage::KEY));
    // Still refused by its rules.
    $this->assertFalse($this->submit(['drawers' => 40], 'count')->isValid());

    // A caller that commits what was prepared for it is refused.
    $context = $this->resources()->operation(CabinetResource::class, 'count');
    $storage = $this->resources()->storage(CabinetResource::class, $context, $built);
    try {
      $this->pipeline()->commit($result->prepared, $storage);
      $this->fail('A read only operation\'s values were committed.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('changes nothing', $e->getMessage());
    }
    $this->assertSame(self::STORED, $this->container->get('state')->get(CabinetStorage::KEY));

    // The tool says so, and writes nothing.
    $tool = $this->runTool('data_surface:tool_test.cabinet:count', [OperationInputs::VALUES => ['drawers' => 9]]);
    $this->assertTrue($tool['success'], $tool['message']);
    $this->assertSame('Count the drawers changes nothing: the values were accepted and prepared, and nothing was written.', $tool['message']);
    $this->assertSame(self::STORED, $this->container->get('state')->get(CabinetStorage::KEY));
  }

  /**
   * Tests access builds a resource only when a variant could refuse it.
   *
   * Only a variant chosen by a locked deciding key may refuse its
   * parent, so a site where no variant names an access class answers
   * access with no build.
   */
  public function testChildrenAnswerOnlyThroughVariants(): void {
    $resources = $this->resources();
    $this->assertInstanceOf(Resources::class, $resources);
    $may = new \ReflectionMethod(Resources::class, 'childrenMayAnswer');
    $registry = $this->container->get('data_surface.resource_registry');
    // The code lock is a variant with an access class.
    $this->assertTrue($may->invoke($resources, $registry->getDefinition(CabinetResource::class)));

    // Without this module, the access classes left are none of a variant's,
    // and the one variant answered for is the gallery's oil finish, by an
    // alter: it alone could refuse, so nothing else is built for it.
    $this->installSchema('user', ['users_data']);
    $this->container->get('module_installer')->uninstall(['data_surface_tool_test']);
    $this->container->get('module_installer')->install(['data_surface_resource_test']);
    $resources = $this->container->get('data_surface.resources');
    $registry = $this->container->get('data_surface.resource_registry');
    $access = array_filter($registry->getDefinitions(), static fn ($definition): bool => $definition->access !== NULL);
    $this->assertNotEmpty($access, 'Some resource or structure names an access class.');
    $answered = [];
    foreach ($registry->getDefinitions() as $definition) {
      foreach ($definition->variants as $variants) {
        foreach ($variants as $class) {
          if ($registry->getDefinition($class)->answersAccess()) {
            $answered[] = $registry->getDefinition($class)->id;
          }
        }
      }
    }
    $this->assertSame(['resource_test.gallery.finish.oil'], $answered);
    $this->assertNull($registry->getDefinition(OilFinishStructure::class)->access, 'Answered for by an alter, with no access class.');
    $this->assertTrue($may->invoke($resources, $registry->getDefinition(GalleryResource::class)));
    $this->assertFalse($may->invoke($resources, $registry->getDefinition(OilFinishStructure::class)), 'Nothing but itself answers.');
  }

  /**
   * Gets a built resource's chosen variant at a key, for a value.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The built resource.
   * @param string $value
   *   The deciding value.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The variant.
   */
  protected static function variant(DataSurfaceInterface $built, string $value): DataSurfaceInterface {
    $variants = $built->getDefinitions()->entry('lock_settings')?->variants;
    assert($variants !== NULL);
    return $variants->variant($value)->child;
  }

}
