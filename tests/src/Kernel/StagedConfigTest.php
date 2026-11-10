<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\Development\ConfigSchemaChecker;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Schema\SchemaIncompleteException;
use Drupal\Core\Routing\RouteMatch;
use Drupal\surface\Form\CompiledFlow;
use Drupal\surface\Http\ServedOperationAccessCheck;
use Drupal\surface\Pipeline\StorageViolationsException;
use Drupal\surface\Pipeline\TransactionalStorageInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\StorageAdapter;
use Drupal\surface\Staging\ConfigWrites;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\Stageable;
use Drupal\surface\Staging\StagedStorage;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\surface_tool_test\Storage\VaultStorage;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;

/**
 * Tests config in a stage, and the one rule of where a stage applies.
 *
 * A config object and a config entity are staged as any thing is: read
 * through the stage, listed in `changes`, applied in position order. A
 * config object has no id, so `changes` lists it with no parameters and
 * `applied` with no `id`. When an apply rolls back, the config factory,
 * the cached storage, the `cache.config` bin and the entity storage
 * answer what the database holds again. Two resources over one config
 * object are one thing. An operation that names no thing, a venue
 * reorder, is not staged, and the HTTP door answers a stage header on a
 * call a stage does not apply to with 422. The tool door, the OpenAPI
 * document, the HTTP door and the staged storage's prepare agree on
 * every operation of the test site. An apply a schema change voided
 * marks the stage voided, and a config name is recorded before core's
 * own listeners can throw.
 *
 * @see docs/decisions.md#config-is-staged-and-apply-resets-what-its-rollback-leaves-behind
 * @see docs/decisions.md#one-rule-says-where-a-stage-applies-at-every-door
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StagedConfigTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use ServedControllerTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The config entity resource.
   */
  protected const ENTITY = 'storage_test.config_test';

  /**
   * The operations with a tool and no OpenAPI call, sorted.
   */
  protected const NOT_SERVED = [];

  /**
   * The writes whose build throws before prepare is reached, sorted.
   */
  protected const UNREACHED = [];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::ENTITY_MODULES,
    'config_test',
    'surface_storage_test',
    'surface_stage_test',
    'surface_tool_test',
    'surface_examples',
    'surface_examples_keys',
    'surface_examples_node_type',
    'my_module',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->installConfig(['surface', 'my_module', 'surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Creates a config_test entity for real.
   *
   * @param string $id
   *   Its id.
   * @param string $label
   *   Its label.
   */
  protected function entity(string $id, string $label): void {
    [$built, $storage] = $this->permanent(self::ENTITY, 'create');
    $result = $this->pipeline()->submit($built, ['id' => $id, 'label' => $label], $storage);
    $this->assertTrue($result->committed, $this->said($result));
  }

  /**
   * Loads a config_test entity through its entity storage.
   *
   * @param string $id
   *   Its id.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   It, or NULL.
   */
  protected function load(string $id): ?ConfigEntityInterface {
    $entity = $this->container->get('entity_type.manager')->getStorage('config_test')->load($id);
    return $entity instanceof ConfigEntityInterface ? $entity : NULL;
  }

  /**
   * Lists the config_test entities, as a listing loads them.
   *
   * @return string[]
   *   Their ids, sorted.
   */
  protected function listed(): array {
    $ids = array_keys($this->container->get('entity_type.manager')->getStorage('config_test')->loadMultiple());
    sort($ids);
    return array_map('strval', $ids);
  }

  /**
   * Tests a config object is staged, read through the stage and applied.
   */
  public function testConfigObjectIsStagedAndApplied(): void {
    $id = $this->open();
    $result = $this->stage($id, 'my_module.settings', [], ['title' => 'Staged title'], 'configure');
    $this->assertTrue($result->committed, $this->said($result));
    $this->assertSame(['title'], $result->changes);
    $this->assertSame('Latest content', $this->config('my_module.settings')->get('title'), 'Nothing is written.');

    // Read through the stage, the staged view; without it, what is stored.
    [$built, $storage] = $this->staged($id, 'my_module.settings', 'configure');
    $this->assertSame('Staged title', $storage->load($built)['title']);
    $this->assertSame(10, $storage->load($built)['items_per_page'], 'A key the stage did not move is what is stored.');
    [$built, $storage] = $this->permanent('my_module.settings', 'configure');
    $this->assertSame('Latest content', $storage->load($built)['title']);

    // A config object has no id: no parameters.
    $changes = $this->stageTool('changes', $id);
    $this->assertTrue($changes['success'], $changes['message']);
    $item = $changes['outputs']['items'][0];
    $described = [$item['resource'], $item['operation'], $item['kind']];
    $this->assertSame(['my_module.settings', 'configure', 'update'], $described);
    $this->assertSame([[], ['/title']], [$item['parameters'], $item['changed']]);

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('Staged title', $this->config('my_module.settings')->get('title'));
    $this->assertSame([], $applied['outputs']['applied'][0]['parameters']);
    $this->assertTrue($applied['outputs']['applied'][0]['changed']);
    $this->assertArrayNotHasKey('id', $applied['outputs']['applied'][0], 'A thing with no id is answered with none.');
  }

  /**
   * Tests the apply's ETag carries the stage's writes over HTTP.
   *
   * The confirm page's fingerprint, over HTTP: the envelope of `apply` is
   * tagged with what the stage holds, so a write staged after it was read
   * moves the tag, and `PATCH :apply` with the old `If-Match` is 412,
   * nothing written; with the new one it applies.
   */
  public function testApplyEtagCarriesTheStagedWrites(): void {
    $this->entity('kept', 'Kept');
    $id = $this->open();
    $this->assertTrue($this->stage($id, 'my_module.settings', [], ['title' => 'Staged title'], 'configure')->committed);
    $apply = '/surface-api/surface.stage/' . $id . ':apply';
    $read = $this->served('GET', $apply);
    $this->assertSame(200, $read->getStatusCode(), (string) $read->getContent());
    $drawn = (string) $read->getEtag();
    $this->assertNotSame('', $drawn);
    $this->assertSame($drawn, (string) $this->served('GET', $apply)->getEtag(), 'Nothing staged since: the same tag.');

    $this->assertTrue($this->stage($id, self::ENTITY, ['id' => 'kept'], ['label' => 'Renamed'], 'update')->committed);
    $moved = (string) $this->served('GET', $apply)->getEtag();
    $this->assertNotSame($drawn, $moved, 'A write staged since moves the tag.');

    $stale = $this->served('PATCH', $apply, [], ['If-Match' => $drawn]);
    $this->assertSame(412, $stale->getStatusCode(), (string) $stale->getContent());
    $this->assertSame(Violation::CONFLICT, json_decode((string) $stale->getContent(), TRUE)['errors'][0]['code']);
    $this->assertSame('Latest content', $this->config('my_module.settings')->get('title'), 'Nothing is written.');
    $this->assertSame('Kept', $this->load('kept')?->label());

    $applied = $this->served('PATCH', $apply, [], ['If-Match' => $moved]);
    $this->assertSame(200, $applied->getStatusCode(), (string) $applied->getContent());
    $this->assertCount(2, json_decode((string) $applied->getContent(), TRUE)['outputs']['applied']);
    $this->assertSame('Staged title', $this->config('my_module.settings')->get('title'));
    $this->assertSame('Renamed', $this->load('kept')?->label());
  }

  /**
   * Tests config entities are created, updated and deleted in a stage.
   *
   * A staged create named by its caller, an update of it by that name,
   * which merges into the create, an update of a stored one and a delete
   * of another: apply makes them in position order.
   */
  public function testConfigEntityWritesApplyInPositionOrder(): void {
    $this->entity('kept', 'Kept');
    $this->entity('gone', 'Gone');
    $id = $this->open();

    [$built, $storage] = $this->through($id, self::ENTITY, 'create');
    $created = $this->pipeline()->submit($built, ['id' => 'fresh', 'label' => 'Fresh'], $storage);
    $this->assertTrue($created->committed, $this->said($created));
    $this->assertInstanceOf(StagedStorage::class, $storage);
    $this->assertSame(['id' => 'fresh'], $storage->address(), 'The name the caller gave is its local id.');
    [$built, $storage] = $this->through($id, self::ENTITY, 'update', ['id' => 'fresh']);
    $merged = $this->pipeline()->submit($built, ['label' => 'Fresh, renamed'], $storage);
    $this->assertTrue($merged->committed, $this->said($merged));
    $this->assertNull($this->load('fresh'), 'Nothing is created until the stage is applied.');

    $updated = $this->stage($id, self::ENTITY, ['id' => 'kept'], ['label' => 'Kept, moved']);
    $this->assertTrue($updated->committed, $this->said($updated));
    $deleted = $this->stage($id, self::ENTITY, ['id' => 'gone'], [], 'delete');
    $this->assertTrue($deleted->committed, $this->said($deleted));
    $this->assertSame('Kept', $this->load('kept')?->label());
    $this->assertNotNull($this->load('gone'));

    $changes = $this->stageTool('changes', $id)['outputs']['items'];
    $kinds = array_map(static fn (array $item): array => [$item['position'], $item['kind']], $changes);
    $this->assertSame([[1, 'create'], [2, 'update'], [3, 'delete']], $kinds);
    $this->assertSame([['name' => 'id', 'value' => 'fresh']], $changes[0]['parameters']);

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame([1, 2, 3], array_column($applied['outputs']['applied'], 'position'));
    $this->assertSame(['fresh', 'kept', 'gone'], array_column($applied['outputs']['applied'], 'id'));
    $this->assertSame('Fresh, renamed', $this->load('fresh')?->label());
    $this->assertSame('Kept, moved', $this->load('kept')?->label());
    $this->assertNull($this->load('gone'));
  }

  /**
   * Tests a rolled back apply leaves config as the database holds it.
   *
   * A config object write, two config entity writes and two content type
   * writes (a create, and an update of the page's title label), then a
   * ledger row whose commit fails: the transaction rolls the config rows
   * back, and apply resets what config kept beside them. The config
   * factory, a fresh read of the config storage, the entity storage and
   * its listing, and the node bundle info answer the stored values in the
   * same request. The test fails without each of three resets: the
   * `cache.config` delete and the config factory reset (the factory then
   * serves the staged title) and the cached bundles (the bundle info then
   * lists the rolled back content type). Four are not pinned, since no
   * staged resource of the test site leaves a stale copy for them: the
   * fast backend's reset (a kernel test's bin is a memory backend), the
   * entity memory cache (config entities are not statically cached), the
   * field definitions (the override's save clears them and nothing in the
   * apply builds them again before the rollback), and State's cache (no
   * staged write writes State). A config entity's uuid lookup, which the
   * kernel keeps in a memory key-value store, is not asked: no core API
   * rebuilds it.
   */
  public function testRollbackResetsWhatConfigKeptBesideTheDatabase(): void {
    $this->ledger('a', 'Alpha');
    $this->entity('kept', 'Kept');
    $id = $this->open();
    $this->assertTrue($this->stage($id, 'my_module.settings', [], ['title' => 'Rolled back'], 'configure')->committed);
    $this->assertTrue($this->stage($id, self::ENTITY, ['id' => 'kept'], ['label' => 'Moved'])->committed);
    [$built, $storage] = $this->through($id, self::ENTITY, 'create');
    $this->assertTrue($this->pipeline()->submit($built, ['id' => 'fresh', 'label' => 'Fresh'], $storage)->committed);
    [$built, $storage] = $this->through($id, 'node.type', 'create');
    $type = ['name' => 'Staged type', 'type' => 'staged_type', 'title_label' => 'Staged title'];
    $this->assertTrue($this->pipeline()->submit($built, $type, $storage)->committed);
    $this->assertTrue($this->stage($id, 'node.type', ['type' => 'page'], ['title_label' => 'Moved title'])->committed);
    $this->assertTrue($this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['title' => 'Alpha moved'])->committed);
    $record = $this->stages()->read($id);

    // What a request has read before the apply is in every cache.
    $this->assertSame('Latest content', \Drupal::config('my_module.settings')->get('title'));
    $this->assertSame('Kept', $this->load('kept')?->label());
    $this->assertSame(['kept'], $this->listed());
    $bundles = $this->container->get('entity_type.bundle.info');
    $fields = $this->container->get('entity_field.manager');
    $this->assertArrayNotHasKey('staged_type', $bundles->getBundleInfo('node'));
    $this->assertSame('Title', (string) $fields->getFieldDefinitions('node', 'page')['title']->getLabel());

    $this->container->get('state')->set(LedgerStorage::FAIL, 'a');
    [$built, $storage] = $this->permanent('surface.stage', 'apply', ['id' => $id]);
    try {
      $this->pipeline()->submit($built, [], $storage);
      $this->fail('The ledger write fails.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('The ledger row "a" could not be written.', $e->getMessage());
    }

    $this->assertSame('Latest content', \Drupal::config('my_module.settings')->get('title'), 'The config factory answers what is stored.');
    $this->assertSame('Latest content', $this->container->get('config.storage')->read('my_module.settings')['title'], 'The config storage answers what is stored.');
    $this->assertSame('Kept', $this->load('kept')?->label(), 'The entity answers what is stored.');
    $this->assertNull($this->load('fresh'), 'The create was rolled back.');
    $this->assertSame(['kept'], $this->listed(), 'The listing answers what is stored.');
    $this->assertArrayNotHasKey('staged_type', $bundles->getBundleInfo('node'), 'The bundle info answers what is stored.');
    $this->assertSame('Title', (string) $fields->getFieldDefinitions('node', 'page')['title']->getLabel(), 'The field definitions answer what is stored.');
    $this->assertSame('Alpha', $this->row('a')['title']);
    $this->assertSame($record, $this->stages()->read($id), 'The stage is open and intact.');
  }

  /**
   * Tests an apply voided by a schema change marks the stage voided.
   *
   * A config object write, a ledger row whose commit voids the
   * transaction as a schema change does on MySQL, and a ledger row whose
   * commit fails: the writes at and before the failing position are
   * permanent, the stage is voided at that position with no writes and
   * `applied` the two that completed, apply and a staged write are
   * refused, naming the position, and discard removes it. The config
   * caches were reset, so the factory serves what is stored, which is the
   * permanent write.
   */
  public function testVoidedApplyMarksTheStageVoided(): void {
    $this->ledger('v', 'Void');
    $this->ledger('c', 'Charlie');
    $id = $this->open();
    $this->assertTrue($this->stage($id, 'my_module.settings', [], ['title' => 'Made permanent'], 'configure')->committed);
    $this->assertTrue($this->stage($id, 'stage_test.ledger', ['id' => 'v'], ['title' => 'Void moved'])->committed);
    $this->assertTrue($this->stage($id, 'stage_test.ledger', ['id' => 'c'], ['title' => 'Charlie moved'])->committed);
    $this->assertSame('Latest content', \Drupal::config('my_module.settings')->get('title'));

    $this->container->get('state')->set(LedgerStorage::VOID, 'v');
    $this->container->get('state')->set(LedgerStorage::FAIL, 'c');
    [$built, $storage] = $this->permanent('surface.stage', 'apply', ['id' => $id]);
    try {
      $this->pipeline()->submit($built, [], $storage);
      $this->fail('The ledger write fails.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Writes at and before position 3 were made permanent by a schema change and could not be rolled back; the stage is voided. Discard it. The write at position 3 failed: The ledger row "c" could not be written.', $e->getMessage());
      $this->assertSame('The ledger row "c" could not be written.', $e->getPrevious()?->getMessage());
    }
    $this->assertFalse($this->container->get('database')->inTransaction());

    $record = $this->stages()->read($id);
    $this->assertSame(['voided', ['position' => 3], []], [$record['status'], $record['voided'], $record['writes']]);
    $this->assertSame([1, 2], array_column($record['applied'], 'position'));
    $this->assertSame('Made permanent', \Drupal::config('my_module.settings')->get('title'), 'The factory serves the permanent write.');
    $this->assertSame('Made permanent', $this->container->get('config.storage')->read('my_module.settings')['title']);
    $this->assertSame('Void moved', $this->row('v')['title']);
    $this->assertSame('Charlie', $this->row('c')['title']);
    $this->container->get('state')->delete(LedgerStorage::VOID);
    $this->container->get('state')->delete(LedgerStorage::FAIL);

    // The stage's apply reads what was made, as an applied one does.
    $loaded = $this->container->get(StageStorage::class)->load(new OperationContext('apply', known: ['id' => $id]));
    $this->assertSame($record['applied'], $loaded['applied']);
    [$built, $storage] = $this->permanent('surface.stage', 'apply', ['id' => $id]);

    $said = sprintf(' (does_not_apply): The stage %s was voided at position 3; writes at and before it were made permanent by a schema change. Discard the stage.', $id);
    $again = $this->pipeline()->submit($built, [], $storage);
    $this->assertFalse($again->committed);
    $this->assertSame(['' => Violation::DOES_NOT_APPLY], static::codes($again), $this->said($again));
    $this->assertSame($said, $this->said($again));
    $write = $this->stage($id, 'stage_test.ledger', ['id' => 'c'], ['title' => 'Charlie again']);
    $this->assertFalse($write->committed);
    $this->assertSame(['' => Violation::DOES_NOT_APPLY], static::codes($write), $this->said($write));
    $this->assertSame($said, $this->said($write));
    $this->assertSame($record, $this->stages()->read($id), 'The voided stage holds what it held.');

    $discarded = $this->stageTool('delete', $id);
    $this->assertTrue($discarded['success'], $discarded['message']);
    $this->assertNull($this->stages()->read($id));
  }

  /**
   * Tests an apply voided by a schema change, every write succeeding.
   *
   * Every write is permanent already: the stage is applied with no
   * commit, which would only warn, and no PHP warning escapes.
   */
  public function testVoidedApplyThatSucceedsIsApplied(): void {
    $this->ledger('v', 'Void');
    $id = $this->open();
    $this->assertTrue($this->stage($id, 'my_module.settings', [], ['title' => 'Applied'], 'configure')->committed);
    $this->assertTrue($this->stage($id, 'stage_test.ledger', ['id' => 'v'], ['title' => 'Void moved'])->committed);
    $this->container->get('state')->set(LedgerStorage::VOID, 'v');

    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
      $warnings[] = $message;
      return TRUE;
    }, E_WARNING | E_USER_WARNING);
    try {
      [$built, $storage] = $this->permanent('surface.stage', 'apply', ['id' => $id]);
      $result = $this->pipeline()->submit($built, [], $storage);
    }
    finally {
      restore_error_handler();
    }
    $this->assertTrue($result->committed, $this->said($result));
    $this->assertSame([], $warnings);
    $this->assertFalse($this->container->get('database')->inTransaction());
    $record = $this->stages()->read($id);
    $this->assertSame('applied', $record['status']);
    $this->assertSame([1, 2], array_column($record['applied'], 'position'));
    $this->assertSame('Applied', \Drupal::config('my_module.settings')->get('title'));
    $this->assertSame('Void moved', $this->row('v')['title']);
  }

  /**
   * Tests a config name is recorded before core's listeners can throw.
   *
   * Config::save() writes the storage and the cache before it dispatches
   * SAVE, and the schema checker a kernel test runs throws after that
   * for a key the schema does not declare. The pipeline validates every
   * staged write first, so the save is made here directly, recording on,
   * inside a transaction as apply makes it: the name is recorded, and
   * after the rollback and the reset neither the factory nor the
   * `cache.config` bin serves the rolled back value. The priority is
   * pinned against the checker's too.
   */
  public function testConfigNameIsRecordedBeforeTheSchemaCheckerThrows(): void {
    $priority = ConfigSchemaChecker::getSubscribedEvents()[ConfigEvents::SAVE][0][1];
    foreach (ConfigWrites::getSubscribedEvents() as $listener) {
      $this->assertGreaterThan($priority, $listener[1]);
    }

    $this->assertSame('Latest content', \Drupal::config('my_module.settings')->get('title'));
    $writes = $this->container->get('surface.config_writes');
    assert($writes instanceof ConfigWrites);
    $transaction = $this->container->get('database')->startTransaction();
    $writes->start();
    try {
      $this->container->get('config.factory')->getEditable('my_module.settings')->set('title', 'Rolled back')->set('undeclared', 'x')->save();
      $this->fail('The schema checker throws.');
    }
    catch (SchemaIncompleteException) {
    }
    finally {
      $writes->stop();
    }
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame(['my_module.settings'], $writes->names());
    $writes->reset();
    $this->assertSame('Latest content', \Drupal::config('my_module.settings')->get('title'));
    $cached = $this->container->get('cache.config')->get('my_module.settings');
    $this->assertTrue($cached === FALSE || $cached->data['title'] === 'Latest content', 'The bin serves no rolled back value.');
  }

  /**
   * Tests a config object holding a secret is staged, and read without it.
   *
   * The webhook's pasted key is sealed in the record, the read through
   * the stage leaves it out, as every read does, and a dry run of apply
   * rehearses the write: the keys module's mount, which nothing stored
   * holds yet, is not sent, since staging did not move it.
   */
  public function testSecretBearingConfigObjectIsStaged(): void {
    $id = $this->open();
    $result = $this->stage($id, 'event.webhook', [], ['api_key' => 'pasted-key'], 'configure');
    $this->assertTrue($result->committed, $this->said($result));
    $entry = $this->stages()->read($id)['writes'][0];
    $this->assertSame([['api_key']], $entry['sealed']);
    $this->assertSame(['api_key'], $entry['changed'], 'An empty mount the stored object leaves out did not move.');
    $this->assertStringNotContainsString('pasted-key', serialize($entry));

    $read = $this->runTool('surface:event.webhook:read', [OperationInputs::STAGE => $id]);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertArrayNotHasKey('api_key', $read['outputs'][OperationInputs::VALUES]);

    $dry = $this->stageTool('apply', $id, TRUE);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertNull($this->config('surface_examples.webhook')->get('api_key'), 'A dry run writes nothing.');
    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('pasted-key', $this->config('surface_examples.webhook')->get('api_key'), 'Apply opens the seal; the config object keeps the key as it always does.');
  }

  /**
   * Tests two resources over one config object are one thing.
   *
   * The catering's two resources store one config object through one
   * storage class, and neither knows identity: the second is refused,
   * naming the first, in either order.
   */
  public function testTwoResourcesOverOneConfigObjectAreOneThing(): void {
    $values = [
      'provider' => 'Harbour Kitchen',
      'headcount' => 150,
      'contact' => ['name' => 'Sam Ortiz', 'email' => 'catering@example.com'],
    ];
    $orders = [
      ['event.catering', 'event.catering.method'],
      ['event.catering.method', 'event.catering'],
    ];
    foreach ($orders as [$before, $after]) {
      $id = $this->open();
      $first = $this->stage($id, $before, [], $values, 'configure');
      $this->assertTrue($first->committed, $this->said($first));
      $record = $this->stages()->read($id);
      $second = $this->stage($id, $after, [], ['headcount' => 90] + $values, 'configure');
      $this->assertFalse($second->committed);
      $this->assertSame(['' => Violation::CONFLICT], static::codes($second), $this->said($second));
      $this->assertSame(sprintf(' (conflict): This is staged through %s already; write it through that resource, or discard the stage.', $before), $this->said($second));
      $this->assertSame($record, $this->stages()->read($id), 'The stage holds what it held.');
    }
  }

  /**
   * Tests an operation that names no thing is not staged, at any door.
   *
   * The venues' reorder writes several venues, and its context knows no
   * id: its tool takes no stage, and a write named into a stage anyway is
   * refused at prepare, naming the rule. The examples ship the venue it
   * reorders.
   */
  public function testOperationThatNamesNoThingIsRefused(): void {
    $tools = $this->container->get('plugin.manager.tool');
    $this->assertArrayNotHasKey(OperationInputs::STAGE, $tools->getDefinition('surface:event.venue:reorder')->getInputDefinitions(TRUE));
    $this->assertArrayHasKey(OperationInputs::STAGE, $tools->getDefinition('surface:event.venue:update')->getInputDefinitions(TRUE));

    $id = $this->open();
    $result = $this->stage($id, 'event.venue', [], ['venues' => [['id' => 'riverside', 'weight' => 3]]], 'reorder');
    $this->assertFalse($result->committed);
    $this->assertSame(['' => Violation::DOES_NOT_APPLY], static::codes($result), $this->said($result));
    $this->assertSame(' (does_not_apply): event.venue cannot be staged through the reorder operation: it names no thing, since it creates nothing and its parameters name none of the identity keys (id).', $this->said($result));
    $this->assertSame([], $this->stages()->read($id)['writes']);

    // A stage handed to the tool anyway, as an adapter that passes
    // undeclared inputs through would: refused, never written for real.
    $tool = $tools->createInstance('surface:event.venue:reorder');
    $inputs = [OperationInputs::STAGE => $id, OperationInputs::VALUES => ['venues' => []]];
    $staged = (fn (array $values): mixed => $this->staged($values))->call($tool, $inputs);
    $this->assertInstanceOf(ExecutableResult::class, $staged);
    $this->assertFalse($staged->isSuccess());
    $this->assertSame(FailureCategory::Input, $staged->getFailureCategory());
    $this->assertSame('A stage does not apply to this operation.', (string) $staged->getMessage());
    try {
      (fn (array $values): mixed => $this->stage($values, []))->call($tool, $inputs);
      $this->fail('A stage the tool does not take is refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('A stage does not apply to this operation: its tool takes no stage input.', $e->getMessage());
    }
  }

  /**
   * Tests a write is refused where a routed child cannot be staged.
   *
   * No shipped resource routes a child under a storage a stage takes, so
   * the ledger's storage is composed here with a child kept in State, as
   * the build composes a routed child: the staged write is refused,
   * naming the child, before anything is rehearsed. The check is
   * prepare's alone: Stageable, which the doors ask, reads no child, so
   * such a resource is offered the stage and refused at the write.
   */
  public function testRoutedChildThatCannotBeStagedIsRefused(): void {
    $this->ledger('a', 'Alpha');
    $id = $this->open();
    $context = $this->resources()->operation('stage_test.ledger', 'update', ['id' => 'a']);
    $built = $this->resources()->build('stage_test.ledger', $context);
    $resolver = $this->container->get('class_resolver');
    $child = new StorageAdapter($resolver->getInstanceFromDefinition(VaultStorage::class), new OperationContext('configure'));
    $adapter = new StorageAdapter($resolver->getInstanceFromDefinition(LedgerStorage::class), $context, ['vault' => [StorageAdapter::ATTACHED => $child]], ['id']);
    $staged = new StagedStorage($adapter, $this->stages()->named($id), $this->stages(), 'stage_test.ledger', $context, LedgerStorage::class, ['id'], ['id'], $this->container->get('surface.secret_codec'));
    try {
      $staged->prepare($built, ['title' => 'Alpha moved']);
      $this->fail('The child cannot be staged.');
    }
    catch (StorageViolationsException $e) {
      $violations = iterator_to_array($e->getViolations());
      $this->assertCount(1, $violations);
      $this->assertSame([Violation::DOES_NOT_APPLY, ''], [$violations[0]->code, $violations[0]->fullPath()]);
      $this->assertSame(sprintf('stage_test.ledger cannot be staged: its child at vault is stored by %s, which does not declare that a database transaction rolls its writes back whole (%s).', VaultStorage::class, TransactionalStorageInterface::class), (string) $violations[0]->message);
    }
  }

  /**
   * Tests the HTTP door answers a stage header on a call it cannot stage.
   *
   * 422, one violation at no path, code `does_not_apply`, for a read and a
   * write alike: a State resource's envelope, and the venues' reorder,
   * also for a stage that names nothing; another account's stage is
   * refused first, 403, by the route's access check. A config object's
   * envelope reads through the stage.
   */
  public function testHeaderOnCallNotStagedIsUnprocessable(): void {
    $id = $this->open();
    $refusal = [
      ['path' => '', 'code' => Violation::DOES_NOT_APPLY, 'message' => 'A stage does not apply to this operation.'],
    ];
    $read = $this->served('GET', '/surface-api/tool_test.vault', NULL, ['X-Surface-Stage' => $id]);
    $this->assertSame(422, $read->getStatusCode(), (string) $read->getContent());
    $this->assertSame($refusal, json_decode((string) $read->getContent(), TRUE)['errors']);

    // The order: another account's stage is refused by the route's
    // access check first, 403, on a call a stage does not apply to too;
    // a stage that names nothing is let through, and the call answers 422.
    $admin = $this->container->get('current_user')->getAccount();
    $this->setCurrentUser($this->createUser());
    $foreign = $this->open();
    $this->setCurrentUser($admin);
    $this->assertSame([FALSE, TRUE], $this->routeAccess('tool_test.vault', $foreign));
    $this->assertSame([TRUE, FALSE], $this->routeAccess('tool_test.vault', 'no-such-stage'));
    $missing = $this->served('GET', '/surface-api/tool_test.vault', NULL, ['X-Surface-Stage' => 'no-such-stage']);
    $this->assertSame(422, $missing->getStatusCode(), (string) $missing->getContent());
    $this->assertSame($refusal, json_decode((string) $missing->getContent(), TRUE)['errors']);

    $paths = $this->container->get('surface.served_paths');
    $registry = $this->container->get('surface.resource_registry');
    $venue = $registry->getDefinition('event.venue');
    $reorder = $registry->getOperation('event.venue', 'reorder');
    [$method, $named] = $paths->writes($venue, $reorder);
    $url = $paths->url($venue, $reorder, [], $named, FALSE)->getGeneratedUrl();
    $write = $this->served($method, $url, ['venues' => []], ['X-Surface-Stage' => $id]);
    $this->assertSame(422, $write->getStatusCode(), (string) $write->getContent());
    $this->assertSame($refusal, json_decode((string) $write->getContent(), TRUE)['errors']);
    $this->assertSame([], $this->stages()->read($id)['writes']);

    $this->assertTrue($this->stage($id, 'my_module.settings', [], ['title' => 'Staged title'], 'configure')->committed);
    $staged = $this->served('GET', '/surface-api/my_module.settings', NULL, ['X-Surface-Stage' => $id]);
    $this->assertSame(200, $staged->getStatusCode(), (string) $staged->getContent());
    $this->assertSame('Staged title', json_decode((string) $staged->getContent(), TRUE)['values']['title']);
  }

  /**
   * Answers the API route's access check for a GET with a stage header.
   *
   * @param string $resource
   *   The resource id, whose path names no parameter.
   * @param string $stage
   *   The header's value.
   *
   * @return array{0: bool, 1: bool}
   *   Whether the check allows the request, and whether it forbids it.
   */
  protected function routeAccess(string $resource, string $stage): array {
    $check = $this->container->get('surface.served_access');
    assert($check instanceof ServedOperationAccessCheck);
    $ids = ['resource' => $resource, 's1' => '', 's2' => '', 's3' => '', 's4' => '', 's5' => ''];
    $route_match = new RouteMatch('surface.api', new Route('/surface-api/{resource}/{s1}/{s2}/{s3}/{s4}/{s5}'), $ids, $ids);
    $request = Request::create('/surface-api/' . $resource, 'GET', [], [], [], ['HTTP_X_SURFACE_STAGE' => $stage]);
    $result = $check->access($route_match, $this->container->get('current_user'), $request);
    return [$result->isAllowed(), $result->isForbidden()];
  }

  /**
   * Tests the doors agree on where a stage applies, for every operation.
   *
   * Stageable::operation() is the rule, asked at each place that takes a
   * stage: the tool's input (OperationInputs), the OpenAPI document, the
   * HTTP door (ResourceApiController), the operation form and a React
   * page served at a module's route (OperationRoute::stageRequested()),
   * the React page's access check (ServedOperations::stageable()), the
   * flow's own check (CompiledFlow::refusal()) and the staged storage's prepare
   * (Stageable::storage(), Stageable::namesThing()). For each operation
   * of the site with a tool, each is asked on its own terms but the flow,
   * which FlowFormTest covers:
   *
   * - the tool, and its schema tool, take `stage` exactly where the rule
   *   says;
   * - the OpenAPI calls reference `SurfaceStage` exactly where it says,
   *   but the full schema, which reads nothing stored;
   * - for each one that writes, the staged storage the build step makes
   *   (ResourcesInterface::storage() with a stage, which reads the
   *   storage instance's class and the registry's parameters) refuses
   *   prepare `does_not_apply`, naming the rule, exactly where the rule
   *   says no; its context is the one the operation builds with no
   *   arguments, or, for an operation that takes some, the context the
   *   build stamps with the operation's id and its creates, which is all
   *   prepare reads of it;
   * - for each OpenAPI call but the full schema, which reads nothing
   *   stored and ignores the header at a stored id, a request with the
   *   stage header is refused with the stage refusal exactly where the
   *   rule says no; where it says yes, any other answer stands (a path
   *   parameter of 1 may name nothing, a body of `{}` may be refused).
   *   The stage's own calls answer with their own problem, never the
   *   stage refusal;
   * - the React page, `surface_examples_react.page`, asked with the stage
   *   in its query, is forbidden `does_not_apply` by its access check
   *   exactly where the rule says no.
   *
   * - for each operation form route of the site, and a React page served
   *   at a module's route, a request with the stage in its query is
   *   refused `does_not_apply` by the route's access check exactly where
   *   the rule says no; any other route an operation serves refuses any
   *   stage, a controller of another module named
   *   ResourceReactPage::served among them.
   *
   * Every operation with a tool has an OpenAPI call but those listed in
   * NOT_SERVED, and every write reaches prepare but those in UNREACHED;
   * both lists are empty, and a new entry in either fails the test.
   */
  public function testDoorsAgreeOnWhereStageApplies(): void {
    $this->container->get('router.builder')->rebuild();
    $document = $this->container->get('surface.openapi_document')->build(Request::create('https://example.com/surface-api/openapi.json'))->document;
    $document = json_decode((string) json_encode($document, JSON_THROW_ON_ERROR), TRUE);
    $calls = [];
    foreach ($document['paths'] as $path => $item) {
      foreach ($item as $method => $call) {
        if (!is_array($call) || !isset($call['operationId'])) {
          continue;
        }
        $operation = substr((string) $call['operationId'], 0, (int) strrpos((string) $call['operationId'], ':'));
        $calls[$operation][] = [
          'method' => strtoupper((string) $method),
          'path' => (string) $path,
          'headed' => in_array(['$ref' => '#/components/parameters/SurfaceStage'], $call['parameters'] ?? [], TRUE),
        ];
      }
    }
    $refusal = [
      ['path' => '', 'code' => Violation::DOES_NOT_APPLY, 'message' => 'A stage does not apply to this operation.'],
    ];
    // One stage for prepare, which nothing is written into, and one for
    // the requests, which may stage what they send.
    $id = $this->open();
    $stage = $this->stages()->named($id);
    $headed = $this->open();
    $tools = $this->container->get('plugin.manager.tool');
    $registry = $this->container->get('surface.resource_registry');
    $compared = 0;
    $staged = 0;
    $prepared = 0;
    $requested = 0;
    $reacted = 0;
    $unserved = [];
    $unreached = [];
    foreach ($registry->getDefinitions() as $definition) {
      if (!$definition->isResource() || $definition->storage === NULL) {
        continue;
      }
      foreach ($registry->getOperations($definition->name()) as $operation) {
        $tool = 'surface:' . $definition->id . ':' . $operation->id;
        $key = $definition->id . ':' . $operation->id;
        if (!$tools->hasDefinition($tool)) {
          continue;
        }
        $compared++;
        $rule = Stageable::operation($definition, $operation);
        $staged += (int) $rule;
        $this->assertSame($rule, array_key_exists(OperationInputs::STAGE, $tools->getDefinition($tool)->getInputDefinitions(TRUE)), $tool . ': the tool takes the stage where the rule says.');
        if ($tools->hasDefinition($tool . ':schema')) {
          $this->assertSame($rule, array_key_exists(OperationInputs::STAGE, $tools->getDefinition($tool . ':schema')->getInputDefinitions(TRUE)), $tool . ':schema');
        }

        $this->assertSame(!$rule, $this->reactPageRefuses($definition->id, $operation->id, $headed), $key . ': the React page refuses the stage query exactly where the rule says no.');
        $reacted++;

        if (!$operation->readOnly) {
          $refused = $this->refusedByTheRule($definition->name(), $operation->id, $operation->creates, $stage);
          if ($refused === NULL) {
            $unreached[] = $key;
          }
          else {
            $this->assertSame(!$rule, $refused, $key . ': the staged storage refuses by the rule exactly where the doors offer no stage.');
            $prepared++;
          }
        }

        if (!isset($calls[$key])) {
          $unserved[] = $key;
          continue;
        }
        foreach ($calls[$key] as $call) {
          // The full schema reads nothing stored, so no call carries the
          // header there.
          $schema = $call['method'] === 'GET' && str_ends_with($call['path'], '/schema');
          $this->assertSame($rule && !$schema, $call['headed'], $call['method'] . ' ' . $call['path'] . ': the OpenAPI call references the header where the rule says.');
          $url = preg_replace('/\{[^}]+\}/', '1', $call['path']);
          $body = in_array($call['method'], ['POST', 'PUT', 'PATCH'], TRUE) ? [] : NULL;
          $response = $this->served($call['method'], $url, $body, ['X-Surface-Stage' => $headed]);
          $answer = json_decode((string) $response->getContent(), TRUE);
          $stageRefused = $response->getStatusCode() === 422 && ($answer['errors'] ?? NULL) === $refusal;
          // A stage is never staged: its own calls answer the header with
          // their own problem, 400, before the rule is asked.
          if ($definition->id === StageResource::ID) {
            $this->assertFalse($stageRefused, $call['method'] . ' ' . $url);
            $requested++;
            continue;
          }
          $this->assertSame(!$rule && !$schema, $stageRefused, sprintf('%s %s: the HTTP door refuses the header exactly where the rule says no (%d).', $call['method'], $url, $response->getStatusCode()));
          $requested++;
        }
      }
    }
    // The form door: each operation form route, asked with the stage in
    // its query, refuses it `does_not_apply` exactly where the rule says
    // no, before anything of its path is read, and so does a React page
    // served at a module's route. Every other route served by an
    // operation refuses any stage: only the operation form and the React
    // page read the query. A React page and a controller are added, built
    // by hand, to the routes the site serves.
    $check = $this->container->get('surface.operation_access');
    $forms = 0;
    $formsStaged = 0;
    $others = 0;
    $reactStaged = FALSE;
    $otherReactRefused = FALSE;
    $routes = iterator_to_array($this->container->get('router.route_provider')->getAllRoutes());
    $by = [
      OperationRoute::RESOURCE => 'Drupal\\surface_examples\\Resource\\RegistrationStep1Resource',
      OperationRoute::OPERATION => 'configure',
    ];
    $routes['test.react_page'] = new Route('/test/react', $by + ['_controller' => '\Drupal\surface_examples_react\Controller\ResourceReactPage::served']);
    $routes['test.controller'] = new Route('/test/controller', $by + ['_controller' => '\Drupal\surface_examples\Controller\ExamplesController::index']);
    // A controller of another module with the React page's short name is
    // not the React page.
    $routes['test.other_react_page'] = new Route('/test/other-react', $by + ['_controller' => '\Drupal\other\Controller\ResourceReactPage::served']);
    foreach ($routes as $name => $route) {
      if (!OperationRoute::serves($route)) {
        continue;
      }
      if (!OperationRoute::operationForm($route) && !OperationRoute::reactPage($route)) {
        $answer = $check->access(new RouteMatch((string) $name, $route), $this->container->get('current_user'), Request::create('/', 'GET', ['stage' => $headed]));
        $this->assertTrue($answer->isForbidden(), $name);
        $this->assertSame('A stage does not apply to this page: only an operation form or a React page reads the "stage" query.', $answer instanceof AccessResultReasonInterface ? $answer->getReason() : NULL, $name);
        $others++;
        $otherReactRefused = $otherReactRefused || $name === 'test.other_react_page';
        continue;
      }
      $resource = (string) $route->getDefault(OperationRoute::RESOURCE);
      $raw = [];
      foreach ($route->compile()->getVariables() as $variable) {
        $raw[$variable] = str_contains($resource, '{' . $variable . '}') ? 'page' : '1';
      }
      $route_match = new RouteMatch((string) $name, $route, $raw, $raw);
      [$named, $served] = OperationRoute::named($route_match, $registry);
      $definition = $registry->getDefinition($named);
      $rule = Stageable::operation($definition, $registry->getOperation($definition->name(), $served));
      $answer = $check->access($route_match, $this->container->get('current_user'), Request::create('/', 'GET', ['stage' => $headed]));
      $reason = $answer instanceof AccessResultReasonInterface ? (string) $answer->getReason() : '';
      $this->assertSame(!$rule, $answer->isForbidden() && str_contains($reason, '(' . Violation::DOES_NOT_APPLY . ')'), $name . ': the form door refuses the stage exactly where the rule says no.');
      $forms++;
      $formsStaged += (int) $rule;
      if ($name === 'test.react_page') {
        $reactStaged = $answer->isAllowed();
      }
    }
    $this->assertTrue($reactStaged, 'The React page takes a stage where the rule says yes.');
    $this->assertTrue($otherReactRefused, 'A controller of another module named ResourceReactPage::served is refused the stage.');
    $this->assertGreaterThanOrEqual(2, $others);
    $this->assertGreaterThan(10, $forms);
    $this->assertGreaterThan(5, $formsStaged);
    $this->assertLessThan($forms, $formsStaged);
    sort($unserved);
    sort($unreached);
    $this->assertSame(self::UNREACHED, $unreached, 'Every write reaches prepare, but these.');
    $this->assertSame(self::NOT_SERVED, $unserved, 'Every operation with a tool has an OpenAPI call, but these.');
    $this->assertSame([], $this->stages()->read($id)['writes'], 'Prepare stages nothing.');
    $this->assertGreaterThan(20, $compared);
    $this->assertGreaterThan(10, $staged);
    $this->assertLessThan($compared, $staged);
    $this->assertGreaterThan(10, $prepared);
    $this->assertGreaterThan(20, $requested);
    $this->assertSame($compared, $reacted);
  }

  /**
   * Answers whether the React page refuses a stage by the rule.
   *
   * Asks the access check of surface_examples_react.page,
   * `/surface-react/{resource}/{operation}`, for a GET with the stage in
   * its query and no other parameter.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param string $stage
   *   The stage's id.
   *
   * @return bool
   *   TRUE when the check forbids the page `does_not_apply`; FALSE for
   *   any other answer, a page not found among them.
   */
  protected function reactPageRefuses(string $resource, string $operation, string $stage): bool {
    $check = $this->container->get('surface.served_access');
    assert($check instanceof ServedOperationAccessCheck);
    $raw = ['resource' => $resource, 'operation' => $operation];
    $route_match = new RouteMatch('surface_examples_react.page', new Route('/surface-react/{resource}/{operation}'), $raw, $raw);
    $request = Request::create('/surface-react/' . $resource . '/' . $operation, 'GET', [CompiledFlow::STAGE => $stage]);
    try {
      $answer = $check->access($route_match, $this->container->get('current_user'), $request);
    }
    catch (NotFoundHttpException) {
      return FALSE;
    }
    $reason = $answer instanceof AccessResultReasonInterface ? (string) $answer->getReason() : '';
    return $answer->isForbidden() && str_contains($reason, '(' . Violation::DOES_NOT_APPLY . ')');
  }

  /**
   * Answers whether the staged storage refuses a write by the rule.
   *
   * @param string $resource
   *   The resource.
   * @param string $operation
   *   The operation, one that writes.
   * @param bool $creates
   *   Whether it creates, as its attribute says.
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   *
   * @return bool|null
   *   TRUE when prepare refuses `does_not_apply` at no path, saying the
   *   resource cannot be staged; NULL when the build or the storage
   *   throws first, so prepare is not reached.
   */
  protected function refusedByTheRule(string $resource, string $operation, bool $creates, Stage $stage): ?bool {
    try {
      $context = $this->resources()->operation($resource, $operation, [], $stage);
    }
    catch (\InvalidArgumentException) {
      $context = (new OperationContext())->stamped($operation, $creates);
    }
    try {
      $built = $this->resources()->build($resource, $context);
      $storage = $this->resources()->storage($resource, $context, $built, NULL, $stage);
    }
    catch (\Throwable) {
      return NULL;
    }
    $this->assertInstanceOf(StagedStorage::class, $storage);
    try {
      $storage->prepare($built, []);
    }
    catch (StorageViolationsException $e) {
      $violations = iterator_to_array($e->getViolations(), FALSE);
      return count($violations) === 1
        && $violations[0]->code === Violation::DOES_NOT_APPLY
        && $violations[0]->fullPath() === ''
        && str_contains((string) $violations[0]->message, ' cannot be staged');
    }
    // What the storage's own prepare throws past the rule.
    catch (\Throwable) {
    }
    return FALSE;
  }

}
