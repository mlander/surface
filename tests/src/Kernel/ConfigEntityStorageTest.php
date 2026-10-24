<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_storage_test\Resource\ConfigTestResource;
use Drupal\data_surface_storage_test\Storage\ConfigTestStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the config entity storage, through a resource that names it.
 *
 * A config_test entity, by its id: added under an id the values carry and
 * refused under one taken, edited key by key with the rest kept, held to
 * the entity's config schema with each violation under the key that owns
 * it, its third party settings where core keeps them, a setter for a
 * property that needs one, a dry run that leaves the stored entity and
 * the one a caller holds alone, and removed.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ConfigEntityStorageTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'config_test',
    'data_surface',
    'data_surface_storage_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  /**
   * Submits values to one operation of the test resource.
   *
   * @param string $operation
   *   The operation.
   * @param array $values
   *   The values.
   * @param array $parameters
   *   Its parameters.
   * @param bool $dry_run
   *   Whether to stop after prepare.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result.
   */
  protected function submit(string $operation, array $values, array $parameters = [], bool $dry_run = FALSE) {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(ConfigTestResource::class, $operation, $parameters);
    $built = $resources->build(ConfigTestResource::class, $context);
    return $this->pipeline()->submit($built, $values, $resources->storage(ConfigTestResource::class, $context, $built), $dry_run);
  }

  /**
   * Loads a test entity afresh.
   *
   * @param string $id
   *   Its id.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   It, or NULL.
   */
  protected function stored(string $id): ?ConfigEntityInterface {
    $entity = $this->container->get('entity_type.manager')->getStorage('config_test')->loadUnchanged($id);
    return $entity instanceof ConfigEntityInterface ? $entity : NULL;
  }

  /**
   * Tests the map a subclass names: properties, a setter and the id.
   */
  public function testTheMapIsReadOffTheSubclass(): void {
    $this->assertSame([
      'id' => 'id',
      'label' => 'label',
      'weight' => 'weight',
      'style' => 'style',
      'protected_property' => [ConfigTestStorage::METHOD => 'setProtectedProperty'],
    ], ConfigTestStorage::properties());
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(ConfigTestStorage::class);
    $this->assertSame('id', $storage->idKey());
    $this->assertSame('config_test.dynamic.*', $storage->configSchemaType());
    $this->assertNull($storage->mayClear(new OperationContext('update'), 'label'), 'The test entity is not fully validatable, so the storage does not know.');
  }

  /**
   * Tests adding, then editing, then a second add under the same id.
   */
  public function testAddEditAndTakenId(): void {
    $added = $this->submit('create', [
      'id' => 'one',
      'label' => 'First',
      'weight' => 3,
      'style' => 'wide',
      'protected_property' => 'set',
    ]);
    $this->assertTrue($added->isValid());
    $this->assertTrue($added->committed);
    $entity = $this->stored('one');
    $this->assertSame('First', $entity->label());
    $this->assertSame(3, $entity->get('weight'));
    $this->assertSame('wide', $entity->get('style'));
    $this->assertSame('set', $entity->get('protected_property'), 'Set through the entity\'s own setter.');
    $this->assertSame($entity->uuid(), $added->prepared->artifact['uuid'], 'What was rehearsed is what is stored, uuid included.');

    $edited = $this->submit('update', ['weight' => 9], ['id' => $entity]);
    $this->assertTrue($edited->committed);
    $entity = $this->stored('one');
    $this->assertSame(9, $entity->get('weight'));
    $this->assertSame('First', $entity->label(), 'A key left out keeps what it holds.');

    $again = $this->submit('create', ['id' => 'one', 'label' => 'Again'], dry_run: TRUE);
    $this->assertFalse($again->isValid());
    $this->assertSame('id', $again->violations->keys()[0]);
    $this->assertSame(Violation::TAKEN, $again->violations->byKey('id')[0]->code);
    $this->assertSame('The machine name one is already in use: choose another.', (string) $again->violations->byKey('id')[0]->message);
  }

  /**
   * Tests a schema violation is filed under the key, nothing written.
   */
  public function testTheSchemaRefusesUnderTheKey(): void {
    // The resource states the schema's rule too, so the pipeline refuses
    // first; storage is asked directly for what it holds the entity to.
    $this->assertSame(['label'], $this->submit('create', ['id' => 'two', 'label' => "Two\nlines"])->violations->keys());
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(ConfigTestStorage::class);
    try {
      $storage->prepare(new OperationContext('create', TRUE), ['id' => 'two', 'label' => "Two\nlines"], new AnonymousUserSession());
      $this->fail('The schema refuses a label on two lines.');
    }
    catch (StorageViolationsException $e) {
      $this->assertSame(['label'], $e->getViolations()->keys());
      $this->assertSame('', $e->getViolations()->byKey('label')[0]->path);
    }
    $this->assertNull($this->stored('two'));
  }

  /**
   * Tests a dry run leaves the stored entity, and the caller's, alone.
   */
  public function testDryRunWritesNothing(): void {
    $this->submit('create', ['id' => 'three', 'label' => 'Kept', 'weight' => 7]);
    $held = $this->container->get('entity_type.manager')->getStorage('config_test')->load('three');
    $result = $this->submit('update', ['label' => 'Rehearsed', 'weight' => 42], ['id' => $held], TRUE);
    $this->assertTrue($result->isValid());
    $this->assertFalse($result->committed);
    $this->assertSame('Rehearsed', $result->prepared->artifact['label']);
    $this->assertSame('Kept', $held->label(), 'The entity the request holds is never shaped.');
    $this->assertSame(7, $this->stored('three')->get('weight'));
  }

  /**
   * Tests the third party settings are written and read where core keeps them.
   */
  public function testThirdPartySettings(): void {
    $this->submit('create', ['id' => 'four', 'label' => 'Mounted']);
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(ConfigTestStorage::class);
    $context = new OperationContext('update', known: ['id' => 'four']);
    $prepared = $storage->prepare($context, [
      'label' => 'Mounted',
      'third_party_settings' => ['data_surface_storage_test' => ['note' => 'Written by a third party']],
    ], new AnonymousUserSession());
    $this->assertSame(['data_surface_storage_test' => ['note' => 'Written by a third party']], $prepared['third_party_settings']);
    $this->assertSame(['module' => ['data_surface_storage_test']], $prepared['dependencies'], 'The dependencies a save would calculate.');
    $storage->commit($context, $prepared, new AnonymousUserSession());
    $this->assertSame('Written by a third party', $this->stored('four')->getThirdPartySetting('data_surface_storage_test', 'note'));
    $this->assertSame(['data_surface_storage_test' => ['note' => 'Written by a third party']], $storage->load($context)['third_party_settings']);

    // A setting no schema describes is refused under the mount.
    try {
      $storage->prepare($context, ['third_party_settings' => ['data_surface_storage_test' => ['note' => ['not' => 'a string']]]], new AnonymousUserSession());
      $this->fail('A setting of the wrong type is refused.');
    }
    catch (StorageViolationsException $e) {
      $this->assertSame(['third_party_settings'], $e->getViolations()->keys());
    }
  }

  /**
   * Tests removing: rehearsed, then done, then nothing to remove.
   */
  public function testRemove(): void {
    $this->submit('create', ['id' => 'five', 'label' => 'Going']);
    $entity = $this->stored('five');
    $rehearsed = $this->submit('delete', [], ['id' => $entity], TRUE);
    $this->assertTrue($rehearsed->isValid());
    $this->assertFalse($rehearsed->committed);
    $this->assertSame([''], $rehearsed->changes, 'The whole thing goes: the empty pointer.');
    $this->assertSame('Going', $rehearsed->values['label'], 'The values it holds are answered.');
    $this->assertNotNull($this->stored('five'));

    $sent = $this->submit('delete', ['label' => 'Ignored'], ['id' => $entity]);
    $this->assertFalse($sent->isValid(), 'A delete takes no values.');
    $this->assertSame(Violation::UNKNOWN_KEY, $sent->violations->byKey('label')[0]->code);
    $this->assertNotNull($this->stored('five'));

    $removed = $this->submit('delete', [], ['id' => $entity]);
    $this->assertTrue($removed->committed);
    $this->assertTrue($removed->changed());
    $this->assertNull($this->stored('five'));

    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(ConfigTestStorage::class);
    try {
      $storage->prepareDelete(new OperationContext('delete', known: ['id' => 'five']), new AnonymousUserSession());
      $this->fail('Nothing stored is nothing to remove.');
    }
    catch (StorageViolationsException $e) {
      $this->assertSame(Violation::NOT_FOUND, iterator_to_array($e->getViolations())[0]->code);
    }
  }

}
