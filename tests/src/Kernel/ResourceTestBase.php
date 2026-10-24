<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Contract\ContractBaselines;
use Drupal\data_surface\Contract\ContractChange;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceCatalog;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\data_surface\Traits\NeverStricterTrait;
use Drupal\Tests\data_surface\Traits\SchemaRoundTripTrait;
use Drupal\Tests\data_surface\Traits\ServedControllerTrait;
use Drupal\Tests\data_surface\Traits\StaticAccessAgreementTrait;
use Drupal\Tests\data_surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The invariants of the pattern, held for an adopter's own resources.
 *
 * What the module's own suite holds for its fixtures, one base class for
 * any module's kernel test: extend it, list the modules to install in
 * `$modules` (this class installs the main module, the tool bridge and
 * the HTTP door), name the resources in `$resources`, and optionally give
 * rows of values in `$values` and a baselines directory in `$baselines`.
 * For each resource, each test below runs as one data set:
 *
 * - every operation builds, for the account and for no account;
 * - never stricter: the full schema and the schema for these answers
 *   accept exactly what the pipeline accepts, post-coercion, for the
 *   declared defaults, each key sent null, each key sent a value of
 *   another type, and the rows in `$values`;
 * - the round trip: the full schema read back builds a structure whose
 *   full schema is byte-equal, a document of a known kind that cannot
 *   reported as skipped by its kind, any other difference a failure;
 * - the static refiners and the static access answers agree with what a
 *   fresh build answers, over sampled values and accounts;
 * - every door agrees: the same documents through the pipeline, the tool
 *   and the HTTP API, dry, give one accepted document and one refusal
 *   list, its paths as JSON Pointers;
 * - the contract baseline: today's full schema keeps the promise the
 *   committed baseline made, a breaking change failing unless versioned
 *   or allowed (DATA_SURFACE_BASELINES_WRITE=1 pins today's).
 *
 * An operation that needs a subject is built with none for the build
 * check and left out of the rest, which need values to read. A test
 * class extending this one carries #[Group] and
 * #[RunTestsInSeparateProcesses] itself, as every concrete kernel test
 * does.
 *
 * @see docs/testing-a-resource.md
 * @see docs/decisions.md#an-adopters-tests-hold-the-invariants
 */
abstract class ResourceTestBase extends DataSurfaceKernelTestBase {

  use NeverStricterTrait;
  use SchemaRoundTripTrait;
  use ServedControllerTrait;
  use StaticAccessAgreementTrait;
  use StaticRefinerAgreementTrait;
  use UserCreationTrait;

  /**
   * The environment variable that pins today's full schemas as baselines.
   */
  public const BASELINES_WRITE = 'DATA_SURFACE_BASELINES_WRITE';

  /**
   * What each door is run with, beside the defaults and the rows.
   */
  protected const OTHER_TYPE = [
    'number' => 'not a number',
    'boolean' => 'not a boolean',
    'string' => ['not', 'a', 'string'],
    'list' => 'not a list',
    'map' => 'not a map',
  ];

  /**
   * The modules this class needs, merged before the adopter's own.
   *
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_react',
  ];

  /**
   * The resources and structures held, by id.
   *
   * @var string[]
   */
  protected static array $resources = [];

  /**
   * Rows of values, by resource id, then by label.
   *
   * Each row is `[$input, $accepts]`: what is sent, and whether the
   * pipeline accepts it (NULL to take its verdict). A refused row may add
   * whether the full schema, and then the schema for these answers,
   * accept its document anyway (TRUE where it says it is wider, FALSE
   * where it is exact); left out, the schema's own marks at each refused
   * key decide. Every row is sent to every operation of the resource
   * that needs nothing and takes values.
   *
   * @var array<string, array<string, array{0: array, 1: bool|null, 2?: bool|null, 3?: bool|null}>>
   */
  protected static array $values = [];

  /**
   * The directory the contract baselines are kept in, or empty for none.
   *
   * A path, `__DIR__ . '/../../fixtures/contracts'` from a test in
   * `tests/src/Kernel/`; empty skips the baseline check, saying so.
   */
  protected static string $baselines = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $registry = $this->container->get('data_surface.resource_registry');
    $modules = [];
    foreach (static::$resources as $resource) {
      if ($registry->hasDefinition($resource)) {
        $modules[$registry->getDefinition($resource)->module] = TRUE;
      }
    }
    $this->installConfig(array_keys($modules));
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * One data set per resource.
   *
   * @return iterable<string, array{0: string}>
   *   The resource ids, each by itself.
   */
  public static function resourceProvider(): iterable {
    foreach (static::$resources as $resource) {
      yield $resource => [$resource];
    }
  }

  /**
   * Tests every operation builds, for the account and for no account.
   */
  #[DataProvider('resourceProvider')]
  public function testEveryOperationBuilds(string $resource): void {
    $definition = $this->definition($resource);
    $resources = $this->container->get('data_surface.resources');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $operations = $this->container->get('data_surface.resource_registry')->getOperations($definition->name());
    $contexts = $operations === [] ? [ContractBaselines::ALONE => new OperationContext(ContractBaselines::ALONE)] : [];
    foreach ($operations as $operation) {
      $contexts[$operation->id] = $operation->needsNothing() ? $resources->operation($definition->name(), $operation->id) : new OperationContext($operation->id);
    }
    foreach ($contexts as $id => $context) {
      $declared = $resources->buildDeclared($definition->name(), $context);
      $static = $resources->buildStatic($definition->name(), $context);
      $this->assertSame($declared->getDefinitions()->names(), $static->getDefinitions()->names(), $resource . ':' . $id . ': an administrator sees every key it declares.');
      $document = $emitter->emitFull($static, $definition->id, $operations === [] ? NULL : $id)->document;
      $this->assertSame($definition->id, $document['resource'], $resource . ':' . $id . ': its full schema is emitted.');
    }
  }

  /**
   * Tests the advertised schemas accept exactly what the pipeline accepts.
   */
  #[DataProvider('resourceProvider')]
  public function testNeverStricterThanThePipeline(string $resource): void {
    foreach ($this->asked($resource) as $id => [$context, $operation]) {
      $resources = $this->container->get('data_surface.resources');
      $name = $this->definition($resource)->name();
      $surface = $resources->build($name, $context);
      $declared = $resources->buildStatic($name, $context);
      $rows = $operation?->kind() === OperationDefinition::DELETE ? [] : $this->rows($resource, $surface);
      $this->assertTable($id, $surface, $this->stored($name, $context, $surface), $rows, $declared);
    }
  }

  /**
   * Tests the full schema reads back to itself, or differs by a known kind.
   */
  #[DataProvider('resourceProvider')]
  public function testFullSchemaReadsBackToItself(string $resource): void {
    $definition = $this->definition($resource);
    $resources = $this->container->get('data_surface.resources');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $skipped = [];
    foreach ($this->asked($resource) as $id => [$context, $operation]) {
      $schema = $emitter->emitFull($resources->buildStatic($definition->name(), $context), $definition->id, $operation?->id, $operation?->label, operation: $operation?->facts() ?? [])->document['schema'];
      $read = $this->readBack($schema, $definition->id, $definition->module, $context->id, $operation?->id, $operation?->label, $operation?->facts() ?? []);
      $this->assertNotSame('error', $read['verdict'], $id . ': it reads back and builds: ' . $read['error']);
      if ($read['verdict'] === 'same') {
        $this->addToAssertionCount(1);
        continue;
      }
      $kind = $read['verdict'] === 'live' ? 'live' : static::kindOf($schema, $read['again'], $definition);
      $this->assertNotNull($kind, sprintf("%s: its full schema read back is not itself, for no known reason.\nEmitted:\n%s\nRead back:\n%s", $id, static::encoded($schema), static::encoded($read['again'])));
      $skipped[] = sprintf('%s does not round-trip, by kind: %s (%s)', $id, $kind, static::roundTripKinds()[$kind]);
    }
    if ($skipped !== []) {
      $this->markTestSkipped(implode("\n", $skipped));
    }
  }

  /**
   * Tests each static refiner is what a fresh refinement answers.
   */
  #[DataProvider('resourceProvider')]
  public function testStaticRefinersAgree(string $resource): void {
    $resources = $this->container->get('data_surface.resources');
    $name = $this->definition($resource)->name();
    $compared = 0;
    foreach ($this->asked($resource) as $id => [$context]) {
      $compared += $this->assertStaticRefinersAgree($resources->buildStatic($name, $context), NULL, $id);
      $surface = $resources->build($name, $context);
      $stored = $this->stored($name, $context, $surface);
      foreach ($this->rows($resource, $surface) as $row) {
        try {
          $accepted = $this->pipeline()->accept($surface, $row[0], $stored);
        }
        catch (\Exception) {
          continue;
        }
        $compared += $this->assertStaticRefinersAgree($surface, $accepted, $id);
      }
    }
    $this->assertGreaterThanOrEqual(0, $compared, 'Each conditional compared.');
  }

  /**
   * Tests each static access answer holds for every account and stored value.
   */
  #[DataProvider('resourceProvider')]
  public function testStaticAccessAgrees(string $resource): void {
    $definition = $this->definition($resource);
    $administrator = $this->container->get('current_user')->getAccount();
    $resources = $this->container->get('data_surface.resources');
    foreach ($this->asked($resource) as $id => [$context, $operation]) {
      if ($operation === NULL) {
        // A structure no operation asks for is built by its host, which
        // answers access for it.
        $this->addToAssertionCount(1);
        continue;
      }
      $accounts = ['an administrator' => $administrator];
      $permission = $operation->staticPermission();
      if ($permission !== NULL && $permission !== '') {
        $accounts['an account with ' . $permission] = $this->createUser([$permission]);
      }
      $accounts['an account with no permission'] = $this->createUser([]);
      $situations = ['as installed' => static function (): void {}];
      $surface = $resources->build($definition->name(), $context);
      foreach ($this->rows($resource, $surface, FALSE) as $label => $row) {
        $situations['with ' . $label . ' stored'] = function () use ($definition, $context, $row): void {
          $this->write($definition->name(), $context, $row[0]);
        };
      }
      $compared = $this->assertStaticAccessAgrees($definition->name(), $operation->id, $accounts, $situations);
      $this->container->get('current_user')->setAccount($administrator);
      $this->assertGreaterThan(0, $compared, $id . ': each key compared.');
    }
  }

  /**
   * Tests the pipeline, the tool and the HTTP API answer one document alike.
   */
  #[DataProvider('resourceProvider')]
  public function testEveryDoorAgrees(string $resource): void {
    $definition = $this->definition($resource);
    $resources = $this->container->get('data_surface.resources');
    $tools = $this->container->get('plugin.manager.tool');
    $doors = 0;
    foreach ($this->asked($resource) as $id => [$context, $operation]) {
      if ($operation === NULL || !ResourceCatalog::standalone($definition, $operation, $this->container->get('data_surface.resource_plugins')->usedBy($definition->name())) || $operation->kind() === OperationDefinition::DELETE) {
        continue;
      }
      $tool = 'data_surface:' . $definition->id . ':' . $operation->id;
      $this->assertTrue($tools->hasDefinition($tool), $id . ': the tool ' . $tool . ' is derived.');
      $surface = $resources->build($definition->name(), $context);
      foreach ($this->rows($resource, $surface) as $label => $row) {
        $at = $id . ', ' . $label;
        $pipeline = $this->pipelineDoor($definition, $context, $row[0]);
        $tooled = $this->toolDoor($tool, $row[0]);
        $http = $this->httpDoor($definition->id, $operation->id, $row[0]);
        $this->assertSame($pipeline, $http, $at . ': the HTTP API answers as the pipeline does.');
        if ($tooled === NULL) {
          // The Tool API refused the value at its own input, before any
          // run: no list to compare, only the verdict.
          $this->assertFalse($pipeline['ok'], $at . ': the tool refused at its input what the pipeline accepts.');
          continue;
        }
        $before = $tooled['before'];
        unset($tooled['before']);
        if ($before) {
          // Refused by the Tool API at its own input, before the run: it
          // holds the shape alone (types, the keys a create requires), and
          // the pipeline stops at the first value of another type, so the
          // two may name other keys. Both refuse, and the Tool API names
          // keys by pointer.
          // Decision: see
          // docs/decisions.md#an-adopters-tests-hold-the-invariants.
          $this->assertFalse($pipeline['ok'], $at . ': the Tool API refused at its input what the pipeline accepts.');
          $this->assertNotSame([], $tooled['violations'], $at . ': the Tool API names what it refused.');
          foreach ($tooled['violations'] as $violation) {
            $this->assertStringStartsWith('/', $violation, $at . ': the Tool API names a key by pointer.');
          }
          continue;
        }
        $this->assertSame($pipeline, $tooled, $at . ': the tool answers as the pipeline does.');
        foreach ($pipeline['violations'] as $violation) {
          $this->assertTrue($violation === '' || $violation[0] === '/' || $violation[0] === ' ', $at . ': "' . $violation . '" is a JSON Pointer.');
        }
        $doors++;
      }
    }
    $this->assertGreaterThanOrEqual(0, $doors);
  }

  /**
   * Tests today's full schemas keep the promise of the committed baseline.
   */
  #[DataProvider('resourceProvider')]
  public function testContractBaseline(string $resource): void {
    $definition = $this->definition($resource);
    if (static::$baselines === '') {
      $this->markTestSkipped('No baselines directory: name one in $baselines to hold ' . $resource . ' to its contract.');
    }
    $baselines = $this->container->get('data_surface.contract_baselines');
    $current = array_intersect_key($baselines->emit([$definition->module]), [$definition->id => TRUE]);
    $this->assertArrayHasKey($definition->id, $current, $resource . ' is emitted: it is no derived one, and something of it needs no subject.');
    $directory = static::$baselines;
    $committed = is_dir($directory) ? array_intersect_key($baselines->read($directory), $current) : [];
    if (getenv(static::BASELINES_WRITE) === '1') {
      $baselines->write($directory, $current[$definition->id] + ['allowed' => $committed[$definition->id]['allowed'] ?? []]);
      $this->markTestSkipped(sprintf('The baseline of %s was written to %s.', $resource, $directory));
    }
    $this->assertDirectoryExists($directory, 'The baselines directory is there; ' . static::BASELINES_WRITE . '=1 makes it.');
    $failing = array_filter($baselines->compare($committed, $current), static fn (ContractChange $change): bool => $change->fails());
    $this->assertSame([], array_values(array_map(static fn (ContractChange $change): string => sprintf('%s %s: %s (%s)', $change->surface, $change->path, $change->change, $change->status), $failing)), sprintf(
      'A breaking change raises its owner\'s version, or its baseline allows it with a reason; a declaration with no baseline is pinned with %s=1, and an accepted change with drush data_surface:contract-diff --write.',
      static::BASELINES_WRITE,
    ));
  }

  /**
   * Gets a resource's definition.
   *
   * @param string $resource
   *   Its id.
   *
   * @return \Drupal\data_surface\ResourceBuild\ResourceDefinition
   *   What discovery found.
   */
  protected function definition(string $resource): ResourceDefinition {
    return $this->container->get('data_surface.resource_registry')->getDefinition($resource);
  }

  /**
   * Lists the contexts a resource is held in: each operation needing nothing.
   *
   * @param string $resource
   *   Its id.
   *
   * @return array<string, array{0: \Drupal\data_surface\Resource\OperationContext, 1: \Drupal\data_surface\ResourceBuild\OperationDefinition|null}>
   *   By `<id>:<operation>`, the context and the operation; a declaration
   *   with none is held alone, as a host builds it, its operation NULL.
   */
  protected function asked(string $resource): array {
    $definition = $this->definition($resource);
    $resources = $this->container->get('data_surface.resources');
    $operations = $this->container->get('data_surface.resource_registry')->getOperations($definition->name());
    if ($operations === []) {
      return [
        $definition->id . ':' . ContractBaselines::ALONE => [new OperationContext(ContractBaselines::ALONE), NULL],
      ];
    }
    $asked = [];
    foreach ($operations as $operation) {
      if ($operation->needsNothing()) {
        $asked[$definition->id . ':' . $operation->id] = [
          $resources->operation($definition->name(), $operation->id),
          $operation,
        ];
      }
    }
    return $asked;
  }

  /**
   * Writes the rows a resource is held to.
   *
   * @param string $resource
   *   Its id.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   It, built in the operation.
   * @param bool $generated
   *   Whether to add the generated rows to the adopter's: the defaults,
   *   each key sent null, each key sent a value of another type.
   *
   * @return array<string, array{0: array, 1: bool|null, 2?: bool|null, 3?: bool|null}>
   *   The rows, by label.
   */
  protected function rows(string $resource, DataSurfaceInterface $surface, bool $generated = TRUE): array {
    $rows = [];
    $defaults = $surface->getDefaultValues();
    if ($generated) {
      $rows['the declared defaults'] = [$defaults, NULL, NULL, NULL];
      foreach ($surface->getDefinitions()->entries() as $name => $entry) {
        $name = (string) $name;
        $rows[$name . ' sent null'] = [[$name => NULL] + $defaults, NULL, NULL, NULL];
        $other = static::otherType($entry->definition, $entry->attachment !== NULL || $entry->variants !== NULL);
        $rows[$name . ' sent a value of another type'] = [[$name => $other] + $defaults, NULL, NULL, NULL];
      }
    }
    foreach (static::$values[$resource] ?? [] as $label => $row) {
      $rows[$label] = $row + [1 => NULL, 2 => NULL, 3 => NULL];
    }
    return $rows;
  }

  /**
   * Gives a value of another type than a key's.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   * @param bool $child
   *   Whether a child or variants sit at the key.
   *
   * @return mixed
   *   A value no coercion makes the key's type.
   */
  protected static function otherType(DataDefinitionInterface $definition, bool $child): mixed {
    return match (TRUE) {
      $child || $definition instanceof ComplexDataDefinitionInterface => static::OTHER_TYPE['map'],
      $definition instanceof ListDataDefinitionInterface => static::OTHER_TYPE['list'],
      in_array($definition->getDataType(), ['integer', 'float', 'timestamp', 'decimal'], TRUE) => static::OTHER_TYPE['number'],
      $definition->getDataType() === 'boolean' => static::OTHER_TYPE['boolean'],
      default => static::OTHER_TYPE['string'],
    };
  }

  /**
   * Reads what a resource's storage holds, in its shape.
   *
   * @param string $name
   *   The resource's name.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   It, built there.
   *
   * @return array
   *   The stored values; none for a structure no storage holds.
   */
  protected function stored(string $name, OperationContext $context, DataSurfaceInterface $surface): array {
    if ($this->container->get('data_surface.resource_registry')->getDefinition($name)->storage === NULL) {
      return [];
    }
    $storage = $this->container->get('data_surface.resources')->storage($name, $context, $surface);
    return array_intersect_key($storage->load($surface), $surface->getDefinitions()->toArray());
  }

  /**
   * Writes values through the pipeline, as a situation a build then reads.
   *
   * @param string $name
   *   The resource's name.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context.
   * @param array $input
   *   The values.
   */
  protected function write(string $name, OperationContext $context, array $input): void {
    $resources = $this->container->get('data_surface.resources');
    if ($resources->buildDeclared($name, $context)->changesNothing() || $this->container->get('data_surface.resource_registry')->getDefinition($name)->storage === NULL) {
      return;
    }
    $surface = $resources->build($name, $context);
    $this->pipeline()->submit($surface, $input, $resources->storage($name, $context, $surface));
  }

  /**
   * Runs values through the pipeline, dry, as a door would.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The operation's context.
   * @param array $input
   *   The values.
   *
   * @return array{ok: bool, values: mixed, violations: list<string>}
   *   What every door is compared by.
   */
  protected function pipelineDoor(ResourceDefinition $definition, OperationContext $context, array $input): array {
    $resources = $this->container->get('data_surface.resources');
    $surface = $resources->build($definition->name(), $context);
    $result = $this->pipeline()->submit($surface, $input, $resources->storage($definition->name(), $context, $surface), TRUE, $resources->access($definition->name(), $context));
    return static::compared($result->document($this->pipeline()->outbound($surface, $result->held())));
  }

  /**
   * Runs values through the operation's tool, dry.
   *
   * @param string $tool
   *   The tool's id.
   * @param array $input
   *   The values.
   *
   * @return array{ok: bool, values: mixed, violations: list<string>, before: bool}|null
   *   What every door is compared by, and whether the Tool API refused
   *   the values at its own input, before the run; NULL when setting the
   *   input threw.
   */
  protected function toolDoor(string $tool, array $input): ?array {
    $instance = $this->container->get('plugin.manager.tool')->createInstance($tool);
    assert($instance instanceof ToolInterface);
    try {
      $instance->setInputValue(OperationInputs::VALUES, $input);
    }
    catch (\Exception) {
      return NULL;
    }
    // A read only operation writes nothing, and takes no dry run.
    if (array_key_exists(OperationInputs::DRY_RUN, $instance->getInputDefinitions())) {
      $instance->setInputValue(OperationInputs::DRY_RUN, TRUE);
    }
    $instance->execute();
    $result = $instance->getResult();
    $context = $result->getContextValues();
    // A run that failed outside the result shape, an error the Tool API
    // caught, answers nothing to compare: it fails here by its message.
    $this->assertArrayHasKey('ok', $context, 'The tool ' . $tool . ' answers in the result shape: ' . (string) $result->getMessage());
    // The Tool API's own violations are there only when it refused the
    // values before the tool ran.
    return static::compared($context) + ['before' => $result->getViolations()->count() > 0];
  }

  /**
   * Runs values through the HTTP API, dry, as the operation's write.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param array $input
   *   The values.
   *
   * @return array{ok: bool, values: mixed, violations: list<string>}
   *   What every door is compared by.
   */
  protected function httpDoor(string $resource, string $operation, array $input): array {
    $answer = $this->httpDryRun($resource, $operation, $input);
    if (isset($answer['status'])) {
      // A refusal is problem details, each error a pointer and a code.
      return ['ok' => FALSE, 'values' => NULL, 'violations' => static::pointed($answer['errors'] ?? [])];
    }
    return static::compared($answer);
  }

  /**
   * Keeps what doors are compared by: verdict, document, refusals.
   *
   * @param array $result
   *   A run's result, every key of ResultDocument::KEYS.
   *
   * @return array{ok: bool, values: mixed, violations: list<string>}
   *   Whether it was accepted; the accepted document, through JSON, when
   *   it was; and each refusal as its pointer and code, sorted.
   */
  protected static function compared(array $result): array {
    $ok = (bool) $result['ok'];
    return [
      'ok' => $ok,
      'values' => $ok ? json_decode((string) json_encode($result['values'], JSON_PRESERVE_ZERO_FRACTION), TRUE) : NULL,
      'violations' => static::pointed($result['violations'] ?? []),
    ];
  }

  /**
   * Writes refusals as their pointer and code, sorted.
   *
   * @param array $violations
   *   Each `{path, code, message}`.
   *
   * @return list<string>
   *   `<pointer> <code>` for each.
   */
  protected static function pointed(array $violations): array {
    $pointed = array_map(static fn (array $violation): string => $violation['path'] . ' ' . $violation['code'], $violations);
    sort($pointed);
    return $pointed;
  }

}
