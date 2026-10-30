<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\PreparedValues;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_examples_workflow\Alter\FeesWorkflowAlter;
use Drupal\surface_react\Access\ServedOperationAccessCheck;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Plugin\tool\Tool\ResourceOperationToolBase;
use Drupal\surface_tool_test\Storage\VaultStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests that every door projects what the read tool projects.
 *
 * The security findings of the reviews (2026-10-20), each reproduced:
 * B1, a dry run handed back the stored values of keys access left out,
 * through `prepared`; B2, the write tool and React `/validate` echoed
 * stored secrets; B3, a write sending a collection erased each item's
 * stored secret; B4, the React door (and the tool door) loaded the
 * subject before asking the permission and said in its refusal whether
 * an id exists; B26, a child permission nothing could name was not
 * asked. And the static listing: the cached tool definitions were the
 * discovering account's.
 *
 * @see docs/decisions.md#every-door-projects-what-the-read-tool-projects
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class SecurityProjectionTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'entity_test',
    'serialization',
    'tool',
    'surface',
    'surface_examples_node_type',
    'surface_examples_field',
    'surface_examples',
    'surface_examples_workflow',
    'surface_react',
    'surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['field', 'node', 'surface_examples']);
    // The first account is the super user; every test acts as another.
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Runs a tool as the current account.
   *
   * @param string $id
   *   The tool's plugin id.
   * @param array $inputs
   *   Its inputs, by name.
   *
   * @return array{success: bool, message: string, outputs: array}
   *   Whether it succeeded, its message, and its outputs.
   */
  protected function runTool(string $id, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => (string) $result->getMessage(),
      'outputs' => $result->getContextValues(),
    ];
  }

  /**
   * Runs a dry write on the API, as the current account.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param array $values
   *   The values sent.
   *
   * @return array
   *   The decoded answer.
   */
  protected function validate(string $resource, string $operation, array $values): array {
    return $this->httpDryRun($resource, $operation, $values);
  }

  /**
   * Asks the React route's access check, as an account.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param array $query
   *   The query string: the operation's parameters.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account; NULL for the anonymous one.
   *
   * @return array{0: bool, 1: string}
   *   Whether it is allowed, and the reason given.
   */
  protected function reactAccess(string $resource, string $operation, array $query, ?AccountInterface $account = NULL): array {
    $check = $this->container->get('surface_react.access');
    assert($check instanceof ServedOperationAccessCheck);
    $ids = ['resource' => $resource, 'operation' => $operation];
    $route_match = new RouteMatch('surface_react.page', new Route('/surface-react/{resource}/{operation}'), $ids, $ids);
    $result = $check->access($route_match, $account ?? User::getAnonymousUser(), Request::create('/surface-react/' . $resource . '/' . $operation, 'GET', $query));
    return [$result->isAllowed(), $result instanceof AccessResultReasonInterface ? (string) $result->getReason() : ''];
  }

  /**
   * Tests a dry run answers no key access left out (B1).
   *
   * Example 10's workflow leaves the fees out for an account that may not
   * see them; the program stores them whole, so storage's rehearsal held
   * them, and the doors served it as `prepared`.
   */
  public function testNoDoorAnswersKeyLeftOut(): void {
    $this->setUpCurrentUser([], ['administer site configuration']);
    $this->assertFalse($this->container->get('current_user')->hasPermission(FeesWorkflowAlter::PERMISSION));

    $dry = $this->runTool('surface:event.program:configure', [
      OperationInputs::VALUES => [],
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertArrayNotHasKey('prepared', $dry['outputs']);
    $this->assertArrayNotHasKey('fees', $dry['outputs'][OperationInputs::VALUES]);
    $this->assertStringNotContainsString('concession', (string) json_encode($dry['outputs']));

    $read = $this->runTool('surface:event.program:configure:read', []);
    $this->assertSame($read['outputs'][OperationInputs::VALUES], $dry['outputs'][OperationInputs::VALUES], 'The dry run answers what the read answers.');

    $validated = $this->validate('event.program', 'configure', []);
    $this->assertTrue($validated['ok']);
    $this->assertSame(['ok', 'committed', 'changed', 'changes', 'values', 'violations', 'notes', 'stale'], array_slice(array_keys($validated), 0, 8));
    $this->assertArrayNotHasKey('fees', $validated['values']);
    $this->assertStringNotContainsString('concession', (string) json_encode($validated));

    // Written, as well: the fees are kept as stored, and not answered.
    $written = $this->runTool('surface:event.program:configure', [OperationInputs::VALUES => ['sessions' => 9]]);
    $this->assertTrue($written['success'], $written['message']);
    $this->assertArrayNotHasKey('fees', $written['outputs'][OperationInputs::VALUES]);
    $this->assertSame(['standard' => 20.0, 'concession' => 10.0], array_map('floatval', $this->config('surface_examples.program')->get('fees')));
  }

  /**
   * Tests no door echoes a stored secret (B2).
   *
   * At the top, in an attached child and in each item of a collection,
   * on a write, a dry run and React's /validate.
   */
  public function testNoDoorEchoesSecret(): void {
    $this->setUpCurrentUser([], ['administer site configuration']);
    $values = [
      'endpoint' => 'https://vault.example.com',
      'token' => 'top-secret-token',
      'backup' => ['host' => 'backup.example.com', 'password' => 'hunter2'],
      'mirrors' => [
        ['host' => 'east.example.com', 'password' => 'mirror-one'],
        ['host' => 'west.example.com', 'password' => 'mirror-two'],
      ],
    ];
    $written = $this->runTool('surface:tool_test.vault:configure', [OperationInputs::VALUES => $values]);
    $this->assertTrue($written['success'], $written['message']);
    $dry = $this->runTool('surface:tool_test.vault:configure', [
      OperationInputs::VALUES => ['endpoint' => 'https://other.example.com'],
      OperationInputs::DRY_RUN => TRUE,
    ]);
    $this->assertTrue($dry['success'], $dry['message']);
    $validated = $this->validate('tool_test.vault', 'configure', ['endpoint' => 'https://other.example.com']);
    $this->assertTrue($validated['ok']);

    foreach (['written' => $written['outputs'], 'dry run' => $dry['outputs'], 'validate' => $validated] as $door => $answer) {
      $encoded = (string) json_encode($answer);
      foreach (['top-secret-token', 'hunter2', 'mirror-one', 'mirror-two'] as $secret) {
        $this->assertStringNotContainsString($secret, $encoded, $door . ' does not echo ' . $secret);
      }
    }
    $this->assertSame([
      'endpoint' => 'https://other.example.com',
      'backup' => ['host' => 'backup.example.com'],
      'mirrors' => [['host' => 'east.example.com'], ['host' => 'west.example.com']],
    ], $dry['outputs'][OperationInputs::VALUES]);
    // Storage still holds every one of them: left out of the answer, not
    // out of what was written.
    $stored = $this->container->get('state')->get(VaultStorage::KEY);
    $this->assertSame('top-secret-token', $stored['token']);
    $this->assertSame('hunter2', $stored['backup']['password']);
  }

  /**
   * Tests a collection item keeps its stored secret, by position (B3).
   *
   * The read tool leaves the item's secret out, so a caller sending back
   * what it read, one host renamed, sends no secret; that kept the
   * child's default before, erasing it. Sent as long as stored, an item
   * keeps what is stored at its position; with an item added or removed
   * a position may hold another item, so the write is refused by the
   * secret's path, unless the secret is sent again or cleared.
   */
  public function testCollectionItemKeepsItsSecret(): void {
    $this->setUpCurrentUser([], ['administer site configuration']);
    $this->runTool('surface:tool_test.vault:configure', [
      OperationInputs::VALUES => [
        'mirrors' => [
          ['host' => 'east.example.com', 'password' => 'mirror-one'],
          ['host' => 'west.example.com', 'password' => 'mirror-two'],
        ],
      ],
    ]);
    $read = $this->runTool('surface:tool_test.vault:configure:read', []);
    $mirrors = $read['outputs'][OperationInputs::VALUES]['mirrors'];
    $mirrors[1]['host'] = 'west-2.example.com';

    $edited = $this->runTool('surface:tool_test.vault:configure', [OperationInputs::VALUES => ['mirrors' => $mirrors]]);
    $this->assertTrue($edited['success'], $edited['message']);
    $stored = $this->container->get('state')->get(VaultStorage::KEY)['mirrors'];
    $this->assertSame(['east.example.com', 'west-2.example.com'], array_column($stored, 'host'));
    $this->assertSame(['mirror-one', 'mirror-two'], array_column($stored, 'password'), 'Each item kept the secret stored at its position.');

    // The first removed: the second now sits where the first's secret is.
    $removed = $this->runTool('surface:tool_test.vault:configure', [OperationInputs::VALUES => ['mirrors' => [['host' => 'west-2.example.com']]]]);
    $this->assertFalse($removed['success']);
    $this->assertStringContainsString('/mirrors/0/password: Password is stored for the item at this position', $removed['message']);
    $this->assertSame(['mirror-one', 'mirror-two'], array_column($this->container->get('state')->get(VaultStorage::KEY)['mirrors'], 'password'), 'Nothing was written.');

    // Sent again, or cleared, it is said.
    $again = $this->runTool('surface:tool_test.vault:configure', [
      OperationInputs::VALUES => ['mirrors' => [['host' => 'west-2.example.com', 'password' => 'mirror-two']]],
    ]);
    $this->assertTrue($again['success'], $again['message']);
    $this->assertSame([['host' => 'west-2.example.com', 'password' => 'mirror-two']], $this->container->get('state')->get(VaultStorage::KEY)['mirrors']);
    $added = $this->runTool('surface:tool_test.vault:configure', [
      OperationInputs::VALUES => [
        'mirrors' => [
          ['host' => 'west-2.example.com', 'password' => SurfacePipelineInterface::CLEAR_SECRET],
          ['host' => 'north.example.com'],
        ],
      ],
    ]);
    $this->assertTrue($added['success'], $added['message']);
    $this->assertSame([NULL, ''], array_column($this->container->get('state')->get(VaultStorage::KEY)['mirrors'], 'password'), 'Cleared, and a new item at its default.');
  }

  /**
   * Tests a note says nothing of a secret inside a locked key.
   *
   * A value sent for a locked key is noted when it is not what the key
   * keeps; compared whole, a map holding a secret was noted only for a
   * wrong guess, which told a caller the secret. Anything sent for a
   * secret is not taken, whatever it holds.
   */
  public function testNoteSaysNothingOfSecret(): void {
    $token = DataDefinition::create('string')->setLabel('Token');
    DefinitionMetadata::setSecret($token);
    $builder = new SurfaceBuilder();
    $builder->setDefinition('remote', MapDataDefinition::create()
      ->setLabel('Remote')
      ->setPropertyDefinition('host', DataDefinition::create('string')->setLabel('Host'))
      ->setPropertyDefinition('token', $token));
    $builder->lock('remote');
    $surface = $builder->seal();
    $stored = ['remote' => ['host' => 'a.example.com', 'token' => 'right']];
    $storage = new class ($stored) implements SurfaceStorageInterface {

      /**
       * Constructs the storage, holding what it loads.
       *
       * @param array $stored
       *   What it holds.
       */
      public function __construct(protected array $stored) {}

      /**
       * {@inheritdoc}
       */
      public function load(SurfaceInterface $surface): array {
        return $this->stored;
      }

      /**
       * {@inheritdoc}
       */
      public function prepare(SurfaceInterface $surface, array $values): PreparedValues {
        return new PreparedValues($values, $values);
      }

      /**
       * {@inheritdoc}
       */
      public function commit(PreparedValues $prepared): void {}

    };
    $notes = [];
    foreach (['right', 'wrong'] as $guess) {
      $result = $this->pipeline()->submit($surface, ['remote' => ['host' => 'a.example.com', 'token' => $guess]], $storage, dry_run: TRUE);
      $notes[$guess] = array_map(static fn ($note): string => $note->fullPath() . ': ' . $note->message, $result->notes);
      $this->assertStringNotContainsString($guess, (string) json_encode($this->pipeline()->outbound($surface, $result->values)));
    }
    $this->assertSame(['remote: Remote is locked and kept as stored; the value sent was not taken.'], $notes['right']);
    $this->assertSame($notes['right'], $notes['wrong'], 'A right guess and a wrong one are told the same.');
    $result = $this->pipeline()->submit($surface, ['remote' => ['host' => 'a.example.com']], $storage, dry_run: TRUE);
    $this->assertSame([], $result->notes, 'The secret left out, the map as held: nothing to say.');
  }

  /**
   * Tests the React door asks the permission first and names no id (B4).
   */
  public function testReactDoorAsksThePermissionFirstAndNamesNoId(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    // A permission as written: refused before the parameter is read, so
    // an id that names something and one that names nothing read alike.
    $there = $this->reactAccess('node.type', 'update', ['type' => 'article']);
    $missing = $this->reactAccess('node.type', 'update', ['type' => 'nothing_here']);
    $this->assertFalse($there[0]);
    $this->assertSame($there, $missing);
    $this->assertStringContainsString('administer data surface node type demo', $there[1]);

    // A permission only the subject names: the subject is read, and a
    // refusal says the same whether it was there.
    FieldStorageConfig::create(['field_name' => 'field_note', 'entity_type' => 'entity_test', 'type' => 'string'])->save();
    FieldConfig::create(['field_name' => 'field_note', 'entity_type' => 'entity_test', 'bundle' => 'entity_test'])->save();
    $field = ['entity_type' => 'entity_test', 'bundle' => 'entity_test'];
    $there = $this->reactAccess('field.instance', 'update', $field + ['field_name' => 'field_note']);
    $missing = $this->reactAccess('field.instance', 'update', $field + ['field_name' => 'field_none']);
    $this->assertSame([FALSE, ServedOperationAccessCheck::REFUSED], $there);
    $this->assertSame($there, $missing);

    // The tool door alike.
    $tools = $this->container->get('plugin.manager.tool');
    $reasons = [];
    foreach (['field_note', 'field_none'] as $name) {
      $tool = $tools->createInstance('surface:field.instance:update');
      $tool->setInputValue('entity_type', 'entity_test');
      $tool->setInputValue('bundle', 'entity_test');
      $tool->setInputValue('field_name', $name);
      $tool->setInputValue(OperationInputs::VALUES, []);
      $result = $tool->access(User::getAnonymousUser(), TRUE);
      $this->assertFalse($result->isAllowed());
      $reasons[] = $result instanceof AccessResultReasonInterface ? (string) $result->getReason() : '';
    }
    $this->assertSame([ResourceOperationToolBase::REFUSED, ResourceOperationToolBase::REFUSED], $reasons);

    // An account holding the operation's permission hears why the rest
    // refused what the parameter names, and is let in where it may; a
    // parameter naming nothing is let through, for the page to answer
    // 404, as the API and the operation form's route answer it.
    $holder = $this->createUser(['administer data surface node type demo']);
    $this->assertSame([FALSE, 'The resource this page serves may not be written by this account.'], $this->reactAccess('node.type', 'update', ['type' => 'article'], $holder));
    $this->assertSame([TRUE, ''], $this->reactAccess('node.type', 'update', ['type' => 'nothing_here'], $holder));
    $admin = $this->createUser(['administer data surface node type demo', 'administer content types']);
    $this->assertTrue($this->reactAccess('node.type', 'update', ['type' => 'article'], $admin)[0]);
  }

  /**
   * Tests a child permission nothing can name is refused (B26).
   *
   * The field's storage is added with `administer %entity_type
   * fields`. Built where the context knows no entity type, only a static
   * listing may describe it; any other build leaves it out.
   */
  public function testChildPermissionNothingCanNameIsRefused(): void {
    $resources = $this->container->get('surface.resources');
    $bare = new OperationContext('create');
    $this->assertFalse($resources->build('field.instance', $bare)->getDefinitions()->has('storage'));
    $this->assertTrue($resources->buildStatic('field.instance', $bare)->getDefinitions()->has('storage'), 'A static listing describes it as it may be.');
    $named = $resources->operation('field.instance', 'create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ]);
    $this->assertTrue($resources->build('field.instance', $named)->getDefinitions()->has('storage'));
  }

  /**
   * Tests the cached tool definitions are no account's.
   *
   * They were built for whichever account discovered them, so a consumer
   * reading them without an instance saw that account's view. Built for
   * no account, the fees example 10 leaves out for an account that may
   * not see them are there, marked, whoever discovered them; an instance
   * lists them for the account that asks.
   */
  public function testCachedToolDefinitionsAreNoAccounts(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $seeing = $this->createUser(['administer site configuration', FeesWorkflowAlter::PERMISSION]);
    $blind = $this->createUser(['administer site configuration']);
    $listed = [];
    foreach (['seeing' => $seeing, 'blind' => $blind] as $who => $account) {
      $this->setCurrentUser($account);
      $tools->clearCachedDefinitions();
      $values = $tools->getDefinition('surface:event.program:configure')->getInputDefinition(OperationInputs::VALUES);
      $fees = $values->getPropertyDefinitions()['fees'] ?? NULL;
      $this->assertNotNull($fees, $who . ' discovered the fees, as declared.');
      $this->assertStringContainsString('left out', (string) $fees->getDescription());
      $listed[$who] = serialize($tools->getDefinition('surface:event.program:configure')->getInputDefinitions());
    }
    $this->assertSame($listed['seeing'], $listed['blind'], 'The cached definition is the same whoever discovered it.');

    $schema = $this->container->get('tool.definition_serializer');
    foreach (['seeing' => TRUE, 'blind' => FALSE] as $who => $sees) {
      $this->setCurrentUser($who === 'seeing' ? $seeing : $blind);
      $properties = $schema->normalizeInputSchema($tools->createInstance('surface:event.program:configure'))['properties'][OperationInputs::VALUES]['properties'];
      $this->assertSame($sees, isset($properties['fees']), $who . ': the listing is the account\'s.');
    }
  }

}
