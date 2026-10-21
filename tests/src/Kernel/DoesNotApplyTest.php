<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_resource_test\Resource\Bulletin\BulletinResource;
use Drupal\data_surface_resource_test\Structure\Broken\NarrowsAttachedStructure;
use Drupal\data_surface_resource_test\Structure\Broken\UndeclaredDoesNotApplyStructure;
use Drupal\data_surface_resource_test\Structure\Notice\NoticeBoardStructure;
use Drupal\data_surface_resource_test\Structure\Notice\NoticeStructure;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\data_surface\Traits\ContractValidationTrait;
use Drupal\Tests\data_surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a key a refiner answers does not apply, at every door.
 *
 * The bulletin's send day applies only to a weekly bulletin, and is
 * required when it does: the newcomer's case from the first fresh-eyes
 * run, which had no spelling. The notice rules out an attached child,
 * variants and a key an alter mounted the same way.
 *
 * @see docs/decisions.md#a-key-that-does-not-apply
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DoesNotApplyTest extends DataSurfaceKernelTestBase {

  use ContractValidationTrait;
  use StaticRefinerAgreementTrait;
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
    'data_surface_resource_test',
  ];

  /**
   * The bulletin's config object.
   */
  protected const CONFIG = 'data_surface_resource_test.bulletin';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds a resource or structure in its operation, or in none.
   *
   * @param string $class
   *   The class.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built resource or structure.
   */
  protected function built(string $class = BulletinResource::class): DataSurfaceInterface {
    $resources = $this->container->get('data_surface.resources');
    return $class === BulletinResource::class
      ? $resources->build($class, $resources->operation($class, 'configure'))
      : $resources->build($class, new OperationContext('configure'));
  }

  /**
   * Reads what the bulletin's config object holds.
   *
   * @return array
   *   The stored values, less what core keeps.
   */
  protected function stored(): array {
    return array_diff_key($this->config(self::CONFIG)->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Tests a refiner's answer rules the key out, and only for its values.
   */
  public function testTheRefinerRulesTheKeyOut(): void {
    $built = $this->built();
    $this->assertSame([''], $built->getDefinitions()->entry('send_day')->mayNotApply(), 'Declared in the return type.');
    $this->assertFalse(DoesNotApply::marks($built->getDefinition('send_day')), 'Advertised: it may apply.');
    $this->assertTrue(DoesNotApply::marks($built->refine(['frequency' => 'monthly'])->getDefinition('send_day')));
    $weekly = $built->refine(['frequency' => 'weekly'])->getDefinition('send_day');
    $this->assertFalse(DoesNotApply::marks($weekly));
    $this->assertTrue($weekly->isRequired(), 'Required when it applies.');
    $this->assertFalse(DoesNotApply::marks($built->refine(['frequency' => 'yearly'])->getDefinition('send_day')), 'A frequency its own list refuses is no frequency: the gate stays closed.');
  }

  /**
   * Tests the narrowing check: nothing is narrower, nothing comes back.
   */
  public function testNothingIsNarrowerAndNothingComesBack(): void {
    $day = DataDefinition::create('string')->setRequired(TRUE);
    Narrowing::assertNarrows('send_day', 'a test', $day, DoesNotApply::marked($day));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('a key that did not apply was made to apply');
    Narrowing::assertNarrows('send_day', 'a test', DoesNotApply::marked($day), $day);
  }

  /**
   * Tests an answer the return type does not declare is refused.
   */
  public function testAnUndeclaredAnswerIsRefused(): void {
    $built = $this->built(UndeclaredDoesNotApplyStructure::class);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(UndeclaredDoesNotApplyStructure::class . '::quietly() answered DoesNotApply::here() without naming DoesNotApply in its return type.');
    $built->refine(['frequency' => 'monthly']);
  }

  /**
   * Tests a parent may rule out a key holding a child, and say nothing else.
   */
  public function testParentMayOnlyRuleOutChild(): void {
    $notice = $this->built(NoticeStructure::class);
    $this->assertSame([''], $notice->getDefinitions()->entry('address')->mayNotApply());
    $email = $notice->refine(['channel' => 'email', 'cadence' => 'weekly']);
    $this->assertTrue(DoesNotApply::marks($email->getDefinition('address')));
    $this->assertFalse(DoesNotApply::marks($email->getDefinition('schedule')));
    $post = $notice->refine(['channel' => 'post', 'cadence' => 'weekly']);
    $this->assertFalse(DoesNotApply::marks($post->getDefinition('address')));
    $this->assertTrue(DoesNotApply::marks($post->getDefinition('schedule')), 'Variants are ruled out like any key.');
    $this->assertSame(['data_surface_resource_test.signature'], $notice->getDefinitions()->entry('third_party_settings')->mayNotApply(), 'A mounted key, by its path inside the mount.');
    $this->assertSame(['data_surface_resource_test.signature'], DoesNotApply::inside($post->getDefinition('third_party_settings')));
    $this->assertSame([], DoesNotApply::inside($email->getDefinition('third_party_settings')));

    $narrows = $this->built(NarrowsAttachedStructure::class);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Refining "address", which holds a child,');
    $narrows->refine(['channel' => 'post']);
  }

  /**
   * Tests the pipeline: left out keeps, null keeps, a value is refused.
   */
  public function testThePipelineReadsTheKeyAsLeftOut(): void {
    $built = $this->built();
    $storage = $this->container->get('data_surface.resources')->storage(BulletinResource::class, $this->container->get('data_surface.resources')->operation(BulletinResource::class, 'configure'), $built);

    $result = $this->pipeline()->submit($built, ['frequency' => 'monthly'], $storage);
    $this->assertTrue($result->isValid(), (string) json_encode(array_map('strval', array_map(static fn ($violation) => $violation->message, iterator_to_array($result->violations, FALSE)))));
    $this->assertSame(['frequency' => 'monthly'], $result->values, 'The accepted document says nothing about the send day.');
    $this->assertSame(['frequency' => 'monthly', 'send_day' => 'friday'], $this->stored(), 'Storage keeps it, for when it applies again.');

    $result = $this->pipeline()->submit($built, ['frequency' => 'monthly', 'send_day' => NULL], $storage);
    $this->assertTrue($result->isValid(), 'Null says nothing about a key that does not apply.');
    $this->assertSame('friday', $this->stored()['send_day']);

    $result = $this->pipeline()->submit($built, ['frequency' => 'monthly', 'send_day' => 'friday'], $storage);
    $this->assertTrue($result->isValid(), 'What it holds already says nothing either.');

    $result = $this->pipeline()->submit($built, ['frequency' => 'monthly', 'send_day' => 'monday'], $storage);
    $this->assertFalse($result->isValid());
    $violation = iterator_to_array($result->violations, FALSE)[0];
    $this->assertSame('send_day', $violation->key);
    $this->assertSame('Send day does not apply when Frequency is Monthly.', (string) $violation->message);
    $this->assertSame('friday', $this->stored()['send_day']);

    $result = $this->pipeline()->submit($built, ['frequency' => 'weekly'], $storage);
    $this->assertTrue($result->isValid());
    $this->assertSame(['frequency' => 'weekly', 'send_day' => 'friday'], $result->values, 'Weekly again: the send day survived the month.');

    $result = $this->pipeline()->submit($built, ['send_day' => NULL], $storage);
    $this->assertFalse($result->isValid());
    $this->assertSame('Send day is required.', (string) iterator_to_array($result->violations, FALSE)[0]->message, 'Required where it applies.');
  }

  /**
   * Tests the pipeline rules out a child, variants and a mounted key.
   */
  public function testThePipelineRulesOutNestedKeys(): void {
    $notice = $this->built(NoticeStructure::class);
    $stored = [
      'channel' => 'post',
      'cadence' => 'weekly',
      'address' => ['street' => 'Main street'],
      'schedule' => ['day' => 'friday'],
      'third_party_settings' => ['data_surface_resource_test' => ['signature' => 'Ada', 'footer' => 'Sent by post']],
    ];

    $email = $this->pipeline()->accept($notice, ['channel' => 'email'], $stored);
    $this->assertArrayNotHasKey('address', $email);
    $this->assertSame(['day' => 'friday'], $email['schedule']);
    $this->assertSame(['signature' => 'Ada', 'footer' => 'Sent by post'], $email['third_party_settings']['data_surface_resource_test']);
    $this->assertTrue($this->pipeline()->validate($notice, $email, $stored)->isEmpty());
    $this->assertSame($stored['address'], $this->pipeline()->kept($notice, $email, $stored)['address'], 'What is stored under it is kept.');

    $post = $this->pipeline()->accept($notice, [], $stored);
    $this->assertArrayNotHasKey('schedule', $post);
    $this->assertSame(['footer' => 'Sent by post'], $post['third_party_settings']['data_surface_resource_test'], 'The mounted signature is left out of its mount.');
    $this->assertTrue($this->pipeline()->validate($notice, $post, $stored)->isEmpty(), 'The required signature is not asked for where it does not apply.');
    $kept = $this->pipeline()->kept($notice, $post, $stored);
    $this->assertSame($stored, $kept, 'What does not apply is put back, in declaration order.');

    $sent = $this->pipeline()->accept($notice, [
      'schedule' => ['day' => 'monday'],
      'third_party_settings' => ['data_surface_resource_test' => ['signature' => 'Bea']],
    ], $stored);
    $messages = array_map(static fn ($violation): string => $violation->fullPath() . ': ' . $violation->message, iterator_to_array($this->pipeline()->validate($notice, $sent, $stored), FALSE));
    $this->assertSame([
      'schedule: Schedule does not apply when Channel is post.',
      'third_party_settings.data_surface_resource_test.signature: Signature does not apply when Channel is post.',
    ], $messages);
  }

  /**
   * Tests the schema for these answers leaves the key out.
   */
  public function testTheSchemaForTheseAnswersLeavesTheKeyOut(): void {
    $built = $this->built();
    $emitter = $this->container->get('data_surface.contract_emitter');
    $monthly = $emitter->emit($built, ['frequency' => 'monthly', 'send_day' => 'friday'], 'resource_test.bulletin', 'configure')->document;
    $this->assertSame(['frequency'], array_keys($monthly['schema']['properties']));
    $this->assertSame(['frequency'], $monthly['schema']['required']);
    $this->assertSame(['frequency' => 'monthly'], $monthly['values']);
    $this->assertArrayNotHasKey('allOf', $monthly['schema']);
    $this->assertSame(['contract' => 2, 'version' => 1, 'doesNotApply' => ['send_day' => ['frequency']]], $monthly['schema']['x-surface'], 'Named on the frame, with what to refine on.');

    $weekly = $emitter->emit($built, ['frequency' => 'weekly', 'send_day' => 'friday'], 'resource_test.bulletin', 'configure')->document;
    $this->assertSame(['frequency', 'send_day'], array_keys($weekly['schema']['properties']));
    $this->assertSame(['frequency', 'send_day'], $weekly['schema']['required']);
    $this->assertSame(['frequency' => 'weekly', 'send_day' => 'friday'], $weekly['values']);
  }

  /**
   * Tests the full schema states where the key applies and is required.
   */
  public function testTheFullSchemaStatesItConditionally(): void {
    $built = $this->built();
    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = json_decode((string) json_encode($emitter->emitFull($built, 'resource_test.bulletin', 'configure')->document['schema']), TRUE);
    $this->assertSame(['frequency'], $full['required'], 'Required only where it applies.');
    $this->assertTrue($full['properties']['send_day']['x-surface']['enumerated']);
    $this->assertSame([
      [
        'if' => ['properties' => ['frequency' => ['const' => 'weekly']], 'required' => ['frequency']],
        'then' => ['required' => ['send_day']],
      ],
      [
        'if' => ['properties' => ['frequency' => ['const' => 'monthly']], 'required' => ['frequency']],
        'then' => ['properties' => ['send_day' => FALSE]],
      ],
    ], $full['allOf']);
    $this->assertTrue($this->validates($full, ['frequency' => 'monthly']));
    $this->assertFalse($this->validates($full, ['frequency' => 'monthly', 'send_day' => 'friday']));
    $this->assertFalse($this->validates($full, ['frequency' => 'weekly']));
    $this->assertTrue($this->validates($full, ['frequency' => 'weekly', 'send_day' => 'friday']));
    $this->assertSame(2, $this->assertStaticRefinersAgree($built, NULL, 'resource_test.bulletin'));
  }

  /**
   * Tests both documents state the nested keys ruled out.
   */
  public function testTheFullSchemaRulesOutNestedKeys(): void {
    $notice = $this->built(NoticeStructure::class);
    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = json_decode((string) json_encode($emitter->emitFull($notice, 'resource_test.notice')->document['schema']), TRUE);
    $this->assertSame(['channel', 'cadence'], $full['required']);
    $this->assertArrayNotHasKey('required', $full['properties']['third_party_settings']['properties']['data_surface_resource_test'], 'The signature is required only where it applies.');
    $by_channel = [];
    foreach ($full['allOf'] as $condition) {
      $channel = $condition['if']['properties']['channel']['const'] ?? NULL;
      if ($channel !== NULL) {
        // Less the declared type each map states beside what it narrows.
        // Decision: see docs/decisions.md#contract-2.
        $by_channel[$channel][] = self::untyped($condition['then']);
      }
    }
    $this->assertContains(['properties' => ['address' => FALSE]], $by_channel['email']);
    $this->assertContains(['properties' => ['schedule' => FALSE]], $by_channel['post']);
    $this->assertContains(['properties' => ['third_party_settings' => ['properties' => ['data_surface_resource_test' => ['properties' => ['signature' => FALSE]]]]]], $by_channel['post']);
    $this->assertContains(['properties' => ['third_party_settings' => ['properties' => ['data_surface_resource_test' => ['required' => ['signature']]]]]], $by_channel['email']);
    $this->assertGreaterThan(0, $this->assertStaticRefinersAgree($notice, NULL, 'resource_test.notice'));

    $post = json_decode((string) json_encode($emitter->emit($notice, ['channel' => 'post', 'cadence' => 'weekly'], 'resource_test.notice')->document), TRUE);
    $this->assertSame(['channel', 'cadence', 'address', 'third_party_settings'], array_keys($post['schema']['properties']));
    $this->assertSame(['footer'], array_keys($post['schema']['properties']['third_party_settings']['properties']['data_surface_resource_test']['properties']), 'The signature is no property of its mount.');
    $this->assertArrayNotHasKey('schedule', $post['values']);
    $this->assertSame([], array_filter($post['schema']['allOf'] ?? [], static fn (array $condition): bool => isset($condition['then']['properties']['schedule'])), 'No variant of a key that does not apply.');
  }

  /**
   * Tests a runtime refiner that may rule a key out: in no `required`.
   */
  public function testRuntimeRuledOutIsRequiredNowhereAhead(): void {
    $board = $this->built(NoticeBoardStructure::class);
    $emitter = $this->container->get('data_surface.contract_emitter');
    $this->assertSame([''], $board->getDefinitions()->entry('pinned')->mayNotApply(TRUE));
    $full = $emitter->emitFull($board, 'resource_test.notice_board')->document['schema'];
    $this->assertSame(['title'], $full['required'], 'Nothing says where it applies, so nothing requires it.');
    $this->assertTrue($full['properties']['pinned']['x-surface']['live']);

    $open = $emitter->emit($board, ['title' => 'Hall'], 'resource_test.notice_board')->document['schema'];
    $this->assertSame(['title', 'pinned'], $open['required'], 'Open, for these values: required.');
    $this->assertFalse($this->pipeline()->validate($board, $this->pipeline()->accept($board, ['title' => 'Hall']))->isEmpty());

    $this->container->get('state')->set(NoticeBoardStructure::CLOSED, TRUE);
    $closed = $emitter->emit($board, ['title' => 'Hall'], 'resource_test.notice_board')->document['schema'];
    $this->assertSame(['title'], array_keys($closed['properties']));
    $this->assertSame(['contract' => 2, 'version' => 1, 'doesNotApply' => ['pinned' => []]], $closed['x-surface']);
    $accepted = $this->pipeline()->accept($board, ['title' => 'Hall']);
    $this->assertSame(['title' => 'Hall'], $accepted);
    $this->assertTrue($this->pipeline()->validate($board, $accepted)->isEmpty(), 'Closed: not asked for.');
  }

  /**
   * Tests the tool: optional in its listing, refused named, read left out.
   */
  public function testTheToolAndTheReadTool(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $tool = $tools->createInstance('data_surface:resource_test.bulletin:configure');
    $values = $tool->getInputDefinition(OperationInputs::VALUES);
    $this->assertFalse($values->getPropertyDefinition('send_day')->isRequired(), 'Required only where it applies, which a listing cannot say.');
    // An update: required in words, a stored value standing when left out.
    $this->assertFalse($values->getPropertyDefinition('frequency')->isRequired());
    $this->assertStringEndsWith('Required; a value already stored stands when left out.', (string) $values->getPropertyDefinition('frequency')->getDescription());

    $tool->setInputValue(OperationInputs::VALUES, ['frequency' => 'monthly']);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->assertSame(['frequency' => 'monthly', 'send_day' => 'friday'], $this->stored());

    $tool = $tools->createInstance('data_surface:resource_test.bulletin:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['frequency' => 'monthly', 'send_day' => 'monday']);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('send_day: Send day does not apply when frequency is monthly (Monthly).', (string) $tool->getResult()->getMessage());

    $read = $tools->createInstance('data_surface:resource_test.bulletin:configure:read');
    $read->execute();
    $this->assertTrue($read->getResult()->isSuccess());
    $this->assertSame(['frequency' => 'monthly'], $read->getResult()->getContextValues()[OperationInputs::VALUES], 'What does not apply is not read back.');

    $this->config(self::CONFIG)->set('frequency', 'weekly')->save();
    $read = $tools->createInstance('data_surface:resource_test.bulletin:configure:read');
    $read->execute();
    $this->assertSame(['frequency' => 'weekly', 'send_day' => 'friday'], $read->getResult()->getContextValues()[OperationInputs::VALUES]);
  }

  /**
   * Serves the bulletin's route and builds or submits its form.
   *
   * @param array|null $values
   *   Raw values to submit, or NULL to build the form only.
   *
   * @return array{0: array, 1: \Drupal\Core\Form\FormState}
   *   The form and its state.
   */
  protected function form(?array $values = NULL): array {
    $route_name = 'data_surface_resource_test.bulletin';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    if ($values === NULL) {
      $form = $this->container->get('form_builder')->buildForm(DataSurfaceOperationForm::class, $form_state);
      return [$form, $form_state];
    }
    $form_state->setValues([DataSurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);
    return [$form_state->getCompleteForm(), $form_state];
  }

  /**
   * Tests the form draws no element for the key, and keeps what it holds.
   */
  public function testTheFormDrawsNothing(): void {
    [$form] = $this->form();
    $this->assertSame('select', $form[DataSurfaceOperationForm::SURFACE_KEY]['send_day']['#type'], 'Weekly, as stored.');

    $this->config(self::CONFIG)->set('frequency', 'monthly')->save();
    [$form] = $this->form();
    $send_day = $form[DataSurfaceOperationForm::SURFACE_KEY]['send_day'];
    $this->assertArrayNotHasKey('#type', $send_day, 'Not rendered: not disabled, not hidden.');
    $this->assertSame('', $send_day['#markup']);

    [, $form_state] = $this->form(['frequency' => 'monthly', 'send_day' => 'monday']);
    $this->assertSame([], $form_state->getErrors(), 'What a key with no element posts is not read.');
    $this->assertSame(['frequency' => 'monthly', 'send_day' => 'friday'], $this->stored());
  }

  /**
   * Tests a list narrowed to nothing is a select offering nothing.
   */
  public function testListOfNothingIsSelectOfNothing(): void {
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: [
      'badge' => DataDefinition::create('string')->setLabel('Badge')->addConstraint('LabeledChoice', ['choices' => []]),
      'kind' => DataDefinition::create('string')->setLabel('Kind')->setRequired(TRUE)->addConstraint('Choice', ['choices' => []]),
    ]));
    $form = $this->formBuilder()->buildSurfaceForm($surface, ['badge' => 'star'], new FormState());
    $this->assertSame('select', $form['badge']['#type']);
    $this->assertSame([], $form['badge']['#options']);
    $schema = $this->container->get('data_surface.contract_emitter')->emit($surface, [], 'test')->document['schema'];
    $this->assertSame([['const' => NULL]], $schema['properties']['badge']['oneOf'], 'Optional: null alone.');
    $this->assertSame([['const' => NULL]], $schema['properties']['kind']['oneOf'], 'Required: null, which its type refuses, so nothing.');
    $this->assertSame('string', $schema['properties']['kind']['type']);
    $this->assertFalse($this->validates($schema, ['badge' => NULL, 'kind' => 'anything']));
    $this->assertFalse($this->validates($schema, ['badge' => 'star']), 'Nothing is allowed but null.');
  }

  /**
   * Leaves out every `type` a schema states, at any depth.
   *
   * @param array $schema
   *   The schema.
   *
   * @return array
   *   The same, with no `type`.
   */
  protected static function untyped(array $schema): array {
    unset($schema['type']);
    foreach ($schema as $keyword => $value) {
      if (is_array($value)) {
        $schema[$keyword] = self::untyped($value);
      }
    }
    return $schema;
  }

}
