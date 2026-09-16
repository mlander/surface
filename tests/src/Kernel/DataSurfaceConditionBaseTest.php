<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface_test\Plugin\Condition\DataSurfaceTestCondition;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests progressive adoption on a condition, the second group A host.
 *
 * The condition host names the plugin triple exactly as the interface
 * does, so the base class takes the triple straight from the trait and
 * has only the host's own two keys to absorb: negate, which core
 * renders, stores and reads, and the plugin ID core prepends to the
 * stored configuration. Everything else asserted here — defaults,
 * validation at the configuration boundary, a generated form, a refined
 * constraint, and storage through the pipeline — is the same code the
 * block host already runs, which is the claim being tested.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceConditionBaseTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_test',
  ];

  /**
   * Creates the test condition.
   *
   * @param array $configuration
   *   Configuration to create it with.
   *
   * @return \Drupal\data_surface_test\Plugin\Condition\DataSurfaceTestCondition
   *   The condition instance.
   */
  protected function createCondition(array $configuration = []): DataSurfaceTestCondition {
    $condition = $this->container->get('plugin.manager.condition')
      ->createInstance('data_surface_test_condition', $configuration);
    $this->assertInstanceOf(DataSurfaceTestCondition::class, $condition);
    return $condition;
  }

  /**
   * Tests that the defaults are the surface's plus the host's negate.
   */
  public function testDefaultsIncludeNegate(): void {
    $condition = $this->createCondition();

    $this->assertSame([
      'mode' => 'at_least',
      'threshold' => 10,
      'reading' => 0,
      'negate' => FALSE,
    ], $condition->defaultConfiguration());
  }

  /**
   * Tests that the stored shape keeps core's leading plugin ID.
   */
  public function testGetConfigurationKeepsIdFirst(): void {
    $configuration = $this->createCondition()->getConfiguration();

    $this->assertSame('id', array_key_first($configuration));
    $this->assertSame('data_surface_test_condition', $configuration['id']);
    $this->assertSame(10, $configuration['threshold']);
    $this->assertFalse($configuration['negate']);
  }

  /**
   * Tests that the surface's keys are validated and negate survives.
   */
  public function testSetConfigurationValidatesAndKeepsNegate(): void {
    $condition = $this->createCondition();
    $condition->setConfiguration(['threshold' => 40, 'negate' => TRUE]);

    $configuration = $condition->getConfiguration();
    $this->assertSame(40, $configuration['threshold']);
    $this->assertTrue($configuration['negate']);
    $this->assertTrue($condition->isNegated());
    // A declared key the caller left out falls back to the surface's
    // default rather than to whatever was stored before.
    $this->assertSame('at_least', $configuration['mode']);
  }

  /**
   * Tests that configuration is validated at the boundary, not trusted.
   */
  public function testInvalidConfigurationThrows(): void {
    $condition = $this->createCondition();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/threshold/');
    $condition->setConfiguration(['threshold' => 500]);
  }

  /**
   * Tests that a refined constraint is enforced at the boundary too.
   */
  public function testRefinedConstraintIsEnforced(): void {
    // Zero is inside the advertised range, and the at least mode takes
    // it, but the at most mode narrows the threshold to one at the
    // lowest.
    $this->createCondition()->setConfiguration(['mode' => 'at_least', 'threshold' => 0]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/threshold/');
    $this->createCondition()->setConfiguration(['mode' => 'at_most', 'threshold' => 0]);
  }

  /**
   * Tests that the form carries the surface and the host's own element.
   */
  public function testConditionFormBuildsSurfaceAndNegate(): void {
    $condition = $this->createCondition();
    $condition->setConfiguration(['mode' => 'at_most', 'threshold' => 40]);

    $form = $condition->buildConfigurationForm([], new FormState());

    // The surface's elements, with the stored values. Which element a
    // constraint maps to is pinned once, in SurfaceFormTest.
    $this->assertArrayHasKey('mode', $form);
    $this->assertSame('at_most', $form['mode']['#default_value']);
    // Option labels are translatable markup, so they are compared as the
    // text they render to.
    $this->assertSame(
      ['at_least' => 'At least', 'at_most' => 'At most'],
      array_map('strval', $form['mode']['#options']),
    );
    $this->assertSame(40, $form['threshold']['#default_value']);
    $this->assertArrayHasKey('reading', $form);
    // Threshold refines against mode, so mode carries the rebuild, and
    // the stored mode already narrowed the threshold's lower bound.
    $this->assertArrayHasKey('#ajax', $form['mode']);
    $this->assertSame(1, $form['threshold']['#min']);
    $this->assertSame(100, $form['threshold']['#max']);

    // The condition host's own element survived the merge.
    $this->assertArrayHasKey('negate', $form);
    $this->assertFalse($form['negate']['#default_value']);
  }

  /**
   * Tests that validation flags the element the violation belongs to.
   */
  public function testConditionValidateFlagsTheElement(): void {
    $condition = $this->createCondition();
    $form_state = new FormState();
    $form = $condition->buildConfigurationForm([], $form_state);

    $form_state->setValues([
      'mode' => 'at_least',
      'threshold' => '500',
      'reading' => '5',
      'negate' => 0,
    ]);
    $condition->validateConfigurationForm($form, $form_state);

    $this->assertArrayHasKey('threshold', $form_state->getErrors());
  }

  /**
   * Tests that submit stores the surface's values and the host's negate.
   */
  public function testConditionSubmitStoresAcceptedValues(): void {
    $condition = $this->createCondition();
    $form_state = new FormState();
    $form = $condition->buildConfigurationForm([], $form_state);

    $form_state->setValues([
      'mode' => 'at_most',
      'threshold' => '40',
      'reading' => '12',
      'negate' => TRUE,
    ]);
    $condition->submitConfigurationForm($form, $form_state);

    $configuration = $condition->getConfiguration();
    // Submitted strings arrived as the definitions' native types.
    $this->assertSame('at_most', $configuration['mode']);
    $this->assertSame(40, $configuration['threshold']);
    $this->assertSame(12, $configuration['reading']);
    // The host's own key came through core's own submit, as before.
    $this->assertTrue($condition->isNegated());
    $this->assertSame('data_surface_test_condition', $configuration['id']);
  }

  /**
   * Tests that the stored values decide the result, and negate flips it.
   */
  public function testEvaluateHonoursNegate(): void {
    $condition = $this->createCondition(['reading' => 20, 'threshold' => 10]);
    $this->assertTrue($condition->evaluate());
    $this->assertTrue($condition->execute());

    $negated = $this->createCondition([
      'reading' => 20,
      'threshold' => 10,
      'negate' => TRUE,
    ]);
    $this->assertTrue($negated->evaluate());
    $this->assertFalse($negated->execute());

    $at_most = $this->createCondition([
      'mode' => 'at_most',
      'reading' => 20,
      'threshold' => 10,
    ]);
    $this->assertFalse($at_most->evaluate());
  }

  /**
   * Tests that the summary reads its words from the surface's options.
   */
  public function testSummaryUsesOptionLabels(): void {
    $condition = $this->createCondition(['mode' => 'at_most', 'threshold' => 40]);
    $this->assertSame('At most 40', (string) $condition->summary());

    $condition = $this->createCondition(['mode' => 'at_least', 'threshold' => 5]);
    $this->assertSame('At least 5', (string) $condition->summary());
  }

}
