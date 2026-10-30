<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Storage\ConfigObjectStorage;
use Drupal\data_surface_storage_test\Storage\NestedSettingsStorage;
use Drupal\data_surface_storage_test\Storage\PlainSettingsStorage;
use Drupal\data_surface_storage_test\Storage\TranslatableSettingsStorage;
use Drupal\data_surface_storage_test\Storage\MissingSchemaSettingsStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the storage for a simple config object.
 *
 * What the most common resource, a settings page, is stored with: the
 * object read less what the storage owns, the whole object held to its
 * schema in prepare() with every violation filed under the key being
 * written, the language code core asks for kept by the storage, a
 * mounted module's settings refused when no schema describes them, and a
 * key kept under a path read, judged and written there.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ConfigObjectStorageTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'data_surface',
    'data_surface_storage_test',
  ];

  /**
   * Makes one of the fixture storage classes, as autowiring would.
   *
   * @param class-string<\Drupal\data_surface\Storage\ConfigObjectStorage> $class
   *   The storage class.
   *
   * @return \Drupal\data_surface\Storage\ConfigObjectStorage
   *   The storage.
   */
  protected function storage(string $class): ConfigObjectStorage {
    return new $class(
      $this->container->get('config.factory'),
      $this->container->get('config.typed'),
      $this->container->get('language_manager'),
      $this->container->get('string_translation'),
    );
  }

  /**
   * Gets the context a singleton resource is configured in.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   The context.
   */
  protected function context(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * Prepares values and returns what the storage refused.
   *
   * @param \Drupal\data_surface\Storage\ConfigObjectStorage $storage
   *   The storage.
   * @param array $values
   *   The values.
   *
   * @return array<string, string>
   *   Each violation's full path => its message, rendered.
   */
  protected function refusals(ConfigObjectStorage $storage, array $values): array {
    try {
      $storage->prepare($this->context(), $values, new AnonymousUserSession());
    }
    catch (StorageViolationsException $e) {
      $refusals = [];
      foreach ($e->getViolations() as $violation) {
        $refusals[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $refusals;
    }
    return [];
  }

  /**
   * Tests load() reads the object less the keys the storage owns.
   */
  public function testLoadLeavesOutWhatTheStorageOwns(): void {
    $this->config(TranslatableSettingsStorage::CONFIG)->setData([
      '_core' => ['default_config_hash' => 'abc'],
      'langcode' => 'en',
      'greeting' => 'Hello',
      'limit' => 3,
    ])->save();

    $this->assertSame(['greeting' => 'Hello', 'limit' => 3], $this->storage(TranslatableSettingsStorage::class)->load($this->context()));
    $this->assertSame([], $this->storage(PlainSettingsStorage::class)->load($this->context()), 'An object never saved loads as nothing.');
  }

  /**
   * Tests a translatable object is written with a language code.
   */
  public function testTranslatableValuesAreStoredWithTheirLanguageCode(): void {
    $storage = $this->storage(TranslatableSettingsStorage::class);

    $prepared = $storage->prepare($this->context(), ['greeting' => 'Hello', 'limit' => 3], new AnonymousUserSession());
    $this->assertSame(['greeting' => 'Hello', 'limit' => 3, 'langcode' => 'en'], $prepared, 'The site default language, when none is stored.');
    $this->assertTrue($this->config(TranslatableSettingsStorage::CONFIG)->isNew(), 'prepare() writes nothing.');

    $storage->commit($this->context(), $prepared, new AnonymousUserSession());
    $this->assertSame(['langcode' => 'en', 'greeting' => 'Hello', 'limit' => 3], $this->config(TranslatableSettingsStorage::CONFIG)->getRawData(), 'Saved in schema order.');

    $this->config(TranslatableSettingsStorage::CONFIG)->set('langcode', 'fr')->save();
    $this->assertSame('fr', $storage->prepare($this->context(), ['greeting' => 'Bonjour', 'limit' => 3], new AnonymousUserSession())['langcode'], 'A stored language code is kept.');
  }

  /**
   * Tests an object with nothing translatable loses a stray language code.
   */
  public function testPlainObjectIsStoredWithoutLanguageCode(): void {
    $this->config(PlainSettingsStorage::CONFIG)->setData(['langcode' => 'en', 'limit' => 1])->save();
    $storage = $this->storage(PlainSettingsStorage::class);

    $prepared = $storage->prepare($this->context(), ['limit' => 2, 'langcode' => 'de'], new AnonymousUserSession());
    $this->assertSame(['limit' => 2], $prepared, 'A language code among the values is not the resource\'s to write.');
    $storage->commit($this->context(), $prepared, new AnonymousUserSession());
    $this->assertSame(['limit' => 2], $this->config(PlainSettingsStorage::CONFIG)->getRawData());
  }

  /**
   * Tests the whole object is held to its schema, filed under the keys.
   */
  public function testTheSchemaIsCheckedUnderTheKeysWritten(): void {
    $refusals = $this->refusals($this->storage(TranslatableSettingsStorage::class), [
      'greeting' => 'Hello',
      'limit' => 'many',
      'color' => 'red',
    ]);

    $this->assertEqualsCanonicalizing(['limit', 'color'], array_keys($refusals));
    $this->assertStringContainsString('correct primitive type', $refusals['limit']);
    $this->assertStringContainsString("'color' is not a supported key", $refusals['color'], 'A key the schema does not declare is refused under that key, not dropped at the root.');
  }

  /**
   * Tests a module's mounted settings need a schema of their own.
   */
  public function testMountedSettingsNeedTheirOwnSchema(): void {
    $storage = $this->storage(TranslatableSettingsStorage::class);
    $described = [
      'greeting' => 'Hello',
      'limit' => 3,
      'third_party_settings' => ['data_surface_storage_test' => ['note' => 'Kept']],
    ];
    $this->assertSame([], $this->refusals($storage, $described));

    $refusals = $this->refusals($storage, ['third_party_settings' => ['nobody' => ['note' => 'Lost']]] + $described);
    $this->assertSame(['third_party_settings.nobody'], array_keys($refusals));
    $this->assertStringContainsString('No config schema describes the settings the nobody module stores in data_surface_storage_test.translatable', $refusals['third_party_settings.nobody']);

    $refusals = $this->refusals($this->storage(PlainSettingsStorage::class), [
      'limit' => 3,
      'third_party_settings' => ['data_surface_storage_test' => ['note' => 'Lost']],
    ]);
    $this->assertSame(['third_party_settings'], array_keys($refusals), 'An owner that declares no third party settings refuses every mount, under that key.');
  }

  /**
   * Tests a key kept under a path, beside a sibling no resource declares.
   */
  public function testKeyStoredUnderPath(): void {
    $this->config(NestedSettingsStorage::CONFIG)->setData([
      'outer' => ['inner' => ['limit' => 3, 'note' => 'Kept']],
      'title' => 'First',
    ])->save();
    $storage = $this->storage(NestedSettingsStorage::class);
    $account = new AnonymousUserSession();

    $this->assertSame(['title' => 'First', 'limit' => 3], $storage->load($this->context()), 'Read from its path, the map around it left out.');

    $prepared = $storage->prepare($this->context(), ['limit' => 7, 'title' => 'Second'], $account);
    $this->assertSame(['outer.inner.limit' => 7, 'title' => 'Second'], $prepared, 'Prepared at its path.');
    $refusals = $this->refusals($storage, ['limit' => 11]);
    $this->assertSame(['limit'], array_keys($refusals), 'The schema at the path judged it, filed under the key.');
    $this->assertStringContainsString('between 0 and 10', $refusals['limit']);

    $storage->commit($this->context(), $prepared, $account);
    $this->assertSame([
      'outer' => ['inner' => ['limit' => 7, 'note' => 'Kept']],
      'title' => 'Second',
    ], $this->config(NestedSettingsStorage::CONFIG)->getRawData(), 'The sibling is kept.');

    $this->assertFalse($storage->mayClear($this->context(), 'limit'), 'The schema at the path does not call it nullable.');
    $this->assertTrue($storage->mayClear($this->context(), 'title'));

    $surface = static fn (int $max): DataSurface => new DataSurface(DefinitionMap::fromArrays(definitions: [
      'limit' => DataDefinition::create('integer')
        ->setLabel('Limit')
        ->setRequired(TRUE)
        ->addConstraint('Range', ['min' => 0, 'max' => $max]),
      'title' => DataDefinition::create('string')->setLabel('Title'),
    ]));
    $this->assertSame([], $storage->widerThanSchema($surface(10)), 'Held to the property at its path.');
    $this->assertSame(['limit' => 'it may be more than 10, which the schema refuses'], $storage->widerThanSchema($surface(20)));
  }

  /**
   * Tests an object no schema describes is refused before anything else.
   */
  public function testAnObjectWithNoSchemaIsAnError(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('No config schema describes data_surface_storage_test.missing');
    $this->storage(MissingSchemaSettingsStorage::class)->prepare($this->context(), ['limit' => 1], new AnonymousUserSession());
  }

}
