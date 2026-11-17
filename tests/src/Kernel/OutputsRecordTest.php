<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\surface\Contract\ContractBaselines;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Pipeline\NotEmitted;
use Drupal\surface\Pipeline\ViolationSet;
use Drupal\surface\Resource\OperationContext;
use Drupal\Tests\surface\HandCorpus\OutputCases;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Records what the output check answers, for each declaration with outputs.
 *
 * Every resource and structure of the example and test modules installed
 * here, built in each of its operations (alone where it has none) for no
 * account, and the hand surfaces of OutputCases, each held to every case
 * OutputCases lists, by two checks side by side: the pipeline's
 * (SurfacePipelineInterface::conformOutput(), `today`) and the check
 * through the dialect's validator (OutputsConformance, `next`). Each answer
 * is what was thrown, or each violation's key, path, code, params and
 * message. The record is written to `surface-outputs-record.json` in the
 * system's temporary directory, so slice 34's flip is compared with it.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class OutputsRecordTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use OutputsConformanceTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::ENTITY_MODULES,
    'path_alias',
    'surface_http',
    'surface_examples',
    'surface_examples_plugins',
    'surface_examples_retitle',
    'surface_property_test',
    'surface_resource_test',
    'surface_stage_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // As installEntityFixture() does, but the retitle example's optional
    // config already holds the page type.
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'system']);
    FilterFormat::create(['format' => 'basic', 'name' => 'Basic', 'weight' => 0])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    if (NodeType::load('page') === NULL) {
      $this->createPage();
    }
    $this->createKitchen();
    $this->installConfig(['surface_examples', 'surface_examples_retitle']);
    // Named, so the documents that list users are the same on every run.
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    $admin = User::create(['uid' => 1, 'name' => 'admin', 'status' => 1]);
    $admin->save();
    $this->setCurrentUser($admin);
  }

  /**
   * Records both checks' answers and holds the record to what is decided.
   */
  public function testRecord(): void {
    $record = [];
    $unbuilt = [];
    foreach ($this->surfaces() as $label => $surface) {
      if (is_string($surface)) {
        $unbuilt[$label] = $surface;
        continue;
      }
      $outputs = $surface->getOutputDefinitions();
      if ($outputs->keys() === []) {
        continue;
      }
      $document = $this->outputsDocument()->of($surface);
      $row = [
        'validation' => $document === NULL ? NULL : Schemas::arrayed($document),
        'cases' => [],
      ];
      foreach (OutputCases::cases($outputs) as $case => $output) {
        $row['cases'][$case] = [
          'value' => self::plain($output),
          'today' => self::answer(fn (): ViolationSet => $this->pipeline()->conformOutput($surface, $output)),
          'next' => self::answer(fn (): ViolationSet => $this->outputsConformance()->conformOutput($surface, $output)),
        ];
      }
      $record[$label] = $row;
    }
    file_put_contents(sys_get_temp_dir() . '/surface-outputs-unbuilt.json', json_encode($unbuilt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    file_put_contents(sys_get_temp_dir() . '/surface-outputs-record.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

    $this->assertArrayHasKey('hand', $record);
    $this->assertArrayHasKey('surface.stage changes', $record);
    foreach ($record as $label => $row) {
      foreach ($row['cases'] as $case => $answers) {
        // The new check throws for no value.
        $this->assertIsArray($answers['next'], $label . ' ' . $case);
      }
      // A good value conforms to both.
      foreach (['good', 'only required'] as $case) {
        $this->assertSame([[], []], [$row['cases'][$case]['today'], $row['cases'][$case]['next']], $label . ' ' . $case);
      }
    }
  }

  /**
   * Builds every declaration with outputs, and the hand surfaces.
   *
   * @return \Generator<string, \Drupal\surface\SurfaceInterface|string>
   *   Each data surface, or why it could not be built, by a label: the
   *   declaration's id and the operation's.
   */
  protected function surfaces(): \Generator {
    yield 'hand' => OutputCases::handSurface();
    try {
      // A secret output, which can be declared until it is refused at
      // declaration.
      $secret = OutputCases::secretSurface();
    }
    catch (\Throwable $e) {
      $secret = get_class($e) . ': ' . $e->getMessage();
    }
    yield 'hand secret' => $secret;
    $registry = $this->container->get('surface.resource_registry');
    $resources = $this->container->get('surface.resources');
    // A subject for each operation that names one: the first node, a
    // stage, else a value no subject has.
    Node::create(['type' => 'page', 'title' => 'Page', 'field_description' => 'x'])->save();
    $stage = $this->open();
    foreach ($registry->getDefinitions() as $definition) {
      $name = $definition->name();
      try {
        $operations = $registry->getOperations($name);
      }
      catch (\Throwable $e) {
        // A declaration the test module ships broken, on purpose.
        yield $definition->id => get_class($e) . ': ' . $e->getMessage();
        continue;
      }
      foreach ($operations === [] ? [NULL] : $operations as $operation) {
        $label = $definition->id . ($operation === NULL ? '' : ' ' . $operation->id);
        $arguments = [];
        foreach ($operation === NULL ? [] : $operation->parameters as $parameter) {
          $arguments[$parameter->name] = match (TRUE) {
            $definition->id === 'surface.stage' => $stage,
            $parameter->type === 'int' => 1,
            default => '1',
          };
        }
        try {
          $context = $operation === NULL ? new OperationContext(ContractBaselines::ALONE) : $resources->operation($name, $operation->id, $arguments);
          $surface = $resources->buildDeclared($name, $context);
        }
        catch (\Throwable $e) {
          yield $label => get_class($e) . ': ' . $e->getMessage();
          continue;
        }
        yield $label => $surface;
      }
    }
  }

  /**
   * Runs one check, recording what it answers.
   *
   * @param \Closure $check
   *   The check.
   *
   * @return array|string
   *   Each violation's key, path, code, params and message; or what it
   *   threw, by class and message.
   */
  protected static function answer(\Closure $check): array|string {
    try {
      $violations = $check();
    }
    catch (\Throwable $e) {
      return get_class($e) . ': ' . $e->getMessage();
    }
    $answer = [];
    foreach ($violations as $violation) {
      $answer[] = [
        'key' => $violation->key,
        'path' => $violation->path,
        'code' => $violation->code,
        'params' => self::plain($violation->params),
        'message' => (string) $violation->message,
      ];
    }
    return $answer;
  }

  /**
   * Writes a value as JSON can hold it, an object by its class.
   *
   * @param mixed $value
   *   The value.
   *
   * @return mixed
   *   The value, each object a string naming it.
   */
  protected static function plain(mixed $value): mixed {
    if (NotEmitted::is($value)) {
      return '<NotEmitted>';
    }
    if (is_object($value)) {
      return '<' . get_debug_type($value) . '>';
    }
    if (is_float($value) && (is_nan($value) || is_infinite($value))) {
      return (string) $value;
    }
    return is_array($value) ? array_map(self::plain(...), $value) : $value;
  }

}
