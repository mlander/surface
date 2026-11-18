<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\surface_values_test\HandBuiltSurfaces;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests a kept watched-value answer is the answer the validator gives.
 *
 * What is walked: every declaration of the example modules WALKED
 * names, the test surfaces of surface_values_test, and
 * HandBuiltSurfaces::surface(), built for an administrator. For each:
 * the value sets ExampleValueSetsTrait::valueSets() gives (each watched
 * key's options, TRUE and FALSE, its bounds), and each watched key it
 * names holding a value its schema may refuse (OTHER). Each set is
 * refined and validated, and every watched-value check on the way is
 * asked twice (WatchedValueParityCheck): as the module asks it, keeping
 * what it may for the request, and of a check that keeps nothing. The
 * answers, or what was thrown, are the same.
 *
 * @see docs/decisions.md#a-watched-value-is-asked-once-per-request
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class WatchedValueAnswersParityTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
  use UserCreationTrait;

  /**
   * The example modules whose declarations are walked.
   */
  protected const WALKED = [
    'surface_examples',
    'surface_examples_address',
    'surface_examples_block',
    'surface_examples_classic',
    'surface_examples_compliance',
    'surface_examples_component_block',
    'surface_examples_entity',
    'surface_examples_extras',
    'surface_examples_field',
    'surface_examples_first',
    'surface_examples_keys',
    'surface_examples_node_type',
    'surface_examples_node_type_tool',
    'surface_examples_performance',
    'surface_examples_plugins',
    'surface_examples_react',
    'surface_examples_references',
    'surface_examples_retitle',
    'surface_examples_workflow',
  ];

  /**
   * The values each watched key is also given, which a schema may refuse.
   */
  protected const OTHER = [
    'not offered' => 'zz-not-offered',
    'a number' => 12345,
    'a long text' => 'x',
    'a list' => ['zz'],
    'a fraction' => 1.5,
    'TRUE' => TRUE,
  ];

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
    'surface_examples_workflow',
    'surface_examples_first',
    'block',
    'path_alias',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_keys',
    'text',
    'filter',
    'node',
    'surface_examples_references',
    'surface_examples_retitle',
    'address',
    'surface_examples_address',
    'surface_examples_classic',
    'surface_examples_entity',
    'surface_examples_plugins',
    'surface_examples_extras',
    'surface_examples_field',
    'surface_examples_node_type',
    'surface_examples_node_type_tool',
    'surface_examples_performance',
    'surface_examples_react',
    'surface_resource_test',
    'surface_values_test',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('surface_test.watched_value_parity', WatchedValueParityCheck::class)
      ->setDecoratedService('surface.watched_value_check')
      ->addArgument(new Reference('surface_test.watched_value_parity.inner'))
      ->addArgument(new Reference('surface.watched_value_answers'))
      ->addArgument(new Reference('surface.pipeline'))
      ->addArgument(new Reference('surface.contract_emitter'))
      ->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
    $this->installConfig(['field', 'surface_examples_keys', 'surface_examples_entity', 'surface_resource_test']);
  }

  /**
   * Tests every watched-value check answers as with nothing kept.
   */
  public function testKeptAnswersAreTheValidatorsAnswers(): void {
    $pipeline = $this->pipeline();
    WatchedValueParityCheck::reset();
    $surfaces = [];
    foreach ($this->examples(FALSE, [...self::WALKED, 'surface_values_test']) as [$id, $operation, $surface]) {
      $surfaces[$id . ($operation === NULL ? '' : '--' . $operation)] = $surface;
    }
    $surfaces[HandBuiltSurfaces::ID] = HandBuiltSurfaces::surface();
    $sets = 0;
    foreach ($surfaces as $surface) {
      $defaults = $surface->getDefaultValues();
      $valueSets = $this->valueSets($surface);
      foreach (array_keys($valueSets) as $name) {
        if (!str_contains((string) $name, '=')) {
          continue;
        }
        $path = explode('/', explode('=', (string) $name, 2)[0]);
        foreach (self::OTHER as $other => $value) {
          $values = $defaults;
          NestedArray::setValue($values, $path, $other === 'a long text' ? str_repeat($value, 300) : $value, TRUE);
          $valueSets[implode('/', $path) . ' ' . $other] = $values;
        }
      }
      foreach ($valueSets as $values) {
        $sets++;
        try {
          $surface->refine($values);
          $pipeline->validate($surface, $values);
        }
        catch (\Throwable) {
          // What a check threw is compared where it was thrown.
        }
      }
    }
    $counts = WatchedValueParityCheck::$counts;
    $this->assertSame([], WatchedValueParityCheck::$differences);
    // The walk reached watched keys, kept answers were used, values were
    // refused, from kept answers too, and TRUE at a string key was refused:
    // slice 35 removed the leniency that read it as its text (the check
    // refuses with type, as validate() does).
    $this->assertGreaterThan(40, count($surfaces));
    $this->assertGreaterThan(500, $sets);
    $this->assertGreaterThan(1000, $counts['checks'] ?? 0);
    $this->assertGreaterThan($counts['asked of the validator'] ?? 0, $counts['answered from kept'] ?? 0);
    $this->assertGreaterThan(50, $counts['refused, answered from kept'] ?? 0);
    $this->assertGreaterThan(0, $counts['TRUE at a string key, refused'] ?? 0);
    $this->assertSame(0, $counts['TRUE at a string key, admitted'] ?? 0);
  }

}
