<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface_resource_test\Resource\Digest\DigestResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a variant that declares no keys.
 *
 * "This choice has no extra settings" is a variant with nothing in it: a
 * daily digest has no send day. The first fresh-eyes run found that one
 * crashed the form with an HTTP 500, because no widget served a map with
 * no properties. It now renders as nothing, its value is an empty map,
 * and the contracts, the tool and the pipeline take it as such.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class EmptyVariantTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_resource_test',
  ];

  /**
   * The digest's config object.
   */
  protected const CONFIG = 'surface_resource_test.digest';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the digest as its operation asks for it.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: \Drupal\surface\Resource\OperationContext}
   *   The built resource and its context.
   */
  protected function digest(): array {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(DigestResource::class, 'configure');
    return [$resources->build(DigestResource::class, $context), $context];
  }

  /**
   * Serves the digest's route and builds or submits its form.
   *
   * @param array|null $values
   *   Raw values to submit, or NULL to build the form only.
   *
   * @return array{0: array, 1: \Drupal\Core\Form\FormState}
   *   The form and its state.
   */
  protected function form(?array $values = NULL): array {
    $route_name = 'surface_resource_test.digest';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    if ($values === NULL) {
      $form = $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $form_state);
      return [$form, $form_state];
    }
    $form_state->setValues([SurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $form_state);
    return [$form_state->getCompleteForm(), $form_state];
  }

  /**
   * Tests the form draws an empty variant as nothing, and saves it.
   */
  public function testTheFormDrawsNothingForAnEmptyVariant(): void {
    [$form] = $this->form();
    $this->assertArrayHasKey('send_day', $form[SurfaceOperationForm::SURFACE_KEY]['schedule'], 'Weekly, as stored.');

    $this->config(self::CONFIG)->setData(['frequency' => 'daily', 'schedule' => []])->save();
    [$form] = $this->form();
    $schedule = $form[SurfaceOperationForm::SURFACE_KEY]['schedule'];
    $this->assertSame('container', $schedule['#type']);
    $this->assertSame([], array_filter(array_keys($schedule), static fn ($key): bool => !str_starts_with((string) $key, '#')), 'Nothing to fill in.');

    $this->config(self::CONFIG)->setData(['frequency' => 'weekly', 'schedule' => ['send_day' => 'friday']])->save();
    [, $form_state] = $this->form(['frequency' => 'monthly']);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(['frequency' => 'monthly', 'schedule' => []], $this->config(self::CONFIG)->getRawData(), 'The weekly send day goes with the weekly variant.');
  }

  /**
   * Tests both contracts state an empty variant as an empty object.
   */
  public function testTheContractsStateAnEmptyObject(): void {
    $this->config(self::CONFIG)->setData(['frequency' => 'daily', 'schedule' => []])->save();
    [$built] = $this->digest();
    $emitter = $this->container->get('surface.contract_emitter');

    $answers = $emitter->emit($built, ['frequency' => 'daily', 'schedule' => []], 'resource_test.digest', 'configure')->document['schema'];
    // A union of its variants' defs, each an object: the union says the
    // type. Decision: see docs/decisions.md#contract-2.
    $this->assertArrayNotHasKey('type', $answers['properties']['schedule']);
    $this->assertContains(['$ref' => '#/$defs/resource_test.digest.daily'], $answers['properties']['schedule']['anyOf']);
    $this->assertContains('object', (array) $answers['$defs']['resource_test.digest.daily']['type']);
    $this->assertSame('daily', $answers['properties']['schedule']['x-surface']['chosen']);
    $this->assertArrayNotHasKey('properties', $answers['properties']['schedule'], 'Nothing to answer.');

    $full = json_encode($emitter->emitFull($built, 'resource_test.digest', 'configure')->document['schema']);
    $this->assertStringContainsString('resource_test.digest.daily', $full);
    $this->assertStringContainsString('resource_test.digest.weekly', $full);
  }

  /**
   * Tests the tool and the pipeline accept an empty variant.
   */
  public function testTheToolAcceptsAnEmptyVariant(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:resource_test.digest:configure');
    $tool->setInputValue('values', ['frequency' => 'daily', 'schedule' => []]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->assertSame(['frequency' => 'daily', 'schedule' => []], array_diff_key($this->config(self::CONFIG)->getRawData(), ['_core' => TRUE]));

    $tool->setInputValue('values', ['frequency' => 'daily', 'schedule' => ['send_day' => 'monday']]);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess(), 'A daily digest has no send day.');
  }

}
