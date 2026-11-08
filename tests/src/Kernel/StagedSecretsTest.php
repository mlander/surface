<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Staging\StagedSecrets;
use Drupal\surface\Staging\StageStore;
use Drupal\surface\Storage\SodiumSecretCodec;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_stage_test\Storage\VaultNoteStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a secret in a stage: sealed in the record, opened by its path.
 *
 * The vault note's token is sealed with the site's codec before the
 * stage's record is written, and the entry records the path it sealed;
 * the read through the stage answers no token, as every read does; a
 * later write that sends none keeps the staged one, and apply writes it.
 * A cleared token is recorded as cleared, and apply clears it; a secret
 * removed with its parent is no clear. `changes` names the token's path
 * and a dry run answers `changed` as a boolean, and neither carries its
 * value. A codec that cannot open the record, as after the hash salt
 * changed, is refused, naming the discard.
 *
 * The compare a write that merges into a staged create makes is not
 * pinned for a secret: the vault note has no create.
 *
 * @see docs/decisions.md#a-secret-is-sealed-in-the-stage-record-by-its-recorded-paths
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StagedSecretsTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The note resource.
   */
  protected const NOTE = 'stage_test.vault_note';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_stage_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Writes into the note through a stage.
   *
   * @param string $stage
   *   The stage's id.
   * @param array $values
   *   The values.
   */
  protected function note(string $stage, array $values): void {
    $result = $this->stage($stage, self::NOTE, [], $values, 'configure');
    $this->assertTrue($result->committed, $this->said($result));
  }

  /**
   * Reads the stage's record as the key-value collection holds it.
   *
   * @param string $stage
   *   The stage's id.
   *
   * @return array
   *   The raw record.
   */
  protected function raw(string $stage): array {
    $raw = $this->container->get('keyvalue.expirable')->get(StageStore::COLLECTION)->get($stage);
    $this->assertIsArray($raw);
    return $raw;
  }

  /**
   * Reads the note as stored.
   *
   * @return array
   *   The note.
   */
  protected function stored(): array {
    $note = $this->container->get('keyvalue.database')->get(VaultNoteStorage::COLLECTION)->get('note');
    return is_array($note) ? $note : [];
  }

  /**
   * Tests the token is sealed in the record, and a read answers none.
   */
  public function testTheSecretIsSealedInTheRecord(): void {
    $id = $this->open();
    $this->note($id, ['label' => 'Note', 'token' => 'shh']);
    $entry = $this->raw($id)['writes'][0];
    $this->assertStringStartsWith('surface:sodium:v1:', $entry['values']['token']);
    $this->assertStringNotContainsString('shh', serialize($this->raw($id)), 'The record holds no secret in the clear.');
    $this->assertSame([['token']], $entry['sealed']);
    $this->assertArrayNotHasKey('cleared', $entry);
    $this->assertSame('Note', $entry['values']['label'], 'A key that is not secret is kept as it is.');

    // The staged storage opens it; the read tool leaves it out.
    [$built, $storage] = $this->staged($id, self::NOTE, 'configure');
    $this->assertSame('shh', $storage->load($built)['token']);
    $read = $this->runTool('surface:' . self::NOTE . ':read', [OperationInputs::STAGE => $id]);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame(['label' => 'Note'], $read['outputs'][OperationInputs::VALUES]);
  }

  /**
   * Tests a write that sends no token keeps the staged one, to apply.
   */
  public function testLaterWriteKeepsTheStagedSecret(): void {
    $id = $this->open();
    $this->note($id, ['label' => 'Note', 'token' => 'shh']);
    $this->note($id, ['label' => 'Renamed']);
    $entry = $this->raw($id)['writes'][0];
    $this->assertSame([['token']], $entry['sealed']);
    $this->assertStringStartsWith('surface:sodium:v1:', $entry['values']['token']);
    $this->assertSame([], $this->stored(), 'Nothing is written.');

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame(['label' => 'Renamed', 'token' => 'shh'], $this->stored());
  }

  /**
   * Tests a cleared token is recorded as cleared, and apply clears it.
   */
  public function testClearedSecretIsClearedAtApply(): void {
    [$built, $storage] = $this->permanent(self::NOTE, 'configure');
    $this->assertTrue($this->pipeline()->submit($built, ['label' => 'Note', 'token' => 'old'], $storage)->committed);
    $id = $this->open();
    $this->note($id, ['token' => SurfacePipelineInterface::CLEAR_SECRET]);
    $entry = $this->raw($id)['writes'][0];
    $this->assertSame([['token']], $entry['cleared']);
    $this->assertArrayNotHasKey('sealed', $entry, 'Nothing is left to seal.');
    $this->assertNull($entry['values']['token'] ?? NULL);

    // A later write that sends no token leaves it cleared.
    $this->note($id, ['label' => 'Renamed']);
    $this->assertSame([['token']], $this->raw($id)['writes'][0]['cleared']);
    $this->assertSame('old', $this->stored()['token']);

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('Renamed', $this->stored()['label']);
    $this->assertNull($this->stored()['token'] ?? NULL, 'The clear survives apply.');
  }

  /**
   * Tests a token set again after a clear is no longer cleared.
   */
  public function testSecretSetAgainIsNotCleared(): void {
    [$built, $storage] = $this->permanent(self::NOTE, 'configure');
    $this->assertTrue($this->pipeline()->submit($built, ['label' => 'Note', 'token' => 'old'], $storage)->committed);
    $id = $this->open();
    $this->note($id, ['token' => SurfacePipelineInterface::CLEAR_SECRET]);
    $this->note($id, ['token' => 'new']);
    $entry = $this->raw($id)['writes'][0];
    $this->assertArrayNotHasKey('cleared', $entry);
    $this->assertSame([['token']], $entry['sealed']);
    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('new', $this->stored()['token']);
  }

  /**
   * Tests `changes` names the token's path, and neither carries its value.
   *
   * The dry run answers whether the write changed what is stored, as a
   * boolean; only `changes` lists the path.
   */
  public function testChangesAndDryRunNameThePathOnly(): void {
    $id = $this->open();
    $this->note($id, ['label' => 'Note', 'token' => 'shh']);
    $changes = $this->stageTool('changes', $id);
    $this->assertTrue($changes['success'], $changes['message']);
    $this->assertSame(['/label', '/token'], $changes['outputs']['items'][0]['changed']);
    $this->assertStringNotContainsString('shh', serialize($changes));
    $this->assertStringNotContainsString('surface:sodium:v1:', serialize($changes));

    $dry = $this->stageTool('apply', $id, TRUE);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertTrue($dry['outputs']['applied'][0]['changed']);
    $this->assertStringNotContainsString('shh', serialize($dry));
    $this->assertSame([], $this->stored(), 'A dry run writes nothing.');
  }

  /**
   * Tests a secret removed with its parent is no clear.
   *
   * A map set to NULL, a list of maps that shrank and a scalar where a map
   * was each remove the secret below them: cleared() records nothing, and
   * clearing() builds no parent again. A secret present and empty is a
   * clear.
   */
  public function testSecretRemovedWithItsParentIsNoClear(): void {
    $secrets = ['backup' => ['password' => TRUE], 'mirrors' => ['@each' => ['password' => TRUE]]];
    $current = [
      'backup' => ['host' => 'b.example.com', 'password' => 'pw'],
      'mirrors' => [
        ['host' => 'm0', 'password' => 'p0'],
        ['host' => 'm1', 'password' => 'p1'],
        ['host' => 'm2', 'password' => 'p2'],
      ],
    ];

    // A map set to NULL.
    $values = ['backup' => NULL] + $current;
    $this->assertSame([], StagedSecrets::cleared($values, $current, $secrets));
    $this->assertNull(StagedSecrets::clearing(['backup' => NULL], [['backup', 'password']])['backup']);

    // A list of maps shrunk from three to two.
    $values = ['mirrors' => array_slice($current['mirrors'], 0, 2)] + $current;
    $this->assertSame([], StagedSecrets::cleared($values, $current, $secrets));
    $input = StagedSecrets::clearing(['mirrors' => $values['mirrors']], [['mirrors', '2', 'password']]);
    $this->assertSame($values['mirrors'], $input['mirrors'], 'The two items are what apply sends.');

    // A scalar where the map was.
    $values = ['backup' => 'none'] + $current;
    $this->assertSame([], StagedSecrets::cleared($values, $current, $secrets));
    $this->assertSame(['backup' => 'none'], StagedSecrets::clearing(['backup' => 'none'], [['backup', 'password']]));
    $this->assertSame([], StagedSecrets::stillCleared([['backup', 'password']], [], $values));

    // The leaf present and empty.
    $values = ['backup' => ['host' => 'b.example.com', 'password' => NULL]] + $current;
    $this->assertSame([['backup', 'password']], StagedSecrets::cleared($values, $current, $secrets));
    $input = StagedSecrets::clearing(['backup' => $values['backup']], [['backup', 'password']]);
    $this->assertSame(SurfacePipelineInterface::CLEAR_SECRET, $input['backup']['password']);
  }

  /**
   * Tests a record the codec cannot open is refused, naming the discard.
   *
   * A codec from another hash salt stands for the site's after its hash
   * salt changed. A read through the stage leaves the sealed token out,
   * as every read does; a staged write and apply are refused
   * `does_not_apply` at no path, and the stage must be discarded.
   */
  public function testCodecThatCannotOpenTheRecordIsRefused(): void {
    $id = $this->open();
    $this->note($id, ['label' => 'Note', 'token' => 'shh']);
    // The stage's storage is a service, built with the codec of its
    // request: a request after the hash salt changed builds it again.
    $this->container->set('surface.secret_codec', new SodiumSecretCodec('another hash salt, at least long enough'));
    $this->container->set(StageStorage::class, StageStorage::create($this->container));
    [$built, $storage] = $this->staged($id, self::NOTE, 'configure');
    $this->assertSame(['label' => 'Note'], $storage->load($built), 'What cannot be opened is left out.');

    $said = sprintf(' (does_not_apply): The stage %s was sealed under another hash salt and cannot be opened; discard it.', $id);
    $write = $this->stage($id, self::NOTE, [], ['label' => 'Renamed'], 'configure');
    $this->assertFalse($write->committed);
    $this->assertSame(['' => Violation::DOES_NOT_APPLY], static::codes($write), $this->said($write));
    $this->assertSame($said, $this->said($write));

    [$built, $storage] = $this->permanent('surface.stage', 'apply', ['id' => $id]);
    $applied = $this->pipeline()->submit($built, [], $storage);
    $this->assertFalse($applied->committed);
    $this->assertSame(['' => Violation::DOES_NOT_APPLY], static::codes($applied), $this->said($applied));
    $this->assertSame($said, $this->said($applied));
    $this->assertSame([], $this->stored(), 'Nothing is written.');
  }

}
