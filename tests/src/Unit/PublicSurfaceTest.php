<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the size of the authoring API: the concept budget for the code.
 *
 * "Your first resource" is the budget for the docs, held by
 * FirstResourceDocsTest; this is the one for the names an author writes.
 * It counts what `src/Resource/` declares: the attributes, their
 * constructor arguments, the blueprint methods (BlueprintInterface and the
 * add-only BlueprintAdditionsInterface together) and the interfaces. A
 * change that adds one fails here until this test is updated with it,
 * which is the place to say why the surface grows; one that removes one
 * fails too, so the count stays the count.
 *
 * The counts were taken on 2026-10-24, when the surface was counted and
 * cut: 9 attributes, 33 arguments to 30, 17 blueprint methods to 15, 23
 * interfaces to 20. Grown on 2026-10-25 by one argument,
 * `#[Operation(gate:)]`, access borrowed from another thing's owner
 * (docs/decisions.md#a-gate-is-borrowed-never-copied): 31 arguments.
 * Grown on 2026-10-27 by one blueprint method, `resolve()`, a key read
 * otherwise at use than it is stored
 * (docs/decisions.md#a-key-may-stand-for-another-at-use): 16 methods.
 * Grown on 2026-10-28 by one interface, EntrySchemasInterface, a provider
 * of variants that answers an entry's schema only when a door reads it,
 * so a long list costs a request the entries it reads
 * (docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request):
 * 21 interfaces.
 * Grown on 2026-10-31 by four arguments, `label:` and `description:` on
 * #[Resource] and #[Structure], what a resource or structure is called
 * and is for, beside its id
 * (docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for):
 * 35 arguments.
 * Cut on 2026-11-01 by one blueprint method, `refer()`: a reference is a
 * key of the id's type with the constraint every reader already reads
 * (docs/decisions.md#a-reference-is-a-constraint-on-a-value): 17 methods.
 * Grown on 2026-11-04 by eighteen attributes and their 76 arguments, the
 * blueprint's verbs spelled as properties (#[Input], #[Output], #[Map],
 * #[Attach], #[AttachList], #[Variants], #[Each], #[AttachEach], #[Port],
 * #[Computed], #[Constraint], #[Rule], #[Describe], #[Group], #[Examples],
 * #[Widget], #[Setting], #[MixIn]), which DeclaresByProperties reads into
 * the same verbs: a second spelling of the blueprint, never a second
 * contract, so no blueprint method and no interface was added
 * (docs/decisions.md#a-class-may-declare-its-keys-as-properties): 27
 * attributes, 112 arguments. #[Output] extends #[Input], so its five
 * arguments are counted with it.
 * Grown on 2026-11-05 by one blueprint method, `mixInVariants()`, the
 * structure a sibling key chooses mixed in flat, as config schema stores
 * a type chosen by a key inside its own value, and its attribute,
 * #[MixInVariants], with its three arguments
 * (docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key): 18
 * methods, 28 attributes, 115 arguments.
 * Unchanged on 2026-11-06 by a rename, #[Variants] to #[AttachVariants],
 * and by mixIn() and mixInVariants() moving from the add-only interface to
 * BlueprintInterface, which this test counts together.
 * Cut on 2026-11-06 by two arguments, #[Each(to:, bundles:)]: a reference
 * per entry is the entry's definition with the reference constraint,
 * #[Constraint(..., item: TRUE)] beside #[Each]
 * (docs/decisions.md#a-reference-is-a-constraint-on-a-value): 113
 * arguments.
 * Cut on 2026-11-06 by the blueprint method `mixInVariants()` and its
 * attribute, #[MixInVariants], with its three arguments: a tagged union
 * has one blueprint form, attachVariants() at a key, and storing the
 * chosen keys beside the deciding key is a setting on that key,
 * Variants::STORED_AS, which the engine's layout reads: 17 methods, 27
 * attributes, 110 arguments.
 * Grown on 2026-11-07 by one interface outside `src/Resource/`,
 * TransactionalStorageInterface in `src/Pipeline/`, with its one method,
 * rolledBack(): a storage declares that a database transaction undoes
 * its writes whole, and only such a storage may be staged; a class list
 * of what to refuse could not hold, since any storage may save config or
 * write State in its own code
 * (docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection).
 * The four counts above are unchanged; the storage interfaces of
 * `src/Pipeline/` are pinned beside them, by name and method, from this
 * date.
 *
 * Grown on 2026-11-09 by one attribute and its four arguments,
 * #[Flow(id, label, keep, redirect)], a flow written as a class, and five
 * interfaces outside `src/Resource/`, in `src/Flow/`: FlowInterface,
 * StepsInterface, StepInterface, StagedInterface and
 * ChecksBeforeApplyInterface, which a flow class implements, declares
 * its steps into and reads the stage through
 * (docs/decisions.md#a-flow-is-a-class-and-the-map-is-its-short-form):
 * 28 attributes, 114 arguments. The interfaces of `src/Flow/` are pinned
 * by name and method from this date.
 *
 * Grown on 2026-11-09 by the runner, `src/Operation/`: the interface
 * OperationRunnerInterface and its class OperationRunner, gate() and
 * run(); the value it answers, OperationRun; Refusal and its
 * RefusalCategory; and Door, the value a caller names itself with
 * (docs/decisions.md#one-runner-runs-an-operation). The four counts above
 * are unchanged; the classes of `src/Operation/` are pinned by name,
 * public method, public property and public constant (an enum by its
 * cases) from this date. A method marked `@internal` is the runner's and
 * is not pinned. The same day, RefusalDetail, the refusal as it was,
 * which Refusal::actual() keeps for the server, and
 * OperationRun::conformed().
 *
 * Grown on 2026-11-10 by three methods of the runner, for the HTTP door
 * moved onto it (docs/decisions.md#one-runner-runs-an-operation):
 * OperationRunnerInterface::submit() and its class's, which submits
 * values to a prepared run, so a door that reads the prepared storage
 * before it writes builds once; OperationRun::hasStorage(), a prepare of
 * a resource that names no storage of its own; and OperationRun::account(),
 * the account submit() runs as. The four counts above are unchanged.
 *
 * @see docs/decisions.md#the-surface-was-counted-and-cut
 */
#[Group('surface')]
class PublicSurfaceTest extends UnitTestCase {

  /**
   * The directory of the authoring API.
   */
  protected const RESOURCE = __DIR__ . '/../../../src/Resource';

  /**
   * The namespace of the authoring API.
   */
  protected const NAMESPACE = 'Drupal\\surface\\Resource\\';

  /**
   * What the surface holds, by kind.
   */
  protected const PINNED = [
    'attributes' => 28,
    'attribute arguments' => 114,
    'blueprint methods' => 17,
    'interfaces' => 22,
  ];

  /**
   * The interfaces a storage implements in `src/Pipeline/`, with their methods.
   */
  protected const PIPELINE_STORAGE = [
    'CreatingStorageInterface' => ['creates'],
    'DeletingStorageInterface' => ['deletes'],
    'SurfaceStorageInterface' => ['commit', 'load', 'prepare'],
    'TransactionalStorageInterface' => ['rolledBack'],
  ];

  /**
   * The interfaces of a flow class in `src/Flow/`, with their methods.
   */
  protected const FLOW = [
    'ChecksBeforeApplyInterface' => ['beforeApply'],
    'FlowInterface' => ['defineSteps'],
    'StagedInterface' => ['value'],
    'StepInterface' => ['description', 'group', 'keys', 'label', 'when'],
    'StepsInterface' => ['step'],
  ];

  /**
   * The classes of the runner in `src/Operation/`, with their public members.
   *
   * Public methods but those marked `@internal`, public properties (`$`)
   * and public constants (`const`); an enum by its cases.
   */
  protected const OPERATION = [
    'Door' => ['$id', '__construct', '__toString', 'from', 'has'],
    'OperationRun' => [
      'access', 'account', 'conformed', 'context', 'definition', 'door',
      'hasBuilt', 'hasRun', 'hasStorage', 'isRefused', 'operation',
      'outputs', 'refusal', 'result', 'stage', 'storage', 'surface',
    ],
    'OperationRunner' => ['__construct', 'const NOT_ALLOWED', 'gate', 'run', 'submit'],
    'OperationRunnerInterface' => ['gate', 'run', 'submit'],
    'Refusal' => [
      '$access', '$cacheability', '$category', '$code', '$reason', 'actual',
      'const WITHHELD', 'violation',
    ],
    'RefusalCategory' => ['case Access', 'case NamesNothing', 'case Stage'],
    'RefusalDetail' => [
      '$access', '$cacheability', '$category', '$code', '$reason',
      '__construct', 'violation',
    ],
  ];

  /**
   * Tests the authoring API is the size this test says.
   */
  public function testTheSurfaceIsPinned(): void {
    $attributes = [];
    $arguments = [];
    foreach (glob(self::RESOURCE . '/Attribute/*.php') ?: [] as $file) {
      $class = self::NAMESPACE . 'Attribute\\' . basename($file, '.php');
      $attributes[] = $class;
      foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $arguments[] = basename($file, '.php') . '::' . $parameter->getName();
      }
    }
    $methods = [];
    foreach ([BlueprintInterface::class, BlueprintAdditionsInterface::class] as $interface) {
      foreach ((new \ReflectionClass($interface))->getMethods() as $method) {
        $methods[$method->getName()] = $method->getName();
      }
    }
    $interfaces = [];
    foreach (glob(self::RESOURCE . '/*.php') ?: [] as $file) {
      $name = self::NAMESPACE . basename($file, '.php');
      if (interface_exists($name)) {
        $interfaces[] = basename($file, '.php');
      }
    }
    $counted = [
      'attributes' => $attributes,
      'attribute arguments' => $arguments,
      'blueprint methods' => array_values($methods),
      'interfaces' => $interfaces,
    ];
    foreach (self::PINNED as $kind => $pinned) {
      $this->assertCount($pinned, $counted[$kind], sprintf(
        'The authoring API holds %d %s, and this test pins %d: %s. Say why it changed, and pin the new count here and in docs/decisions.md#the-surface-was-counted-and-cut.',
        count($counted[$kind]),
        $kind,
        $pinned,
        implode(', ', $counted[$kind]),
      ));
    }
  }

  /**
   * Tests the storage interfaces of the pipeline are the ones pinned.
   *
   * Each is a promise a storage class makes, and each method its own:
   * one added or removed fails here until this test says why.
   */
  public function testThePipelineStorageInterfacesArePinned(): void {
    $found = [];
    foreach (glob(__DIR__ . '/../../../src/Pipeline/*StorageInterface.php') ?: [] as $file) {
      $name = basename($file, '.php');
      $reflection = new \ReflectionClass('Drupal\\surface\\Pipeline\\' . $name);
      $methods = [];
      foreach ($reflection->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() === $reflection->getName()) {
          $methods[] = $method->getName();
        }
      }
      sort($methods);
      $found[$name] = $methods;
    }
    ksort($found);
    $this->assertSame(self::PIPELINE_STORAGE, $found, 'Say why a storage interface of the pipeline changed, and pin it here and in docs/decisions.md#the-surface-was-counted-and-cut.');
  }

  /**
   * Tests the interfaces of a flow class are the ones pinned.
   *
   * Each is what a flow class implements, declares its steps into or
   * reads the stage through: one added or removed, or one of their
   * methods, fails here until this test says why.
   */
  public function testTheFlowInterfacesArePinned(): void {
    $found = [];
    foreach (glob(__DIR__ . '/../../../src/Flow/*Interface.php') ?: [] as $file) {
      $name = basename($file, '.php');
      $methods = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), (new \ReflectionClass('Drupal\\surface\\Flow\\' . $name))->getMethods());
      sort($methods);
      $found[$name] = $methods;
    }
    ksort($found);
    $this->assertSame(self::FLOW, $found, 'Say why an interface of a flow class changed, and pin it here and in docs/decisions.md#the-surface-was-counted-and-cut.');
  }

  /**
   * Tests the classes of the runner are the ones pinned.
   *
   * What a door or a module calls to run an operation, and what it reads
   * back: one class added or removed, or one public method, property,
   * constant or case, fails here until this test says why.
   */
  public function testTheRunnerIsPinned(): void {
    $found = [];
    foreach (glob(__DIR__ . '/../../../src/Operation/*.php') ?: [] as $file) {
      $name = basename($file, '.php');
      $class = 'Drupal\\surface\\Operation\\' . $name;
      if (enum_exists($class)) {
        $members = array_map(static fn (\ReflectionEnumUnitCase $case): string => 'case ' . $case->getName(), (new \ReflectionEnum($class))->getCases());
      }
      else {
        $reflection = new \ReflectionClass($class);
        $members = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
          if ($method->getDeclaringClass()->getName() === $reflection->getName() && !str_contains((string) $method->getDocComment(), '@internal')) {
            $members[] = $method->getName();
          }
        }
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
          if ($property->getDeclaringClass()->getName() === $reflection->getName()) {
            $members[] = '$' . $property->getName();
          }
        }
        foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
          if ($constant->getDeclaringClass()->getName() === $reflection->getName()) {
            $members[] = 'const ' . $constant->getName();
          }
        }
      }
      sort($members);
      $found[$name] = $members;
    }
    ksort($found);
    $this->assertSame(self::OPERATION, $found, 'Say why a class of the runner changed, and pin it here and in docs/decisions.md#the-surface-was-counted-and-cut.');
  }

}
