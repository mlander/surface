<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\Tests\data_surface\Kernel\Fixture\CountingPipeline;
use Drupal\Tests\data_surface\Kernel\Fixture\CountingSubmitForm;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the operation form prepares a write once per submit.
 *
 * Validation runs the whole pipeline dry, prepare included, so a refusal
 * storage raises is said on the elements; the submit commits what it
 * prepared rather than preparing it again, unless the values or what
 * storage holds moved in between, when it runs the whole submit.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class OnePreparePerSubmitTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_first',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_first']);
    $this->setUpCurrentUser(permissions: ['administer data_surface_first']);
    CountingSubmitForm::$pipeline = NULL;
    CountingSubmitForm::$between = NULL;
  }

  /**
   * Submits the newsletter's operation form, as one statement.
   *
   * @param array $values
   *   Raw values keyed by resource key.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submitTheForm(array $values): FormStateInterface {
    $route_name = 'data_surface_first.newsletter';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath(), 'POST');
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    $form_state->setValues([DataSurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(CountingSubmitForm::class, $form_state);
    return $form_state;
  }

  /**
   * Gets the pipeline the form asked.
   *
   * @return \Drupal\Tests\data_surface\Kernel\Fixture\CountingPipeline
   *   The counting pipeline.
   */
  protected function pipeline(): CountingPipeline {
    $this->assertNotNull(CountingSubmitForm::$pipeline);
    return CountingSubmitForm::$pipeline;
  }

  /**
   * Reads what the newsletter holds.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset();
    return $this->config('data_surface_first.newsletter')->getRawData();
  }

  /**
   * Tests the submit commits what validation prepared, once.
   */
  public function testTheSubmitCommitsWhatValidationPrepared(): void {
    $form_state = $this->submitTheForm([
      'sender_name' => 'Once',
      'sender_email' => 'once@example.com',
      'frequency' => 'monthly',
      'max_recipients' => '250',
    ]);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(['submit dry' => 1, 'submit' => 0, 'commitDryRun' => 1], $this->pipeline()->calls, 'One dry run, prepare and all, and its artifact committed.');
    $stored = $this->stored();
    $this->assertSame(
      ['Once', 'once@example.com', 'monthly', 250],
      [$stored['sender_name'], $stored['sender_email'], $stored['frequency'], $stored['max_recipients']],
    );
  }

  /**
   * Tests a refusal is judged once, and nothing is committed.
   */
  public function testRefusalIsJudgedOnce(): void {
    $form_state = $this->submitTheForm([
      'sender_name' => 'Refused',
      'sender_email' => 'not an address',
      'frequency' => 'weekly',
      'max_recipients' => '250',
    ]);
    $this->assertNotSame([], $form_state->getErrors());
    $this->assertSame(['submit dry' => 1, 'submit' => 0, 'commitDryRun' => 0], $this->pipeline()->calls);
    $this->assertNotSame('Refused', $this->stored()['sender_name'] ?? NULL);
  }

  /**
   * Tests what storage holds moving after validation runs the whole submit.
   *
   * The dry run judged values against what storage held then; the write
   * is judged again against what it holds now, as before.
   */
  public function testStorageMovingInBetweenRunsTheSubmitAgain(): void {
    CountingSubmitForm::$between = function (): void {
      $this->config('data_surface_first.newsletter')->set('sender_name', 'Moved')->save();
    };
    $form_state = $this->submitTheForm([
      'sender_name' => 'After',
      'sender_email' => 'after@example.com',
      'frequency' => 'weekly',
      'max_recipients' => '300',
    ]);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(['submit dry' => 1, 'submit' => 1, 'commitDryRun' => 0], $this->pipeline()->calls, 'The fingerprint differs, so the whole submit runs.');
    $this->assertSame('After', $this->stored()['sender_name']);
  }

}
