<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\PipelineGuard;
use Drupal\surface\Resource\AccessAnswer;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_resource_test\Access\Broken\FickleAccess;
use Drupal\surface_resource_test\Access\ExhibitAccess;
use Drupal\surface_resource_test\Access\InsuranceAccess;
use Drupal\surface_resource_test\Resource\Exhibit\ExhibitResource;
use Drupal\surface_resource_test\Resource\Journal\JournalResource;
use Drupal\surface_resource_test\Structure\Broken\FickleAccessParentStructure;
use Drupal\surface_resource_test\Structure\Broken\StateReadingParentStructure;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Plugin\Derivative\ResourceOperationToolDeriver;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\surface\Traits\StaticAccessAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Access is static or runtime, read from its cacheability.
 *
 * The exhibit mixes in its provenance and attaches its loan, both
 * answered by one access class: static for an inspector (locked) and an
 * archivist (left out), runtime for anyone else (locked once the exhibit
 * is sealed, left out once it is withdrawn). The loan's insurance inside
 * it is runtime for everyone. The full schema and the tool listing are
 * built for the account alone and state the static answers; the schema
 * for these answers, the form and the pipeline apply both.
 *
 * @see docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class StaticRuntimeAccessTest extends SurfaceKernelTestBase {

  use ContractValidationTrait;
  use StaticAccessAgreementTrait;
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
    'surface_examples_field',
    'surface_resource_test',
  ];

  /**
   * The exhibit as installed.
   */
  protected const EXHIBIT = [
    'title' => 'Ammonite',
    'status' => 'open',
    'room' => 'east',
    'source' => 'Lyme Regis',
    'acquired' => 1820,
    'loan' => [
      'lender' => 'The county museum',
      'until' => 2030,
      'insurance' => ['policy' => 'P-7'],
    ],
  ];

  /**
   * The accounts, by what they are.
   *
   * @var array<string, \Drupal\Core\Session\AccountInterface>
   */
  protected array $accounts = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test']);
    // User 1 first, so none of the accounts below is the super user.
    $this->setUpCurrentUser();
    $this->createRole(['keep an exhibit'], ExhibitAccess::ARCHIVIST);
    $this->accounts = [
      'keeper' => $this->createUser(['keep an exhibit']),
      'inspector' => $this->createUser(['keep an exhibit', 'inspect exhibit']),
      'archivist' => $this->createUser([], NULL, FALSE, ['roles' => [ExhibitAccess::ARCHIVIST]]),
      'stranger' => $this->createUser([]),
    ];
    $this->actAs('keeper');
    ExhibitAccess::$calls = 0;
    FickleAccess::$calls = 0;
  }

  /**
   * Makes one of the accounts the current user.
   *
   * @param string $who
   *   The account's label.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function actAs(string $who): AccountInterface {
    $this->container->get('current_user')->setAccount($this->accounts[$who]);
    return $this->accounts[$who];
  }

  /**
   * Builds the exhibit in its operation.
   *
   * @param bool $alone
   *   TRUE to build it for the account alone, as the full schema is.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built exhibit.
   */
  protected function exhibit(bool $alone = FALSE): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(ExhibitResource::class, 'configure');
    return $alone ? $resources->buildStatic(ExhibitResource::class, $context) : $resources->build(ExhibitResource::class, $context);
  }

  /**
   * Emits the exhibit's full schema, built for the account alone, decoded.
   *
   * @return array
   *   The schema, its mixed-in members read flat.
   */
  protected function full(): array {
    $served = $this->container->get('surface.contract_emitter')->emitFull($this->exhibit(TRUE), 'resource_test.exhibit', 'configure');
    return SchemaResolver::inline(json_decode((string) json_encode($served->document['schema']), TRUE));
  }

  /**
   * Emits the exhibit's schema for these answers, decoded.
   *
   * @param array $values
   *   The values.
   *
   * @return array
   *   The schema, its mixed-in members read flat.
   */
  protected function these(array $values = self::EXHIBIT): array {
    $served = $this->container->get('surface.contract_emitter')->emit($this->exhibit(), $values, 'resource_test.exhibit', 'configure');
    return SchemaResolver::inline(json_decode((string) json_encode($served->document['schema']), TRUE));
  }

  /**
   * Seals or opens the exhibit, as stored.
   *
   * @param string $status
   *   The status.
   */
  protected function seal(string $status = 'sealed'): void {
    $this->config('surface_resource_test.exhibit')->set('status', $status)->save();
  }

  /**
   * Tests the kind is read from what an answer depends on.
   */
  public function testTheKindIsReadFromCacheability(): void {
    $static = [
      'a permission' => AccessAnswer::locked()->addCacheContexts(['user.permissions']),
      'a role' => AccessAnswer::omitted()->addCacheContexts(['user.roles']),
      'one role' => AccessAnswer::omitted()->addCacheContexts(['user.permissions', 'user.roles:archivist']),
      'core\'s permission check' => AccessAnswer::fromAnswer(AccessResult::allowedIfHasPermission($this->accounts['keeper'], 'keep an exhibit')),
      'decided from nothing, by the build itself' => AccessAnswer::open(),
    ];
    foreach ($static as $what => $answer) {
      $this->assertSame(AccessAnswer::STATIC, $answer->kind(), $what);
    }
    $runtime = [
      'a cache tag' => AccessAnswer::locked()->addCacheContexts(['user.permissions'])->addCacheTags(['config:surface_resource_test.exhibit']),
      'the user' => AccessAnswer::locked()->addCacheContexts(['user']),
      'a route' => AccessAnswer::open()->addCacheContexts(['route']),
      'max-age 0' => AccessAnswer::omitted()->mergeCacheMaxAge(0),
      'core\'s answer with nothing' => AccessAnswer::fromAnswer(AccessResult::allowed()),
      'an answer with nothing, read' => AccessAnswer::fromAnswer(AccessAnswer::locked()),
      'nothing, then a permission added' => AccessAnswer::fromAnswer(AccessAnswer::open())->addCacheContexts(['user.permissions']),
    ];
    foreach ($runtime as $what => $answer) {
      $this->assertSame(AccessAnswer::RUNTIME, $answer->kind(), $what);
    }
    $this->assertSame(AccessAnswer::RUNTIME, AccessAnswer::kindOf(AccessResult::forbidden()->setCacheMaxAge(0)), 'Core\'s results are read alike.');
    $this->assertSame(AccessAnswer::STATIC, AccessAnswer::kindOf(AccessResult::allowed()->cachePerPermissions()));

    // Combined, the narrower stands, and is static only when both are or
    // a static answer leaves the keys out, which nothing narrows.
    $inspect = AccessAnswer::locked()->addCacheContexts(['user.permissions']);
    $sealed = AccessAnswer::open()->addCacheTags(['config:x']);
    $this->assertTrue($inspect->andIf($sealed)->isLocked());
    $this->assertSame(AccessAnswer::RUNTIME, $inspect->andIf($sealed)->kind(), 'A static lock under a runtime answer may still be left out.');
    $hidden = AccessAnswer::omitted()->addCacheContexts(['user.roles']);
    $settled = $sealed->andIf($hidden);
    $this->assertTrue($settled->isOmitted());
    $this->assertSame(AccessAnswer::STATIC, $settled->kind(), 'Left out by the account alone: what the other read cannot change it.');
    $this->assertSame(['user.roles'], $settled->getCacheContexts());
    $this->assertSame([], $settled->getCacheTags());
    $this->assertSame(AccessAnswer::RUNTIME, AccessAnswer::fromAnswer(AccessResult::allowed())->andIf($inspect)->kind(), 'Promised nothing, combined with anything, is runtime.');
  }

  /**
   * Tests each answer as the build applies it, and as built for the account.
   */
  public function testEachAnswerAppliedAndSetAside(): void {
    // A static lock: applied in both builds.
    $this->actAs('inspector');
    foreach ([FALSE, TRUE] as $alone) {
      $map = $this->exhibit($alone)->getDefinitions();
      $this->assertTrue($map->isLocked('loan'));
      $this->assertTrue($map->isHeld('source') && $map->isHeld('acquired'));
      $this->assertFalse($map->isRuntimeAccess('loan'));
      $this->assertFalse($map->isRuntimeAccess('source'));
    }
    // A static omission: applied in both builds.
    $this->actAs('archivist');
    foreach ([FALSE, TRUE] as $alone) {
      $this->assertSame(['title', 'status', 'room'], $this->exhibit($alone)->getDefinitions()->names());
    }
    // A runtime lock: applied in the build, set aside for the account.
    $this->actAs('keeper');
    $this->seal();
    $map = $this->exhibit()->getDefinitions();
    $this->assertTrue($map->isLocked('loan') && $map->isHeld('source'));
    $this->assertFalse($map->isRuntimeAccess('loan'), 'Built with every answer applied, nothing is marked.');
    $alone = $this->exhibit(TRUE)->getDefinitions();
    $this->assertFalse($alone->isLocked('loan') || $alone->isLocked('source'));
    $this->assertTrue($alone->isRuntimeAccess('loan') && $alone->isRuntimeAccess('source') && $alone->isRuntimeAccess('acquired'));
    $this->assertFalse($alone->isRuntimeAccess('title'));
    // A runtime omission: the same.
    $this->seal('open');
    $this->container->get('state')->set(ExhibitAccess::WITHDRAWN, TRUE);
    $this->assertSame(['title', 'status', 'room'], $this->exhibit()->getDefinitions()->names());
    $this->assertSame(['title', 'status', 'room', 'source', 'acquired', 'loan'], $this->exhibit(TRUE)->getDefinitions()->names());
  }

  /**
   * Tests a static answer is asked once per context and account kind.
   */
  public function testStaticAnswerIsAskedOncePerAccountKind(): void {
    $this->actAs('inspector');
    $this->exhibit();
    $first = ExhibitAccess::$calls;
    // The provenance and the loan are asked in the same context, and with
    // assertions on the first asking is asked again, guarded.
    $this->assertSame($this->assertionsOn() ? 2 : 1, $first);
    $this->exhibit();
    $this->exhibit(TRUE);
    $this->assertSame($first, ExhibitAccess::$calls, 'Kept: nothing but the account decides it.');
    $this->accounts['inspector2'] = $this->createUser(['keep an exhibit', 'inspect exhibit']);
    $this->actAs('inspector2');
    $this->exhibit();
    $this->assertGreaterThan($first, ExhibitAccess::$calls, 'Another account\'s permissions are another kind.');
    // A runtime answer is asked every time.
    $this->actAs('keeper');
    $before = ExhibitAccess::$calls;
    $this->exhibit();
    $this->exhibit();
    $this->assertSame($before + 4, ExhibitAccess::$calls);
  }

  /**
   * Answers whether assertions run here.
   *
   * @return bool
   *   TRUE when assert() is compiled and on.
   */
  protected function assertionsOn(): bool {
    $on = FALSE;
    assert(PipelineGuard::asserting($on));
    return $on;
  }

  /**
   * Tests the full schema, built for each account, and its cacheability.
   */
  public function testTheFullSchemaIsTheAccounts(): void {
    $this->actAs('inspector');
    $full = $this->full();
    $this->assertTrue($full['properties']['loan']['readOnly']);
    $this->assertTrue($full['properties']['source']['readOnly']);
    $this->assertArrayNotHasKey('live', $full['properties']['loan']['x-surface'] ?? [], 'Static: stated, not marked.');
    // Inside the loan the insurance is runtime for everyone: locked with
    // the loan, the parent's static answer, and marked, its own.
    $insurance = $full['properties']['loan']['properties']['insurance'];
    $this->assertTrue($insurance['readOnly']);
    $this->assertTrue($insurance['x-surface']['live']);

    $this->actAs('keeper');
    $full = $this->full();
    foreach (['loan', 'source', 'acquired'] as $key) {
      $this->assertArrayNotHasKey('readOnly', $full['properties'][$key], $key);
      $this->assertTrue($full['properties'][$key]['x-surface']['live'], $key);
    }
    $this->assertArrayNotHasKey('live', $full['properties']['title']['x-surface'] ?? []);
    $this->assertTrue($full['properties']['loan']['properties']['insurance']['x-surface']['live']);

    $this->actAs('archivist');
    $full = $this->full();
    $this->assertSame(['title', 'status', 'room'], array_keys($full['properties']));

    // What the full schema depends on: the account's permissions and
    // roles, and nothing a runtime answer read.
    foreach (['inspector', 'keeper', 'archivist'] as $who) {
      $this->actAs($who);
      $served = $this->container->get('surface.contract_emitter')->emitFull($this->exhibit(TRUE), 'resource_test.exhibit', 'configure');
      $this->assertContains('user.permissions', $served->getCacheContexts(), $who);
      $this->assertNotContains('config:surface_resource_test.exhibit', $served->getCacheTags(), $who);
      $this->assertSame(Cache::PERMANENT, $served->getCacheMaxAge(), $who);
    }
    $this->assertContains('user.roles:archivist', $served->getCacheContexts());
    // The schema for these answers depends on what the runtime answers
    // read as well.
    $this->actAs('keeper');
    $these = $this->container->get('surface.contract_emitter')->emit($this->exhibit(), self::EXHIBIT, 'resource_test.exhibit', 'configure');
    $this->assertContains('config:surface_resource_test.exhibit', $these->getCacheTags());
    $this->assertSame(0, $these->getCacheMaxAge());
  }

  /**
   * Tests the schema for these answers settles each runtime answer.
   */
  public function testTheSchemaForTheseAnswersSettlesIt(): void {
    $this->actAs('keeper');
    $these = $this->these();
    $this->assertArrayNotHasKey('readOnly', $these['properties']['loan']);
    $this->assertArrayNotHasKey('live', $these['properties']['loan']['x-surface'] ?? [], 'Settled: nothing is marked.');
    $this->assertTrue($this->validates($these, self::EXHIBIT));

    $this->seal();
    $sealed = ['status' => 'sealed'] + self::EXHIBIT;
    $these = $this->these($sealed);
    $this->assertTrue($these['properties']['loan']['readOnly']);
    $this->assertTrue($these['properties']['source']['readOnly']);
    $this->assertSame('Lyme Regis', $these['properties']['source']['const'], 'Locked by access: it holds what is stored.');
    $this->assertTrue($this->validates($these, $sealed));

    $this->seal('open');
    $this->container->get('state')->set(ExhibitAccess::WITHDRAWN, TRUE);
    $these = $this->these(['title' => 'Ammonite', 'status' => 'open', 'room' => 'east']);
    $this->assertSame(['title', 'status', 'room'], array_keys($these['properties']));

    // The insurance alone, inside an open loan.
    $this->container->get('state')->set(ExhibitAccess::WITHDRAWN, FALSE);
    $this->container->get('state')->set(InsuranceAccess::LAPSED, TRUE);
    $loan = $this->exhibit()->getDefinitions()->entry('loan')->attachment->child;
    $this->assertSame(['lender', 'until'], $loan->getDefinitions()->names());
    $loan = $this->exhibit(TRUE)->getDefinitions()->entry('loan')->attachment->child;
    $this->assertSame(['lender', 'until', 'insurance'], $loan->getDefinitions()->names());
    $this->assertTrue($loan->getDefinitions()->isRuntimeAccess('insurance'));
  }

  /**
   * Tests a locked key posted with another value keeps what is stored.
   */
  public function testLockedKeyKeepsWhatIsStored(): void {
    foreach (['inspector' => NULL, 'keeper' => 'sealed'] as $who => $status) {
      $this->actAs($who);
      if ($status !== NULL) {
        $this->seal($status);
      }
      $stored = ['status' => $status ?? 'open'] + self::EXHIBIT;
      $posted = ['source' => 'Dover', 'loan' => ['lender' => 'Nobody'] + self::EXHIBIT['loan']];
      $accepted = $this->pipeline()->accept($this->exhibit(), $posted, $stored);
      $this->assertSame('Lyme Regis', $accepted['source'], $who);
      $this->assertSame('The county museum', $accepted['loan']['lender'], $who);
    }
  }

  /**
   * Tests narrowing a key's values by account is a runtime refiner.
   */
  public function testNarrowingByAccountIsRuntimeRefinement(): void {
    $this->actAs('keeper');
    $full = $this->full();
    $this->assertTrue($full['properties']['room']['x-surface']['live']);
    $this->assertSame(['east', 'west', 'vault'], static::rooms($full), 'The full schema keeps every room: the refiner is never run ahead.');
    $these = $this->these();
    $this->assertSame(['east', 'west'], static::rooms($these));
    $this->assertNotEmpty($this->refusals(['room' => 'vault'] + self::EXHIBIT));
    $this->accounts['vaulter'] = $this->createUser(['keep an exhibit', 'use the vault']);
    $this->actAs('vaulter');
    $this->assertSame(['east', 'west', 'vault'], static::rooms($this->these()));
    $this->assertSame([], $this->refusals(['room' => 'vault'] + self::EXHIBIT));
  }

  /**
   * Lists the rooms a schema offers, less the null an optional key allows.
   *
   * @param array $schema
   *   The schema.
   *
   * @return string[]
   *   The rooms.
   */
  protected static function rooms(array $schema): array {
    return array_values(array_filter(array_column($schema['properties']['room']['oneOf'], 'const'), static fn (mixed $room): bool => $room !== NULL));
  }

  /**
   * Accepts and validates values for the exhibit as stored.
   *
   * @param array $input
   *   The input.
   *
   * @return array<string, string>
   *   The refusals, by dotted path.
   */
  protected function refusals(array $input): array {
    $surface = $this->exhibit();
    $accepted = $this->pipeline()->accept($surface, $input, self::EXHIBIT);
    $messages = [];
    foreach ($this->pipeline()->validate($surface, $accepted, self::EXHIBIT) as $violation) {
      $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    return $messages;
  }

  /**
   * Tests an answer that is not what its cacheability says is refused.
   */
  public function testEnforcementRefusesStaticAnswerReadingMore(): void {
    if (!$this->assertionsOn()) {
      $this->markTestSkipped('The guard runs under assert().');
    }
    $resources = $this->container->get('surface.resources');
    try {
      $resources->build(StateReadingParentStructure::class, new OperationContext('configure'));
      $this->fail('A static answer that reads State is refused.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('StateReadingAccess answered static', $e->getMessage());
      $this->assertStringContainsString('the service state', $e->getMessage());
    }
    try {
      $resources->build(FickleAccessParentStructure::class, new OperationContext('configure'));
      $this->fail('A static answer that changes when asked again is refused.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('FickleAccess answered open, static by its cacheability, and asked again', $e->getMessage());
    }
  }

  /**
   * Tests the operation's permission is the first static answer.
   */
  public function testTheOperationsPermissionIsTheFirstStaticAnswer(): void {
    $resources = $this->container->get('surface.resources');
    $operation = $this->container->get('surface.resource_registry')->getOperation(ExhibitResource::class, 'configure');
    $this->assertSame('keep an exhibit', $operation->staticPermission());
    $this->assertSame('keep an exhibit', ResourceOperationToolDeriver::declaredPermission($operation));
    $context = $resources->operation(ExhibitResource::class, 'configure');
    $refused = $resources->access(ExhibitResource::class, $context, $this->accounts['stranger']);
    $this->assertFalse($refused->isAllowed());
    $this->assertSame(AccessAnswer::STATIC, AccessAnswer::kindOf($refused), 'Refused by the account alone.');
    $this->assertTrue($resources->access(ExhibitResource::class, $context, $this->accounts['keeper'])->isAllowed());
    $tools = $this->container->get('plugin.manager.tool');
    foreach (['surface:resource_test.exhibit:configure', 'surface:resource_test.exhibit:configure:read'] as $id) {
      $this->assertSame('keep an exhibit', $tools->getDefinition($id)->getPermission(), $id);
    }
    // A placeholder names the subject's permission: never declared.
    $field = $this->container->get('surface.resource_registry')->getOperation('field.instance', 'create');
    $this->assertNull($field->staticPermission());
  }

  /**
   * Tests the tool listing, for the account that asks.
   */
  public function testTheToolListingIsTheAccounts(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $id = 'surface:resource_test.exhibit:configure';

    $this->actAs('inspector');
    $values = $tools->createInstance($id)->getInputDefinition(OperationInputs::VALUES);
    $this->assertTrue($values->getPropertyDefinition('loan')->isLocked());
    $this->assertTrue($values->getPropertyDefinition('source')->isLocked());
    $this->assertStringNotContainsString('decided once', (string) $values->getPropertyDefinition('loan')->getDescription());

    $this->actAs('archivist');
    $values = $tools->createInstance($id)->getInputDefinition(OperationInputs::VALUES);
    $this->assertSame(['title', 'status', 'room'], array_keys($values->getPropertyDefinitions()));

    $this->actAs('keeper');
    $values = $tools->createInstance($id)->getInputDefinition(OperationInputs::VALUES);
    $this->assertFalse($values->getPropertyDefinition('loan')->isLocked());
    foreach (['loan', 'source', 'acquired'] as $key) {
      $this->assertStringContainsString('Whether this may be changed is decided once the thing it belongs to is known', (string) $values->getPropertyDefinition($key)->getDescription(), $key);
    }
    $this->assertStringNotContainsString('decided once', (string) $values->getPropertyDefinition('title')->getDescription());
    $schema = $this->container->get('tool.definition_serializer')->normalizeInputSchema($tools->createInstance($id));
    $this->assertStringContainsString('decided once the thing it belongs to is known', $schema['properties']['values']['properties']['loan']['description']);
  }

  /**
   * Tests the read tool reads what the caller may see, by either kind.
   */
  public function testTheReadToolLeavesOutWhatIsLeftOut(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $read = function (): array {
      $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:resource_test.exhibit:configure:read');
      $this->assertTrue($tool->access());
      $tool->execute();
      return $tool->getOutputValue(OperationInputs::VALUES);
    };
    $this->actAs('inspector');
    $this->assertSame(self::EXHIBIT['source'], $read()['source'], 'Locked is seen.');
    $this->actAs('archivist');
    $this->assertSame(['title', 'status', 'room'], array_keys($read()), 'Left out by the account alone.');
    $this->actAs('keeper');
    $this->container->get('state')->set(ExhibitAccess::WITHDRAWN, TRUE);
    $this->assertSame(['title', 'status', 'room'], array_keys($read()), 'Left out once the thing is known.');
    $this->actAs('stranger');
    $this->assertFalse($tools->createInstance('surface:resource_test.exhibit:configure:read')->access(), 'Reading asks what writing asks: the static permission.');
  }

  /**
   * Tests the full schema's static marks hold for every situation.
   */
  public function testTheStaticMarksAgreeWithTheBuild(): void {
    $state = $this->container->get('state');
    $situations = [
      'as installed' => function () use ($state): void {
        $this->seal('open');
        $state->set(ExhibitAccess::WITHDRAWN, FALSE);
        $state->set(InsuranceAccess::LAPSED, FALSE);
      },
      'sealed' => fn () => $this->seal(),
      'withdrawn' => function () use ($state): void {
        $this->seal('open');
        $state->set(ExhibitAccess::WITHDRAWN, TRUE);
      },
      'the insurance lapsed' => function () use ($state): void {
        $state->set(ExhibitAccess::WITHDRAWN, FALSE);
        $state->set(InsuranceAccess::LAPSED, TRUE);
      },
    ];
    $accounts = array_intersect_key($this->accounts, array_flip(['inspector', 'keeper', 'archivist']));
    $this->assertGreaterThan(30, $this->assertStaticAccessAgrees(ExhibitResource::class, 'configure', $accounts, $situations));

    // The journal's access, all of it by permission: static throughout.
    $accounts = [
      'inspecting the ownership' => $this->createUser(['administer site configuration', 'inspect ownership']),
      'hiding the archive' => $this->createUser(['administer site configuration', 'hide archive', 'inspect ownership']),
    ];
    $this->assertGreaterThan(10, $this->assertStaticAccessAgrees(JournalResource::class, 'configure', $accounts, ['as installed' => static fn () => NULL]));
  }

}
