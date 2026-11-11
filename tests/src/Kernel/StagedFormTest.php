<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormState;
use Drupal\node\Entity\Node;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface_stage_test\Storage\ItineraryStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the operation form writing into the stage its query names.
 *
 * `?stage=<id>` on an operation form page: the pipeline runs over the
 * staged storage, nothing is written, and the page says so and stays,
 * the stage kept in its query. The route's access check and the form
 * agree on who may use the stage and where one applies.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StagedFormTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use OperationRouteTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::ENTITY_MODULES,
    'surface_http',
    'surface_stage_test',
    'surface_examples',
    'surface_route_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests a staged configure writes nothing, says so and stays.
   */
  public function testStagedConfigureWritesNothing(): void {
    $before = $this->config('surface_examples.registration_step1')->getRawData();
    $this->visit('/surface-examples/1');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertContains('url.query_args:stage', $form['#cache']['contexts']);
    $this->assertContains('url.query_args:step', $form['#cache']['contexts']);
    $this->assertNotSame(0, $form['#cache']['max-age'] ?? -1, 'With no stage, the page is cached as before.');
    $stage = $this->open('Registration');
    $this->visit('/surface-examples/1?stage=' . $stage);
    $access = $this->routeAccess();
    $this->assertTrue($access->isAllowed());
    $this->assertSame(0, CacheableMetadata::createFromObject($access)->getCacheMaxAge(), 'An answer under a stage is never cached.');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame(0, $form['#cache']['max-age']);
    $this->assertContains('url.query_args:stage', $form['#cache']['contexts']);
    $state = $this->post(['title' => 'Staged title', 'capacity' => '80', 'open' => '1']);
    $this->assertSame([], $state->getErrors());
    $this->assertNull($state->getRedirect(), 'The page stays, its stage in the query.');
    $this->assertSame(['Configure registration (step 1): the values were accepted and staged in <em class="placeholder">Registration</em>; nothing is written until it is applied.'], $this->statuses());
    $this->assertSame($before, $this->config('surface_examples.registration_step1')->getRawData(), 'Config is unchanged.');
    $writes = $this->stages()->read($stage)['writes'];
    $this->assertCount(1, $writes);
    $this->assertContains('title', $writes[0]['changed']);
    // The same values again move nothing in the stage.
    $this->post(['title' => 'Staged title', 'capacity' => '80', 'open' => '1']);
    $this->assertSame(['Configure registration (step 1): the values were accepted, and they are what is staged in <em class="placeholder">Registration</em>, so nothing was written.'], $this->statuses());
  }

  /**
   * Tests another account's stage is refused, and an unknown one not found.
   */
  public function testAnotherAccountsStageIsRefused(): void {
    $stage = $this->open();
    $this->setUpCurrentUser([], ['administer site configuration']);
    $this->visit('/surface-examples/1?stage=' . $stage);
    $access = $this->routeAccess();
    $this->assertTrue($access->isForbidden());
    $this->assertInstanceOf(AccessResultReasonInterface::class, $access);
    $this->assertSame('The stage is another account\'s.', $access->getReason());
    $this->assertSame(0, CacheableMetadata::createFromObject($access)->getCacheMaxAge());
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('The stage is another account\'s.');
    $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
  }

  /**
   * Tests a link to another page is judged without this page's stage.
   *
   * A link is asked about with no request: the current request's query
   * is read for a link to the current route alone.
   */
  public function testLinkElsewhereIgnoresThisPagesStage(): void {
    $this->visit('/surface-examples/1?stage=junk');
    $account = $this->container->get('current_user');
    $links = $this->container->get('access_manager');
    $this->assertTrue($links->checkNamedRoute('surface_examples.step2', [], $account), 'A link to another operation route is drawn.');
    $this->assertFalse($links->checkNamedRoute('surface_examples.step1', [], $account), 'A link to this page, under a stage that names nothing, is not.');
  }

  /**
   * Tests a stage that names nothing is a page not found.
   */
  public function testStageThatNamesNothingIsNotFound(): void {
    $this->visit('/surface-examples/1?stage=gone');
    $this->assertFalse($this->routeAccess()->isAllowed(), 'No link to a page not found is drawn.');
    $this->expectException(NotFoundHttpException::class);
    $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
  }

  /**
   * Tests a stage is refused where it does not apply, saying so.
   */
  public function testStageIsRefusedWhereItDoesNotApply(): void {
    $stage = $this->open();
    $this->visit('/surface-test/card/add?stage=' . $stage);
    $access = $this->routeAccess();
    $this->assertTrue($access->isForbidden());
    $message = 'A stage does not apply to the "create" operation of route_test.card (does_not_apply), so this page takes no "stage" in its query.';
    $this->assertInstanceOf(AccessResultReasonInterface::class, $access);
    $this->assertSame($message, $access->getReason());
    try {
      $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
      $this->fail('The form did not refuse.');
    }
    catch (AccessDeniedHttpException $e) {
      $this->assertSame($message, $e->getMessage());
    }
  }

  /**
   * Tests a staged create, then its update by its local id in the path.
   */
  public function testStagedCreateIsEditedByItsLocalId(): void {
    $stage = $this->open('New page');
    $this->visit('/surface-entity/node/page/add?stage=' . $stage);
    $state = $this->post(['title' => ['value' => 'Autumn meetup'], 'field_description' => ['value' => 'In the hall.']]);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(['Add Basic page: the values were accepted and staged in <em class="placeholder">New page</em>; nothing is created until it is applied, and a write in the stage names it by -1.'], $this->statuses());
    $this->assertSame([], Node::loadMultiple(), 'Nothing is created.');
    $route = $this->container->get('router.route_provider')->getRouteByName('surface_examples_entity.node_page_update');
    $this->serveBuilt('surface_examples_entity.node_page_update', $route, ['id' => '-1'], ['stage' => $stage]);
    $this->assertTrue($this->routeAccess()->isAllowed(), 'The local id names what the stage creates.');
    $form = $this->surfaceForm();
    $this->assertSame('Autumn meetup', $form['title']['value']['#default_value']);
    $this->post(['title' => ['value' => 'Winter meetup'], 'field_description' => ['value' => 'In the hall.']]);
    $writes = $this->stages()->read($stage)['writes'];
    $this->assertCount(1, $writes, 'The update merged into the create.');
    $this->assertSame('create', $writes[0]['kind']);
    $this->assertSame('Winter meetup', $writes[0]['values']['title']['value']);
    $this->assertSame([], Node::loadMultiple());
  }

  /**
   * Tests a staged delete says nothing is removed until the stage applies.
   */
  public function testStagedDeleteRemovesNothingNow(): void {
    $trip = ['id' => 'trip', 'region' => 'north', 'day' => 'mon'];
    $this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION)->set('trip', $trip);
    $this->visit('/surface-test/itinerary/trip/cancel');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame('Cancels a trip. <em class="placeholder">trip</em> is removed, and this cannot be undone.', (string) $form['confirm']['#markup']);
    $stage = $this->open('Cancel');
    $this->visit('/surface-test/itinerary/trip/cancel?stage=' . $stage);
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame('Cancels a trip. <em class="placeholder">trip</em> is removed when the stage is applied; nothing is removed now.', (string) $form['confirm']['#markup']);
    $this->assertSame(0, $form['#cache']['max-age']);
    $state = $this->post([]);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(['Cancel a trip: the removal was accepted and staged in <em class="placeholder">Cancel</em>; nothing is removed until it is applied.'], $this->statuses());
    $this->assertIsArray($this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION)->get('trip'), 'The trip is still stored.');
    $this->assertSame('delete', $this->stages()->read($stage)['writes'][0]['kind']);
  }

  /**
   * Asks the route's access check about the route on the stack.
   *
   * As for a link: no request is handed in, so the current one is read.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   */
  protected function routeAccess(): AccessResultInterface {
    return $this->container->get('surface.operation_access')->access($this->container->get('current_route_match'), $this->container->get('current_user'));
  }

  /**
   * Submits the page on the stack as a programmed form.
   *
   * @param array $values
   *   The data surface's values.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state, no longer programmed, so its redirect reads.
   */
  protected function post(array $values): FormState {
    $state = (new FormState())->setValues($values === [] ? [] : [SurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    return $state->setProgrammed(FALSE);
  }

  /**
   * Reads the status messages, and clears them.
   *
   * @return list<string>
   *   The messages.
   */
  protected function statuses(): array {
    $messages = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->container->get('messenger')->deleteAll();
    return $messages;
  }

}
