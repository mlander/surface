<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormState;
use Drupal\surface\DefinitionMap;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a node-first build answers as a definition-first build does.
 *
 * With DefinitionMap::$nodeFirst on, every entry a built data surface is
 * sealed with holds the node read from its source's definition and
 * derives its definition on demand (Entry::ofSchema()). The mode is the
 * proof that the derived definition serves every reader that still asks
 * for one; this test pins it on a few example modules, so the lanes run
 * it in their default mode: each data surface is built in each mode, and
 * every answer is compared. The accept and the validation for every
 * value set (ExampleValueSetsTrait), the document for each value set with
 * and without the widget hints, the full schema, the form's envelope and
 * its build, and the tool definitions as the tool module serializes
 * them.
 *
 * The whole module is run in the mode by setting SURFACE_NODE_FIRST=1
 * for any kernel test (SurfaceKernelTestBase::setUp()).
 *
 * Decision: see docs/decisions.md#an-entry-holds-the-form-its-source-gave.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class NodeFirstModeTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
  use UserCreationTrait;

  /**
   * The modules whose data surfaces are compared.
   */
  protected const MODULES = [
    'surface_examples_first',
    'surface_examples_compliance',
    'surface_examples_references',
  ];

  /**
   * The data surfaces compared, the value sets run, and the tools listed.
   */
  protected const COUNTS = [7, 15, 55];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_first',
    'text',
    'filter',
    'node',
    'path_alias',
    'surface_examples_references',
    'surface_examples_retitle',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
  }

  /**
   * Tests every answer is the same in both modes.
   */
  public function testBothModesAnswerAlike(): void {
    $default = $this->answers(FALSE);
    $nodeFirst = $this->answers(TRUE);
    $this->assertSame(array_keys($default['answers']), array_keys($nodeFirst['answers']), 'The same answers are given.');
    $differ = [];
    foreach ($default['answers'] as $key => $answer) {
      if ($answer !== $nodeFirst['answers'][$key]) {
        $differ[] = $key;
      }
    }
    $this->assertSame([], array_slice($differ, 0, 10), 'Every answer is the same in both modes.');
    $this->assertSame(0, $default['nodeFirst'], 'The default mode makes no entry node-first.');
    $this->assertGreaterThan(0, $nodeFirst['nodeFirst'], 'The mode makes entries node-first.');
    $this->assertSame(0, $nodeFirst['kept'], 'Every definition the examples seal derives.');
    $this->assertSame(self::COUNTS, $default['counts'], 'The data surfaces compared, the value sets run, and the tools listed.');
  }

  /**
   * Gives every answer of the compared data surfaces, built in one mode.
   *
   * @param bool $nodeFirst
   *   Whether the entries are made node-first.
   *
   * @return array{answers: array<string, string>, counts: int[], nodeFirst: int, kept: int}
   *   Each answer as JSON, by what was asked; the counts; the entries made
   *   node-first; the definitions the mode kept definition-first.
   */
  protected function answers(bool $nodeFirst): array {
    // A fresh build in each mode: no build kept from the other.
    $resources = $this->container->get('surface.resources');
    (new \ReflectionProperty($resources, 'builds'))->setValue($resources, []);
    $this->container->get('cache.surface_build')->deleteAll();
    DefinitionMap::$nodeFirst = $nodeFirst;
    DefinitionMap::$madeNodeFirst = 0;
    DefinitionMap::$keptDefinitionFirst = 0;
    $pipeline = $this->pipeline();
    $emitter = $this->container->get('surface.contract_emitter');
    $builder = $this->formBuilder();
    $answers = [];
    $surfaces = 0;
    $sets = 0;
    foreach ($this->examples(FALSE, self::MODULES) as [$id, $operation, $surface]) {
      $surfaces++;
      $at = $id . ':' . ($operation ?? '-');
      $defaults = $surface->getDefaultValues();
      foreach ($this->valueSets($surface) as $name => $values) {
        $sets++;
        try {
          $accepted = $pipeline->accept($surface, $values, $defaults);
          $answers["$at $name accept"] = static::json($accepted);
          $violations = [];
          foreach ($pipeline->validate($surface, $accepted, $defaults) as $violation) {
            $violations[] = [
              $violation->fullPath(),
              (string) $violation->message,
              $violation->code,
              $violation->stale,
              static::plain($violation->stored),
              static::plain($violation->params),
            ];
          }
          $answers["$at $name validate"] = static::json($violations);
        }
        catch (\InvalidArgumentException | \UnexpectedValueException $e) {
          $answers["$at $name accept"] = get_class($e) . ': ' . $e->getMessage();
        }
        $answers["$at $name emit"] = static::json($emitter->emit($surface, $values, $id, $operation));
        $answers["$at $name hints"] = static::json($emitter->emit($surface, $values, $id, $operation, NULL, TRUE));
        $answers["$at $name envelope"] = static::json($builder->envelope($surface, $values)->document);
      }
      $answers["$at full"] = static::json($emitter->emitFull($surface, $id, $operation));
      // The wrapper's id is made unique per request: start each build alike.
      Html::resetSeenIds();
      $state = new FormState();
      $answers["$at form"] = static::json($builder->buildSurfaceForm($surface, $defaults, $state));
    }
    $tools = $this->container->get('plugin.manager.tool');
    $tools->clearCachedDefinitions();
    $serializer = $this->container->get('tool.definition_serializer');
    $listed = 0;
    foreach (array_keys($tools->getDefinitions()) as $tool) {
      if (str_starts_with((string) $tool, 'surface:')) {
        $listed++;
        $answers["tool $tool"] = static::json($serializer->normalizeInputSchema($tools->createInstance($tool)));
      }
    }
    $made = DefinitionMap::$madeNodeFirst;
    $kept = DefinitionMap::$keptDefinitionFirst;
    DefinitionMap::$nodeFirst = FALSE;
    return ['answers' => $answers, 'counts' => [$surfaces, $sets, $listed], 'nodeFirst' => $made, 'kept' => $kept];
  }

  /**
   * Writes an answer as JSON, every object as what it says.
   *
   * @param mixed $value
   *   The answer.
   *
   * @return string
   *   Its JSON: a text as its string, any other object as its class and
   *   its JSON form where it has one.
   */
  protected static function json(mixed $value): string {
    return (string) json_encode(static::plain($value), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Makes an answer plain data.
   *
   * @param mixed $value
   *   The answer.
   *
   * @return mixed
   *   The same, every object replaced by what it says.
   */
  protected static function plain(mixed $value): mixed {
    if (is_array($value)) {
      return array_map(static::plain(...), $value);
    }
    if (!is_object($value)) {
      return $value;
    }
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    if ($value instanceof \JsonSerializable) {
      return static::plain($value->jsonSerialize());
    }
    return $value::class;
  }

}
