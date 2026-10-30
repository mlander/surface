<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface_examples\ConceptCalls;
use Drupal\surface_examples\ExampleCalls;
use Drupal\surface_examples\Hook\SpotlightBlockHooks;
use Drupal\surface_examples_references\Sponsorship;
use Drupal\surface_examples_retitle\Retitle;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\surface\Traits\DoorAgreementTrait;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every door agrees, for every operation of every resource shipped.
 *
 * Each resource of this repository's modules, and of its test modules,
 * is held to DoorAgreementTrait: each operation that needs no subject,
 * and each that needs one where the site here has it (SUBJECTS), runs
 * the documents the declaration generates (the declared defaults, each
 * key sent null, each key sent a value of another type) and each call
 * the examples' README makes of it (ConceptCalls, ExampleCalls) through
 * the pipeline, the tool, the HTTP API and the operation's form, dry, and
 * every door gives one verdict, one accepted document, one refusal list
 * by pointer and code, and one set of notes. What a door cannot be
 * driven for is counted by kind and held to the kinds the trait names
 * (KNOWN), so a new way to skip fails here first.
 *
 * Three data sets, each a site of its own: the examples, with the block
 * placement, the references example and the retitle example; content
 * entities and fields; the test modules' fixtures, the gates' among them.
 * A fixture refused at build, which the broken ones are written to be, is
 * left out by the reason it gives.
 *
 * @see docs/decisions.md#nothing-an-operation-runs-reads-the-request
 * @see docs/decisions.md#one-result-at-every-door
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class EveryDoorAgreesTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use DoorAgreementTrait;
  use ServedControllerTrait;
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
    'path_alias',
    'serialization',
    'tool',
    'surface',
    'surface_tool',
    'surface_react',
  ];

  /**
   * Each data set's modules, and the modules whose resources it holds.
   */
  protected const SITES = [
    'the examples' => [
      'modules' => [
        'surface_examples',
        'surface_examples_compliance',
        'surface_examples_workflow',
        'surface_examples_keys',
        'surface_examples_references',
        'surface_examples_retitle',
        'surface_examples_component_block',
        'surface_examples_block',
        'surface_examples_first',
      ],
    ],
    'content entities and fields' => [
      'modules' => [
        'options',
        'datetime',
        'link',
        'telephone',
        'taxonomy',
        'address',
        'surface_examples_address',
        'surface_examples_plugins',
        'surface_examples_extras',
        'surface_examples_node_type',
        'surface_examples_entity',
      ],
      'holds' => ['surface_tool'],
    ],
    'the fixtures' => [
      'modules' => [
        'config_test',
        'surface_test',
        'surface_resource_test',
        'surface_tool_test',
        'surface_storage_test',
        'surface_schema_test',
        'surface_paths_test',
        'surface_gate_test',
      ],
    ],
  ];

  /**
   * The subject each operation that needs one is asked about, by name.
   */
  protected const SUBJECTS = [
    'event.venue:update' => ['id' => 'riverside'],
    'event.venue:delete' => ['id' => 'riverside'],
    'block.placement:update' => ['id' => 'surface_examples_spotlight'],
    'block.placement:delete' => ['id' => 'surface_examples_spotlight'],
    'node.type:update' => ['type' => 'page'],
    'field.instance:create' => ['entity_type' => 'node', 'bundle' => 'article'],
    'field.instance:reuse' => ['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'body'],
    'field.instance:update' => ['entity_type' => 'node', 'bundle' => 'page', 'field_name' => 'body'],
    'field.storage:update' => ['entity_type' => 'node', 'field_name' => 'body'],
    'entity.content:node.page:update' => ['id' => 1],
    'gate_test.note:update' => ['node' => 1],
    'gate_test.note:annotate' => ['node' => 1],
    'gate_test.relay:update' => ['node' => 1],
  ];

  /**
   * What a door may be skipped for, beside a fixture refused at build.
   */
  protected const KNOWN = [
    self::SKIPPED_TOOL_INPUT,
    self::SKIPPED_FORM_TYPE,
    self::SKIPPED_FORM_NOT_DRAWN,
    self::SKIPPED_FORM_SECRET,
    self::SKIPPED_FORM_CHECKBOX,
    self::SKIPPED_FORM_DELETE,
    self::SKIPPED_NOT_STANDALONE,
    self::SKIPPED_NO_STORAGE,
    self::SKIPPED_SUBJECT,
    self::SKIPPED_READ,
  ];

  /**
   * Skipped: an operation needing a subject the site here does not have.
   */
  public const SKIPPED_SUBJECT = 'every door: the operation needs a subject the site here does not have (one an earlier call writes, and the doors here write nothing)';

  /**
   * Skipped: a call to a read tool, which takes no document.
   */
  public const SKIPPED_READ = 'every door: a call to the read tool takes no document';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
  }

  /**
   * One data set per site.
   *
   * @return iterable<string, array{0: string}>
   *   The sites, by name.
   */
  public static function siteProvider(): iterable {
    foreach (array_keys(self::SITES) as $site) {
      yield $site => [$site];
    }
  }

  /**
   * Sets a site up: its modules, their configuration, its subjects.
   *
   * @param string $site
   *   The site.
   */
  protected function site(string $site): void {
    $modules = self::SITES[$site]['modules'];
    $this->enableModules($modules);
    if ($site === 'content entities and fields') {
      // The content types the entity resources are derived from, a page
      // to edit among them.
      $this->installEntityFixture();
    }
    else {
      $this->installEntitySchema('user');
      $this->installEntitySchema('node');
      $this->installSchema('node', ['node_access']);
      $this->installConfig(['system', 'filter', 'node']);
      // Two content types, each with a body.
      if (FieldStorageConfig::loadByName('node', 'body') === NULL) {
        FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary'])->save();
      }
      foreach (['article' => 'Article', 'page' => 'Basic page'] as $type => $name) {
        if (NodeType::load($type) === NULL) {
          NodeType::create(['type' => $type, 'name' => $name])->save();
        }
        if (FieldConfig::loadByName('node', $type, 'body') === NULL) {
          FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => $type, 'label' => 'Body'])->save();
        }
      }
    }
    $this->installConfig(['field']);
    foreach ($modules as $module) {
      $this->installConfig([$module]);
    }
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser(admin: TRUE);
    if ($site === 'content entities and fields') {
      Node::create(['type' => 'page', 'title' => 'A page', 'field_description' => 'Described.'])->save();
    }
    if ($site === 'the examples') {
      $this->container->get('class_resolver')->getInstanceFromDefinition(SpotlightBlockHooks::class)->place();
      // Example 16's three nodes, as its module writes them when installed.
      $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->writeNodes();
      // Example 17's three pages, likewise.
      $this->container->get('class_resolver')->getInstanceFromDefinition(Retitle::class)->writeNodes();
    }
    if ($site === 'the fixtures') {
      // The page the gate test module's notes are about.
      Node::create(['type' => 'page', 'title' => 'A page'])->save();
    }
  }

  /**
   * Tests every door answers every document alike, for every resource.
   */
  #[DataProvider('siteProvider')]
  public function testEveryDoorAgrees(string $site): void {
    $this->site($site);
    $registry = $this->container->get('surface.resource_registry');
    $resources = $this->container->get('surface.resources');
    $holds = [...self::SITES[$site]['modules'], ...(self::SITES[$site]['holds'] ?? [])];
    $compared = 0;
    $skipped = [];
    $refused = [];
    $failures = [];
    foreach ($registry->getDefinitions() as $definition) {
      if (!in_array($definition->module, $holds, TRUE)) {
        continue;
      }
      try {
        $operations = $registry->getOperations($definition->name());
      }
      catch (\LogicException $e) {
        $refused[$definition->id] = $e->getMessage();
        continue;
      }
      foreach ($operations as $operation) {
        $at = $definition->id . ':' . $operation->id;
        $parameters = $operation->needsNothing() ? [] : (self::SUBJECTS[$at] ?? NULL);
        $rows = [];
        if ($parameters !== NULL) {
          try {
            $surface = $resources->build($definition->name(), $resources->operation($definition->name(), $operation->id, $parameters));
          }
          catch (\LogicException | \InvalidArgumentException $e) {
            $refused[$at] = $e->getMessage();
            continue;
          }
          if ($operation->kind() === OperationDefinition::DELETE) {
            $rows['nothing'] = ['input' => []];
          }
          else {
            foreach (static::generated($surface) as $label => $input) {
              $rows[$label] = ['input' => $input, 'form' => !str_ends_with($label, 'sent a value of another type')];
            }
          }
        }
        else {
          $skipped[self::SKIPPED_SUBJECT] = ($skipped[self::SKIPPED_SUBJECT] ?? 0) + 1;
        }
        try {
          if ($rows !== []) {
            $answered = $this->assertDoorsAgree($definition, $operation, $parameters, $rows);
            $compared += $answered['compared'];
            foreach ($answered['skipped'] as $kind => $count) {
              $skipped[$kind] = ($skipped[$kind] ?? 0) + $count;
            }
          }
          foreach ($this->calls($definition, $operation, $skipped) as $label => $call) {
            [$call_parameters, $input, $state, $role] = $call;
            $this->made($state, $role, function () use ($definition, $operation, $call_parameters, $label, $input, &$compared, &$skipped): void {
              $answered = $this->assertDoorsAgree($definition, $operation, $call_parameters, ['the README\'s ' . $label => ['input' => $input]]);
              $compared += $answered['compared'];
              foreach ($answered['skipped'] as $kind => $count) {
                $skipped[$kind] = ($skipped[$kind] ?? 0) + $count;
              }
            });
          }
        }
        catch (AssertionFailedError $e) {
          $failures[] = $e->getMessage() . ($e instanceof ExpectationFailedException ? (string) $e->getComparisonFailure()?->getDiff() : '');
        }
      }
    }
    $this->assertSame([], $failures, implode("\n\n", $failures));
    $this->assertGreaterThan(0, $compared, $site . ': documents went through the doors.');
    $this->assertSame([], array_values(array_diff(array_keys($skipped), self::KNOWN)), $site . ': a door is skipped only by a kind the trait names.');
    // A fixture may be written to be refused when it is built; nothing
    // shipped is.
    if ($site !== 'the fixtures') {
      $this->assertSame([], $refused, $site . ': every resource shipped builds: ' . implode("\n", $refused));
    }
  }

  /**
   * Lists the README's calls of one operation, as rows of the door check.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, int> $skipped
   *   The skipped kinds, counted, a call the doors cannot make among them.
   *
   * @return array<string, array{0: array<string, scalar>, 1: array, 2: array<string, mixed>, 3: string|null}>
   *   By the call's name: its parameters, its values, the State it is
   *   made in and the role of the account it is made as.
   */
  protected function calls(ResourceDefinition $definition, OperationDefinition $operation, array &$skipped): array {
    $calls = [];
    $enabled = array_keys($this->container->get('module_handler')->getModuleList());
    foreach (ConceptCalls::of($definition->id, $enabled) as $name => $call) {
      $called = $call['operation'] ?? 'configure';
      if (str_ends_with($called, ':read') && $called === $operation->id . ':read') {
        $skipped[self::SKIPPED_READ] = ($skipped[self::SKIPPED_READ] ?? 0) + 1;
      }
      if ($called !== $operation->id) {
        continue;
      }
      $parameters = $call['parameters'] ?? [];
      if ($parameters !== []) {
        try {
          $this->container->get('surface.resources')->operation($definition->name(), $operation->id, $parameters);
        }
        catch (\InvalidArgumentException) {
          $skipped[self::SKIPPED_SUBJECT] = ($skipped[self::SKIPPED_SUBJECT] ?? 0) + 1;
          continue;
        }
      }
      $calls[$name] = [
        $parameters,
        ConceptCalls::values($definition->id, $name) ?? [],
        $call['state'] ?? [],
        $call['account'] ?? NULL,
      ];
    }
    if ($definition->id . ':' . $operation->id === 'registration.step3:configure') {
      foreach (ExampleCalls::CALLS as $name => $call) {
        $calls['example 5, ' . $name] = [[], $call['values'], [], NULL];
      }
    }
    return $calls;
  }

  /**
   * Makes a call in the State it names, as an account of the role it names.
   *
   * @param array<string, mixed> $state
   *   The State, set for the call and deleted after it.
   * @param string|null $role
   *   The role of the account it is made as, or NULL for the administrator.
   * @param callable $call
   *   The call.
   */
  protected function made(array $state, ?string $role, callable $call): void {
    $current = $this->container->get('current_user')->getAccount();
    foreach ($state as $key => $value) {
      $this->container->get('state')->set($key, $value);
    }
    if ($role !== NULL) {
      $this->setCurrentUser($this->createUser([], NULL, FALSE, ['roles' => [$role]]));
    }
    try {
      $call();
    }
    finally {
      foreach (array_keys($state) as $key) {
        $this->container->get('state')->delete($key);
      }
      $this->setCurrentUser($current);
    }
  }

}
