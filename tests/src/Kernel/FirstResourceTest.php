<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\data_surface\Unit\FirstResourceDocsTest;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests "Your first resource" and "Your second resource" work as written.
 *
 * FirstResourceDocsTest holds the pages' code to data_surface_first; this
 * holds what they say happens to what does: both forms render with the
 * elements the pages describe, the permission gates them, and every
 * `drush tool:run` line they quote answers as quoted, in order, on one
 * fresh site.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class FirstResourceTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_first',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_first']);
    require_once dirname(__DIR__) . '/Unit/FirstResourceDocsTest.php';
  }

  /**
   * Builds the operation form a route serves.
   *
   * @param string $route_name
   *   The route.
   *
   * @return array
   *   The form's data surface container.
   */
  protected function form(string $route_name): array {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    return $this->container->get('form_builder')->buildForm(DataSurfaceOperationForm::class, $form_state)[DataSurfaceOperationForm::SURFACE_KEY];
  }

  /**
   * Tests both forms render as the pages describe, behind the permission.
   */
  public function testTheFormsAreThePagesForms(): void {
    $this->setUpCurrentUser(permissions: ['administer data_surface_first']);
    $newsletter = $this->form('data_surface_first.newsletter');
    $this->assertSame('textfield', $newsletter['sender_name']['#type']);
    $this->assertSame(64, $newsletter['sender_name']['#attributes']['maxlength']);
    // Drawn required, and checked by the resource alone: Form API's own
    // check is off, its "field is required" never the message.
    $this->assertTrue($newsletter['sender_name'][DataSurfaceFormBuilderInterface::REQUIRED_KEY]);
    $this->assertFalse($newsletter['sender_name']['#required']);
    $this->assertSame('email', $newsletter['sender_email']['#type']);
    $this->assertSame('select', $newsletter['frequency']['#type']);
    $this->assertSame(['weekly' => 'Weekly', 'monthly' => 'Monthly'], array_map('strval', $newsletter['frequency']['#options']));
    $this->assertSame('number', $newsletter['max_recipients']['#type']);
    $recipients = $newsletter['max_recipients'];
    $this->assertSame([1, 10000, 500], [$recipients['#min'], $recipients['#max'], $recipients['#default_value']]);

    $store = $this->form('data_surface_first.store');
    $this->assertSame(['CA-BC', 'CA-ON', 'CA-QC'], array_keys($store['region']['#options']), 'The stored country\'s regions.');

    $access = $this->container->get('access_manager');
    $this->assertTrue($access->checkNamedRoute('data_surface_first.newsletter'));
    $this->setUpCurrentUser();
    $this->assertFalse($access->checkNamedRoute('data_surface_first.newsletter'), 'Without the permission.');
    $this->assertFalse($access->checkNamedRoute('data_surface_first.store'));
  }

  /**
   * Tests every Drush call the pages quote answers as they say.
   */
  public function testTheDrushCallsAnswerAsQuoted(): void {
    $this->setUpCurrentUser(admin: TRUE);
    $manager = $this->container->get('plugin.manager.tool');
    $calls = 0;
    foreach (FirstResourceDocsTest::PAGES as $page) {
      $text = FirstResourceDocsTest::page($page);
      preg_match_all('/^\s*drush tool:info (\S+)$/m', $text, $infos);
      foreach ($infos[1] as $id) {
        $this->assertTrue($manager->hasDefinition($id), sprintf('%s shows tool:info for %s, which is no tool.', $page, $id));
      }
      preg_match_all("/^\\s*drush tool:run (\\S+) --uid=1(?: --input='(.*?)')?( --input=dry_run=true)?$/m", $text, $runs, PREG_SET_ORDER);
      foreach ($runs as $run) {
        $tool = $manager->createInstance($run[1]);
        if (($run[2] ?? '') !== '') {
          $tool->setInputValue(OperationInputs::VALUES, json_decode($run[2], TRUE)['values']);
        }
        if (!empty($run[3])) {
          $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
        }
        $tool->execute();
        $result = $tool->getResult();
        $answer = ($result->isSuccess() ? 'Success: ' : 'Failed: ') . PlainTextOutput::renderFromHtml((string) $result->getMessage());
        $this->assertStringContainsString($answer, $text, sprintf('%s quotes another answer for %s.', $page, $run[0]));
        $values = $result->getContextValues()[OperationInputs::VALUES] ?? NULL;
        if ($values !== NULL && str_contains($text, '  values: ')) {
          $this->assertStringContainsString('  values: ' . json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $text, sprintf('%s quotes other values for %s.', $page, $run[0]));
        }
        $calls++;
      }
    }
    $this->assertSame(4, $calls, 'Two calls on each page.');
  }

}
