<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface_examples\ExamplePanels;
use Drupal\data_surface_examples\Resource\ProgramResource;
use Drupal\data_surface_examples_workflow\Alter\ProgramWorkflowAlter;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\data_surface\Traits\StaticAccessAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests example 10 with the workflow module, whose alters answer access.
 *
 * Another module, with no change to the program: the fees are left out
 * for an account without "see event fees", which the account alone
 * decides, and the program is locked whole once the module's workflow
 * state is published, which only what is stored decides.
 *
 * @see docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesWorkflowTest extends DataSurfaceKernelTestBase {

  use StaticAccessAgreementTrait;
  use UserCreationTrait;

  /**
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
    'data_surface_examples',
    'data_surface_examples_workflow',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Emits the program's full schema for the current account.
   *
   * @return array
   *   The schema.
   */
  protected function full(): array {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(ProgramResource::class, 'configure');
    return $this->container->get('data_surface.contract_emitter')
      ->emitFull($resources->buildStatic(ProgramResource::class, $context), 'event.program', 'configure')
      ->document['schema'];
  }

  /**
   * Publishes the program in its workflow, or takes it back to a draft.
   *
   * @param bool $published
   *   Whether it is published.
   */
  protected function publish(bool $published = TRUE): void {
    $this->container->get('state')->set(ProgramWorkflowAlter::STATE, $published ? ProgramWorkflowAlter::PUBLISHED : 'draft');
  }

  /**
   * Lists the keys a tool lists in its values, for the current account.
   *
   * @return string[]
   *   The keys.
   */
  protected function listed(): array {
    $values = $this->container->get('plugin.manager.tool')->createInstance('data_surface:event.program:configure')->getInputDefinition(OperationInputs::VALUES);
    return array_keys($values->getPropertyDefinitions());
  }

  /**
   * Tests the fees are left out for an account that may not see them.
   */
  public function testTheFeesAreLeftOutForAnAccountThatMayNotSeeThem(): void {
    // Every permission: the fees' own access and the alter both wait for
    // what is stored.
    $fees = $this->full()['properties']['fees'];
    $this->assertTrue($fees[ContractEmitter::EXTENSION]['live']);
    $this->assertArrayNotHasKey('readOnly', $fees);
    $this->assertContains('fees', $this->listed());

    // Without "see event fees": left out, by the account alone, where the
    // fees' own access class alone locked them.
    $this->setUpCurrentUser(permissions: ['administer site configuration']);
    $this->assertSame(['headline', 'sessions', 'published'], array_keys($this->full()['properties']));
    $this->assertSame(['headline', 'sessions', 'published'], $this->listed());
    $read = $this->container->get('plugin.manager.tool')->createInstance('data_surface:event.program:configure:read');
    $read->execute();
    $this->assertSame(['headline', 'sessions', 'published'], array_keys($read->getOutputValue(OperationInputs::VALUES)));

    // With it, and without "set event fees": locked by the fees' own
    // static answer, and marked for the alter's runtime one.
    $this->setUpCurrentUser(permissions: ['administer site configuration', 'see event fees']);
    $fees = $this->full()['properties']['fees'];
    $this->assertTrue($fees['readOnly']);
    $this->assertTrue($fees[ContractEmitter::EXTENSION]['live']);
  }

  /**
   * Tests the program is locked whole once published in its workflow.
   */
  public function testPublishedInTheWorkflowLocksTheProgramWhole(): void {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(ProgramResource::class, 'configure');
    $this->assertNull($resources->build(ProgramResource::class, $context)->lockedWhole());

    $this->publish();
    $built = $resources->build(ProgramResource::class, $context);
    $this->assertSame('The program is published in its workflow, so it is fixed.', (string) $built->lockedWhole());
    $this->assertTrue($built->getDefinitions()->isHeld('headline'));
    $this->assertNull($resources->buildStatic(ProgramResource::class, $context)->lockedWhole(), 'Runtime: set aside for the account alone.');
    $full = $this->full();
    $this->assertArrayNotHasKey('readOnly', $full, 'The full schema states no lock for what only the call settles.');
    $this->assertArrayNotHasKey('readOnly', $full['properties']['headline']);
    foreach (['headline', 'sessions', 'published', 'fees'] as $key) {
      $this->assertTrue($full['properties'][$key][ContractEmitter::EXTENSION]['live'], $key . ': marked, since the whole program may be found locked.');
    }
    $these = $this->container->get('data_surface.contract_emitter')->emit($built, $built->getDefaultValues(), 'event.program', 'configure')->document['schema'];
    $this->assertTrue($these['readOnly'], 'The schema for these answers settles it.');

    // A call is refused by name, and checking, which changes nothing, is
    // left alone.
    $tools = $this->container->get('plugin.manager.tool');
    $tool = $tools->createInstance('data_surface:event.program:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['headline' => 'Four days of talks']);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('The program is published in its workflow, so it is fixed.', (string) $tool->getResult()->getMessage());
    $check = $tools->createInstance('data_surface:event.program:check');
    $check->setInputValue(OperationInputs::VALUES, ['headline' => 'Four days of talks', 'sessions' => 12]);
    $check->execute();
    $this->assertTrue($check->getResult()->isSuccess());
    $this->assertNull($resources->build(ProgramResource::class, $resources->operation(ProgramResource::class, 'check'))->lockedWhole());
  }

  /**
   * Tests the full schema's static marks hold, with the module's answers.
   */
  public function testTheStaticMarksAgreeWithTheBuild(): void {
    $accounts = [
      'every permission' => $this->createUser(['administer site configuration', 'set event fees', 'see event fees']),
      'seeing the fees' => $this->createUser(['administer site configuration', 'see event fees']),
      'not seeing them' => $this->createUser(['administer site configuration']),
    ];
    $situations = [
      'a draft' => fn () => $this->publish(FALSE),
      'published in the workflow' => fn () => $this->publish(),
      'published as stored' => function (): void {
        $this->publish(FALSE);
        $this->config('data_surface_examples.program')->set('published', TRUE)->save();
      },
    ];
    $this->assertGreaterThan(10, $this->assertStaticAccessAgrees(ProgramResource::class, 'configure', $accounts, $situations));
  }

  /**
   * Tests the panel says what the module answers, and makes its call.
   */
  public function testThePanelSaysWhatTheModuleAnswers(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('data_surface_examples.step10');
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'data_surface_examples.step10');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class);
    $panel = $form[DataSurfaceOperationForm::SURFACE_KEY][DataSurfaceOperationForm::PANEL_KEY][ExamplePanels::CONCEPT];
    $this->assertStringContainsString('data_surface_examples_workflow', (string) $panel['workflow']['#markup']);
    $this->assertStringContainsString('see event fees', (string) $panel['workflow']['#markup']);
    $accounts = json_decode(htmlspecialchars_decode((string) $panel['accounts']['content']['#value']), TRUE);
    $this->assertNull($accounts['without the permission']['property'], 'Left out for the account without either permission.');
    $this->assertTrue($accounts['you']['property'][ContractEmitter::EXTENSION]['live']);
    $this->assertArrayHasKey('event.program:published_in_workflow', $panel['calls']);
    $this->assertStringContainsString('The program is published in its workflow', (string) $panel['calls']['event.program:published_in_workflow']['answer']['#value']);
    $this->assertNull($this->container->get('state')->get(ProgramWorkflowAlter::STATE), 'The State the call was made in is put back.');
  }

}
