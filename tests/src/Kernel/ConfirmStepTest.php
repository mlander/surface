<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface\SurfaceAccess;
use Drupal\surface_stage_test\Storage\ItineraryStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the confirm page a flow ends in: the stage's writes, and Save.
 *
 * Over the itinerary's update flow, whose stage is given a second write
 * through the trip's own operations, as any door may write into it.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ConfirmStepTest extends SurfaceKernelTestBase {

  use OperationRouteTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'serialization', 'tool', 'surface', 'surface_stage_test'];

  /**
   * The stage the flow's first step opened.
   */
  protected string $stage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['keep the ledger']);
    $this->container->get('router.builder')->rebuild();
    foreach (['trip' => 'mon', 'other' => 'wed', 'third' => 'fri'] as $id => $day) {
      $this->trips()->set($id, ['id' => $id, 'region' => 'north', 'day' => $day, 'nights' => 2]);
    }
    $this->visit('/surface-test/itinerary/trip/flow');
    $state = $this->post(['region' => 'south']);
    $this->stage = $state->getRedirect()->getOption('query')['stage'];
    $result = $this->stage($this->stage, 'stage_test.itinerary', ['id' => 'other'], ['nights' => 5]);
    $this->assertTrue($result->committed, $this->said($result));
    $this->container->get('messenger')->deleteAll();
  }

  /**
   * Tests the page lists the staged writes and the keys each moved.
   */
  public function testThePageListsTheStagedWrites(): void {
    $form = $this->confirmPage();
    $this->assertSame([
      ['1', 'stage_test.itinerary', 'update', 'update', 'id: trip', '/region'],
      ['2', 'stage_test.itinerary', 'update', 'update', 'id: other', '/nights'],
    ], $form['changes']['#rows']);
    $this->assertSame('Each is checked again when it is made. They are made together or not at all.', (string) $form['together']['#markup']);
    $this->assertArrayNotHasKey('destructive', $form);
    $this->assertSame('primary', $form['actions']['apply']['#button_type']);
    $this->assertSame(0, $form['#cache']['max-age']);
    $this->assertContains('url.query_args:stage', $form['#cache']['contexts']);
    $this->assertContains('url.query_args:step', $form['#cache']['contexts']);
  }

  /**
   * Tests a staged delete makes Save a danger button, and says so.
   */
  public function testDeleteIsSaidAndApplyIsDangerous(): void {
    $result = $this->stage($this->stage, 'stage_test.itinerary', ['id' => 'other'], [], 'delete');
    $this->assertTrue($result->committed, $this->said($result));
    $form = $this->confirmPage();
    $this->assertSame(['2', 'stage_test.itinerary', 'delete', 'delete', 'id: other', 'the whole thing'], $form['changes']['#rows'][1]);
    $this->assertSame('One of these cannot be undone once applied.', (string) $form['destructive']['#markup']);
    $this->assertSame('danger', $form['actions']['apply']['#button_type']);
  }

  /**
   * Tests an operation that declares itself destructive is said too.
   */
  public function testDestructiveOperationIsSaid(): void {
    $result = $this->stage($this->stage, 'stage_test.itinerary', ['id' => 'third'], ['nights' => 3], 'close');
    $this->assertTrue($result->committed, $this->said($result));
    $form = $this->confirmPage();
    $this->assertSame(['3', 'stage_test.itinerary', 'close', 'update', 'id: third', '/nights'], $form['changes']['#rows'][2]);
    $this->assertSame('One of these cannot be undone once applied.', (string) $form['destructive']['#markup']);
    $this->assertSame('danger', $form['actions']['apply']['#button_type']);
  }

  /**
   * Tests Save makes what the page listed, or nothing.
   *
   * A write staged after the page was drawn, here a delete from another
   * door, refuses the Save posted from that page; the page lists it,
   * and Save from the page drawn again makes every write.
   */
  public function testApplyRefusesWhatThePageDidNotList(): void {
    $listed = $this->confirmPage()['surface_stage_listed']['#value'];
    $result = $this->stage($this->stage, 'stage_test.itinerary', ['id' => 'third'], [], 'delete');
    $this->assertTrue($result->committed, $this->said($result));
    $state = $this->post([], 'Save', ['surface_stage_listed' => $listed]);
    $this->assertSame(['The stage changed since this page was drawn; it is listed again below.'], array_values(array_map('strval', $state->getErrors())));
    $this->assertSame('north', $this->trips()->get('trip')['region'], 'Nothing was applied.');
    $this->assertIsArray($this->trips()->get('third'));
    $this->assertSame(StageStorage::OPEN, $this->stages()->read($this->stage)['status']);
    // The page shown with the error is drawn from the stage as it is now,
    // and carries what it lists for the next Save.
    $shown = $state->getCompleteForm();
    $this->assertCount(3, $shown['changes']['#rows']);
    $this->assertSame($this->confirmPage()['surface_stage_listed']['#value'], $shown['surface_stage_listed']['#value']);
    $this->assertNotSame($listed, $shown['surface_stage_listed']['#value']);
    $state = $this->post([], 'Save', ['surface_stage_listed' => $shown['surface_stage_listed']['#value']]);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('south', $this->trips()->get('trip')['region']);
    $this->assertSame(5, $this->trips()->get('other')['nights']);
    $this->assertNull($this->trips()->get('third'));
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($this->stage)['status']);
  }

  /**
   * Tests a voided stage shows why, and offers no Save.
   */
  public function testVoidedStageShowsItsStatus(): void {
    $record = $this->stages()->read($this->stage);
    $record['status'] = StageStorage::VOIDED;
    $record['voided'] = ['position' => 1];
    $record['applied'] = [];
    $this->stages()->write($record);
    $form = $this->confirmPage();
    $this->assertSame('The stage ' . $this->stage . ' was voided at position 1; writes at and before it were made permanent by a schema change. Cancel removes it.', (string) $form['status']['#markup'], 'The page names its own button, where the tools hear "Discard the stage."');
    $this->assertSame([], $form['applied']['#rows']);
    $this->assertArrayNotHasKey('apply', $form['actions']);
    $this->assertArrayHasKey('discard', $form['actions']);
  }

  /**
   * Tests Save makes both writes and marks the stage applied.
   */
  public function testApplyMakesEveryWrite(): void {
    $this->confirmPage();
    $state = $this->post([], 'Save');
    $this->assertSame([], $state->getErrors());
    $this->assertNull($state->getRedirect(), 'With no cosmetic layer, the page stays.');
    $this->assertSame(['Applied 2 writes.'], array_map('strval', $this->container->get('messenger')->messagesByType('status')));
    $this->assertSame('south', $this->trips()->get('trip')['region']);
    $this->assertSame(5, $this->trips()->get('other')['nights']);
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($this->stage)['status']);
    // The page shows the stage applied, what was made, and no Save.
    $form = $this->confirmPage();
    $this->assertMatchesRegularExpression('/^This stage was applied \d+ seconds? ago\.$/', (string) $form['status']['#markup']);
    $this->assertCount(2, $form['applied']['#rows']);
    $this->assertArrayNotHasKey('apply', $form['actions']);
  }

  /**
   * Tests an apply refused is said on the page, and writes nothing.
   */
  public function testRefusedApplyWritesNothing(): void {
    // The trip moves behind the stage's back.
    $this->trips()->set('other', ['id' => 'other', 'region' => 'north', 'day' => 'thu', 'nights' => 2]);
    $this->confirmPage();
    $state = $this->post([], 'Save');
    $errors = array_map('strval', $state->getErrors());
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('(conflict)', reset($errors));
    $this->assertSame('north', $this->trips()->get('trip')['region'], 'Neither write was made.');
    $this->assertSame(StageStorage::OPEN, $this->stages()->read($this->stage)['status']);
  }

  /**
   * Tests Cancel deletes the stage and starts the flow again.
   */
  public function testDiscardDeletesTheStage(): void {
    $this->confirmPage();
    $state = $this->post([], 'Cancel');
    $this->assertSame([], $state->getErrors());
    $this->assertNull($this->stages()->read($this->stage));
    $redirect = $state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('surface_stage_test.itinerary_flow', $redirect->getRouteName());
    $this->assertSame([], $redirect->getOption('query'));
    $this->assertSame('mon', $this->trips()->get('trip')['day']);
  }

  /**
   * Tests Back goes to the last group's step.
   */
  public function testBackGoesToTheLastStep(): void {
    $this->confirmPage();
    $state = $this->post([], 'Back');
    $this->assertSame(['stage' => $this->stage, 'step' => '3'], $state->getRedirect()->getOption('query'));
    $this->assertCount(2, $this->stages()->read($this->stage)['writes']);
  }

  /**
   * Puts the confirm page on the stack and builds it.
   *
   * @return array
   *   The form.
   */
  protected function confirmPage(): array {
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $this->stage . '&step=confirm');
    return $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
  }

  /**
   * Tests the form's runs of the stage answer as before the runner.
   *
   * SurfaceOperationForm::stageRun() runs through the runner and reads
   * Refusal::actual() to tell a stage that is gone from one refused. For a
   * stage that is gone, another account's, rehearsed, applied, and asked
   * again once applied, it answers what the form's own sequence answered
   * before: the same result, the same messages.
   */
  public function testTheFormsStageRunsAnswerAsBefore(): void {
    $other = $this->createUser(['keep the ledger']);
    $theirs = $this->container->get('surface.runner')->run('surface.stage', 'create', values: ['label' => 'Theirs'], account: $other)->outputs()['id'];
    $cases = [
      'gone' => ['apply', 'ghost', FALSE],
      'refused' => ['changes', $theirs, FALSE],
      'rehearsed' => ['apply', $this->stage, TRUE],
      'applied' => ['apply', $this->stage, FALSE],
      'applied again' => ['apply', $this->stage, FALSE],
    ];
    $form = SurfaceOperationForm::create($this->container);
    $stageRun = new \ReflectionMethod($form, 'stageRun');
    foreach ($cases as $case => [$operation, $id, $dry]) {
      [$result, $outputs] = $stageRun->invoke($form, $operation, $id, $dry);
      $this->assertSame(...self::answered($result, $outputs, $case === 'applied' ? NULL : $this->before($operation, $id, $dry), $case));
    }
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($this->stage)['status']);
  }

  /**
   * Runs one of the stage's operations as the form did before the runner.
   *
   * @param string $operation
   *   The operation.
   * @param string $id
   *   The stage's id.
   * @param bool $dry
   *   Whether to rehearse only.
   *
   * @return array{0: \Drupal\surface\Pipeline\SurfaceResult|null, 1: array}
   *   The result, and what it reports; no result when the stage is gone.
   */
  protected function before(string $operation, string $id, bool $dry): array {
    $resources = $this->container->get('surface.resources');
    try {
      $context = $resources->operation(StageResource::ID, $operation, ['id' => $id]);
    }
    catch (NamesNothingException) {
      return [NULL, []];
    }
    $access = SurfaceAccess::decisive($resources->access(StageResource::ID, $context), 'This stage may not be used by this account.');
    $built = $resources->build(StageResource::ID, $context);
    $storage = $resources->storage(StageResource::ID, $context, $built);
    $result = $this->container->get('surface.pipeline')->submit($built, [], $storage, $dry, $access);
    $outputs = $result->isValid() ? $this->container->get('surface.pipeline')->outputs($built, $storage, $result) : [];
    return [$result, $outputs];
  }

  /**
   * Reads what a person sees of a run of the stage, beside the old one.
   *
   * @param \Drupal\surface\Pipeline\SurfaceResult|null $result
   *   The result through the runner.
   * @param array $outputs
   *   Its outputs.
   * @param array|null $before
   *   The result and outputs the form's old sequence answered, or NULL
   *   when it cannot be asked again (an apply that was just made).
   * @param string $case
   *   The case.
   *
   * @return array{0: array, 1: array, 2: string}
   *   What the old sequence answered, what the runner answered, and the
   *   case, for assertSame().
   */
  protected static function answered(?SurfaceResult $result, array $outputs, ?array $before, string $case): array {
    $read = static fn (?SurfaceResult $result, array $outputs): array => [
      'result' => $result !== NULL,
      'committed' => $result?->committed,
      'messages' => $result === NULL ? [] : array_map(
        static fn (Violation $violation): array => [$violation->path, $violation->code, (string) $violation->message],
        iterator_to_array($result->violations, FALSE),
      ),
      'applied' => array_map(static fn (array $applied): mixed => $applied['id'] ?? NULL, is_array($outputs['applied'] ?? NULL) ? $outputs['applied'] : []),
    ];
    $now = $read($result, $outputs);
    if ($before === NULL) {
      // Applied for real, which the old sequence cannot be asked again.
      return [['result' => TRUE, 'committed' => TRUE, 'messages' => [], 'applied' => ['trip', 'other']], $now, $case];
    }
    $then = $read(...$before);
    // A rehearsal's ids are not emitted: the runner leaves them out.
    $then['applied'] = $case === 'rehearsed' ? array_fill(0, count($then['applied']), NULL) : $then['applied'];
    return [$then, $now, $case];
  }

  /**
   * Submits the page on the stack as a programmed form.
   *
   * @param array $values
   *   The data surface's values.
   * @param string|null $op
   *   The button pressed, NULL for the first.
   * @param array $top
   *   Values outside the data surface, by element name.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state, no longer programmed, so its redirect reads.
   */
  protected function post(array $values, ?string $op = NULL, array $top = []): FormState {
    $state = (new FormState())->setValues($top + ($values === [] ? [] : [SurfaceOperationForm::SURFACE_KEY => $values]) + ($op === NULL ? [] : ['op' => $op]));
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    return $state->setProgrammed(FALSE);
  }

  /**
   * Gets the trips' collection.
   *
   * @return \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   *   The trips, by id.
   */
  protected function trips(): mixed {
    return $this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION);
  }

}
