<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_examples_plugins\Structure\ExampleBlockStructure;
use Drupal\surface_examples\Resource\RegistrationStep2Resource;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface_examples_compliance\Alter\RegistrationComplianceAlter;
use Drupal\surface_resource_test\Structure\Broken\FickleStructure;
use Drupal\surface_resource_test\Structure\Broken\ServiceReadingStructure;
use Drupal\surface_resource_test\Structure\Crate\CrateStructure;
use Drupal\surface_resource_test\Structure\StockroomStructure;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a static refiner is held to being one, three ways.
 *
 * The build refuses an instance method not declared runtime (in
 * ResourceBuildTest's broken classes). With assertions on, a static
 * refiner runs with the global container guarded, so one that asks for a
 * service is caught while one that translates is not; and the emitter
 * asks a static refiner again for one combination per key it enumerates,
 * so one that answers differently is caught. Last, every enumerated
 * conditional of the examples and the fixtures is what a fresh
 * refinement answers (StaticRefinerAgreementTrait).
 *
 * @see docs/decisions.md#static-by-default-runtime-by-declaration
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StaticRefinerEnforcementTest extends SurfaceKernelTestBase {

  use StaticRefinerAgreementTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'block',
    'serialization',
    'tool',
    'surface',
    'surface_test',
    'surface_resource_test',
    'surface_examples',
    'surface_examples_plugins',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
    FickleStructure::$calls = 0;
  }

  /**
   * Builds a structure with nothing locked.
   *
   * @param class-string $class
   *   The structure class.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built structure.
   */
  protected function configure(string $class): SurfaceInterface {
    return $this->container->get('surface.resources')->build($class, new OperationContext('configure'));
  }

  /**
   * Builds one of the examples in its configure operation.
   *
   * @param class-string $class
   *   The resource class.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function example(string $class): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build($class, $resources->operation($class, 'configure'));
  }

  /**
   * Tests a static refiner that translates passes the guard.
   *
   * The global t() builds a lazy markup and asks the container for
   * nothing; reading the constraints asks for the typed data manager,
   * which the guard lets through. The markup is rendered after the
   * refiner returns, with the real container back.
   */
  public function testTranslatingStaticRefinerPassesTheGuard(): void {
    $this->assertTrue((bool) ini_get('zend.assertions'), 'The suite runs with assertions on.');
    $name = $this->configure(ServiceReadingStructure::class)->refine(['kind' => 'short'])->getDefinition('name');
    $this->assertSame(['max' => 10], $name->getConstraints()['Length']);
    $this->assertInstanceOf(TranslatableMarkup::class, $name->getDescription());
    $this->assertSame('Up to 10 characters for a short name.', (string) $name->getDescription());
    $this->assertSame($this->container, \Drupal::getContainer());
  }

  /**
   * Tests a static refiner that asks for a service is caught.
   *
   * The real container is back afterwards; and with assertions off, as in
   * production, nothing is guarded and the refiner runs.
   */
  public function testStaticRefinerAskingForServiceIsCaught(): void {
    $surface = $this->configure(ServiceReadingStructure::class);
    try {
      $surface->refine(['shelf' => 'top']);
      $this->fail('A static refiner reading State was let through.');
    }
    catch (\LogicException $e) {
      $this->assertSame(
        'The refiner ' . ServiceReadingStructure::class . '::sizeOnShelf() is declared static but asked for the service state; a refiner that needs a service must declare runtime: TRUE.',
        $e->getMessage(),
      );
    }
    $this->assertSame($this->container, \Drupal::getContainer(), 'The real container is put back.');

    // Production compiles no assertion, so the call is made as it is.
    $this->container->get('state')->set(ServiceReadingStructure::LIMIT, 7);
    ini_set('zend.assertions', '0');
    try {
      $size = $surface->refine(['shelf' => 'top'])->getDefinition('size');
    }
    finally {
      ini_set('zend.assertions', '1');
    }
    $this->assertSame(['max' => 7], $size->getConstraints()['Range']);
  }

  /**
   * Tests the emitter refuses a static refiner that answers differently.
   */
  public function testStaticRefinerAnsweringDifferentlyIsCaught(): void {
    $surface = $this->configure(FickleStructure::class);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The refiner ' . FickleStructure::class . '::sizeOfKind() is declared static but answered differently when run again, for "size" with {"kind":"small"}');
    $this->container->get('surface.contract_emitter')->emitFull($surface, 'resource_test.broken.fickle');
  }

  /**
   * Tests the agreement check itself fails a refiner that answers anew.
   *
   * With assertions off the emitter writes the fickle refiner down
   * without asking it twice; the agreement check, asking afresh, finds
   * the conditional is not what refinement answers now.
   */
  public function testTheAgreementCheckCatchesWhatTheEmitterWroteDown(): void {
    $surface = $this->configure(FickleStructure::class);
    ini_set('zend.assertions', '0');
    try {
      $this->assertStaticRefinersAgree($surface, NULL, 'resource_test.broken.fickle');
      $failed = FALSE;
    }
    catch (\Throwable $e) {
      $failed = str_contains($e->getMessage(), 'is declared static but answered differently when run again');
    }
    finally {
      ini_set('zend.assertions', '1');
    }
    $this->assertTrue($failed, 'The agreement check failed the fickle refiner.');
  }

  /**
   * Tests the examples' conditionals are what refinement answers.
   *
   * Steps 2 and 3, without the compliance module and with it, which makes
   * its two refiners static methods: the full schema, the schema for the
   * defaults, and for no values at all.
   */
  public function testTheExamplesAgree(): void {
    $compared = [];
    foreach (['without', 'with'] as $compliance) {
      if ($compliance === 'with') {
        $this->enableModules(['surface_examples_compliance']);
        // A closed room is the runtime refiner's, which no conditional
        // states; the static ones still agree.
        $this->container->get('state')->set(RegistrationComplianceAlter::CLOSED_ROOMS, ['riverside_main']);
      }
      foreach ([RegistrationStep2Resource::class, RegistrationStep3Resource::class] as $class) {
        $surface = $this->example($class);
        $label = $class . ' ' . $compliance . ' compliance';
        $compared[$label] = $this->assertStaticRefinersAgree($surface, NULL, $label)
          + $this->assertStaticRefinersAgree($surface, [], $label)
          + $this->assertStaticRefinersAgree($surface, $surface->getDefaultValues(), $label);
      }
    }
    foreach ($compared as $label => $count) {
      $this->assertGreaterThan(0, $count, $label . ' has enumerated conditionals to compare.');
    }
  }

  /**
   * Tests the fixtures with enumerated keys agree too.
   *
   * The example block (bundles per entity type, fields per bundle), the
   * stockroom (a static and a runtime refiner on one key) and the crate
   * (a key enumerated inside a variant).
   */
  public function testTheFixturesAgree(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    StockroomStructure::$closed = ['north_2'];
    foreach ([ExampleBlockStructure::class, StockroomStructure::class, CrateStructure::class] as $class) {
      $surface = $this->configure($class);
      $count = $this->assertStaticRefinersAgree($surface, NULL, $class)
        + $this->assertStaticRefinersAgree($surface, [], $class);
      $this->assertGreaterThan(0, $count, $class . ' has enumerated conditionals to compare.');
    }
  }

}
