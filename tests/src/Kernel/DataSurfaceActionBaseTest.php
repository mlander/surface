<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface_test\Plugin\Action\DataSurfaceTestAction;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests progressive adoption on an action, the thinnest group A host.
 *
 * An action owns no configuration keys of its own and supplies neither a
 * build nor a submit for the surface to compose with, so the base class
 * is the two traits and nothing else. What that buys is asserted here:
 * a plugin holding one attribute answers for its defaults, validates
 * what it is constructed with, generates its whole form, stores what the
 * form collected, and runs on the stored values.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceActionBaseTest extends DataSurfaceKernelTestBase {

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
   * Creates the test action.
   *
   * @param array $configuration
   *   Configuration to create it with.
   *
   * @return \Drupal\data_surface_test\Plugin\Action\DataSurfaceTestAction
   *   The action instance.
   */
  protected function createAction(array $configuration = []): DataSurfaceTestAction {
    $action = $this->container->get('plugin.manager.action')
      ->createInstance('data_surface_test_action', $configuration);
    $this->assertInstanceOf(DataSurfaceTestAction::class, $action);
    return $action;
  }

  /**
   * Tests that the surface, and nothing else, declares the defaults.
   */
  public function testDefaultsComeFromTheSurface(): void {
    $action = $this->createAction();

    $this->assertSame([
      'message' => 'Done',
      'level' => 'status',
    ], $action->defaultConfiguration());
    $this->assertSame([
      'message' => 'Done',
      'level' => 'status',
    ], $action->getConfiguration());
  }

  /**
   * Tests that the values an action is constructed with are validated.
   *
   * The host's constructor calls setConfiguration(), so an action built
   * from stored configuration is held to the surface at the moment it
   * comes into existence rather than at the moment it runs.
   */
  public function testConstructionValidatesConfiguration(): void {
    $action = $this->createAction(['message' => 'Saved', 'level' => 'warning']);
    $this->assertSame('Saved', $action->getConfiguration()['message']);
    $this->assertSame('warning', $action->getConfiguration()['level']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/message/');
    $this->createAction(['message' => str_repeat('x', 50)]);
  }

  /**
   * Tests that an undeclared choice is refused as well as an oversized value.
   */
  public function testInvalidChoiceThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/level/');
    $this->createAction(['level' => 'shouting']);
  }

  /**
   * Tests that the whole form triple comes from the trait.
   *
   * @see \Drupal\Tests\data_surface\Kernel\SurfaceFormTest::testConstraintsMapToElementProperties
   */
  public function testFormTripleComesFromTheSurface(): void {
    $action = $this->createAction(['message' => 'Saved']);
    $form_state = new FormState();

    $form = $action->buildConfigurationForm([], $form_state);
    // Both declared keys reach the form carrying what is stored. What
    // element a constraint maps to is pinned once, in SurfaceFormTest.
    $this->assertArrayHasKey('message', $form);
    $this->assertSame('Saved', $form['message']['#default_value']);
    // Option labels are translatable markup, so they are compared as the
    // text they render to.
    $this->assertSame(
      ['status' => 'Status', 'warning' => 'Warning'],
      array_map('strval', $form['level']['#options']),
    );

    $form_state->setValues([
      'message' => str_repeat('x', 50),
      'level' => 'status',
    ]);
    $action->validateConfigurationForm($form, $form_state);
    $this->assertArrayHasKey('message', $form_state->getErrors());
  }

  /**
   * Tests that submit stores the accepted values through the pipeline.
   */
  public function testSubmitStoresAcceptedValues(): void {
    $action = $this->createAction();
    $form_state = new FormState();
    $form = $action->buildConfigurationForm([], $form_state);

    $form_state->setValues([
      'message' => 'The article was published',
      'level' => 'warning',
    ]);
    $action->submitConfigurationForm($form, $form_state);

    $this->assertSame([
      'message' => 'The article was published',
      'level' => 'warning',
    ], $action->getConfiguration());
  }

  /**
   * Tests that execute() runs on what the configuration holds.
   */
  public function testExecuteUsesStoredConfiguration(): void {
    $this->createAction(['message' => 'Saved', 'level' => 'warning'])->execute();
    $this->createAction()->execute();

    $messenger = $this->container->get('messenger');
    $this->assertSame(['Saved'], array_map('strval', $messenger->messagesByType('warning')));
    $this->assertSame(['Done'], array_map('strval', $messenger->messagesByType('status')));
  }

  /**
   * Tests that the host's own remaining protocol is untouched.
   */
  public function testHostProtocolSurvives(): void {
    $action = $this->createAction();

    $this->assertSame([], $action->calculateDependencies());
    $this->assertTrue($action->access(NULL));
  }

}
