<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Target\SecretCodecInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a secret key end to end, from the form element to storage.
 *
 * The unit tests state each rule on its own; this is the one that says
 * the rules are wired to each other. One field type with one secret
 * setting and one ordinary setting beside it, submitted through the same
 * pipeline every caller uses, against the target a real field settings
 * form uses. What it has to show:
 *
 * - storage holds ciphertext, so nothing that reads the field config —
 *   a config export, a database dump, another module — reads the secret;
 * - `load()` hands the pipeline plaintext, which is the only reason a
 *   partial update can leave a secret alone instead of re-encrypting a
 *   ciphertext it mistook for a value;
 * - an empty submission keeps the secret and the clear marker removes
 *   it, through the whole stack rather than in `accept()` alone;
 * - the generated element is a password box carrying no value anywhere.
 *
 * @see \Drupal\data_surface_test\Plugin\Field\FieldType\SurfaceSecretItem
 * @see \Drupal\data_surface\Target\EncryptedSettingsShape
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SecretSettingsTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'data_surface',
    'data_surface_test',
  ];

  /**
   * The field whose instance settings carry the secret.
   */
  protected FieldConfig $field;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    FieldStorageConfig::create([
      'field_name' => 'field_secret',
      'entity_type' => 'entity_test',
      'type' => 'data_surface_secret',
    ])->save();
    $this->field = FieldConfig::create([
      'field_name' => 'field_secret',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Secret bearing',
    ]);
    $this->field->save();
  }

  /**
   * Gets the field item, which is the surface and target provider.
   *
   * Rebuilt from the field's own item definition each time, exactly as
   * the settings form's static callback does, so nothing here holds a
   * target across a save that changed what it describes.
   *
   * @return \Drupal\data_surface\Form\FieldSurfaceProviderInterface
   *   The field item.
   */
  protected function item(): FieldSurfaceProviderInterface {
    $field = FieldConfig::load($this->field->id());
    return $this->container->get('typed_data_manager')->create($field->getItemDefinition());
  }

  /**
   * Gets the site's secret codec.
   *
   * @return \Drupal\data_surface\Target\SecretCodecInterface
   *   The codec.
   */
  protected function codec(): SecretCodecInterface {
    return $this->container->get('data_surface.secret_codec');
  }

  /**
   * Reads what the field config actually stores, with no shape in front.
   *
   * @return array
   *   The stored settings.
   */
  protected function storedSettings(): array {
    return FieldConfig::load($this->field->id())->getSettings();
  }

  /**
   * Submits input through the whole pipeline.
   *
   * @param array $input
   *   The raw input, keyed by surface key.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result.
   */
  protected function submit(array $input): DataSurfaceResult {
    $item = $this->item();
    $result = $this->pipeline()->submit($item->getFieldSurface(), $input, $item->getDataSurfaceTarget());
    $this->assertCount(0, $result->violations);
    $this->assertTrue($result->committed);
    return $result;
  }

  /**
   * Tests that storage holds ciphertext and the pipeline holds plaintext.
   */
  public function testStorageHoldsCiphertext(): void {
    $this->submit(['endpoint' => 'https://example.com', 'token' => 'sk-1234']);

    $stored = $this->storedSettings();
    $this->assertSame('https://example.com', $stored['endpoint']);
    $this->assertNotSame('sk-1234', $stored['token']);
    $this->assertStringNotContainsString('sk-1234', $stored['token']);
    $this->assertTrue($this->codec()->isEncrypted($stored['token']));

    // The whole config object, so nothing else carries a copy: the
    // settings are the only place this value has been.
    $exported = FieldConfig::load($this->field->id())->toArray();
    $this->assertStringNotContainsString('sk-1234', serialize($exported));

    // What the pipeline is handed back is the secret itself, which is
    // what makes the next partial update legal.
    $item = $this->item();
    $loaded = $item->getDataSurfaceTarget()->load($item->getFieldSurface());
    $this->assertSame('sk-1234', $loaded['token']);
  }

  /**
   * Tests that a partial update leaves the stored secret alone.
   *
   * The case the keep rule exists for: somebody changes the endpoint,
   * the password box comes up empty because it always does, and the
   * secret has to survive it.
   *
   * What survives is the secret, not the bytes. Each write draws a fresh
   * nonce, so an unchanged secret is stored as different ciphertext
   * every time — which is what keeps two equal secrets from looking
   * equal in storage, and which is why the assertion below decrypts
   * rather than compares.
   */
  public function testPartialUpdateKeepsTheSecret(): void {
    $this->submit(['endpoint' => 'https://example.com', 'token' => 'sk-1234']);
    $ciphertext = $this->storedSettings()['token'];

    // The form path: every key submitted, the secret submitted empty.
    $this->submit(['endpoint' => 'https://example.test', 'token' => '']);
    $this->assertSame('https://example.test', $this->storedSettings()['endpoint']);
    $this->assertSame('sk-1234', $this->codec()->decrypt($this->storedSettings()['token']));
    $this->assertNotSame($ciphertext, $this->storedSettings()['token']);

    // The payload path: the secret not named at all.
    $this->submit(['endpoint' => 'https://example.invalid']);
    $this->assertSame('sk-1234', $this->codec()->decrypt($this->storedSettings()['token']));

    $item = $this->item();
    $this->assertSame('sk-1234', $item->getDataSurfaceTarget()->load($item->getFieldSurface())['token']);
  }

  /**
   * Tests that the clear marker is the way a secret is removed.
   */
  public function testClearMarkerRemovesTheSecret(): void {
    $this->submit(['token' => 'sk-1234']);
    $this->assertTrue($this->codec()->isEncrypted($this->storedSettings()['token']));

    $this->submit(['token' => DataSurfacePipelineInterface::CLEAR_SECRET]);
    $this->assertNull($this->storedSettings()['token']);

    $item = $this->item();
    $this->assertNull($item->getDataSurfaceTarget()->load($item->getFieldSurface())['token']);
  }

  /**
   * Tests that a secret stored before the flag is read, then enciphered.
   *
   * A field type that declares a key secret today has that key's values
   * in storage from yesterday, in plaintext. Reading must not break, and
   * the next write is where the migration happens.
   */
  public function testPlaintextAlreadyInStorageIsMigrated(): void {
    // Written behind the surface's back, which is what "before the flag"
    // means: the settings the field type saved when nothing encrypted.
    $field = FieldConfig::load($this->field->id());
    $field->setSettings(['endpoint' => 'https://example.com', 'token' => 'sk-legacy'] + $field->getSettings())->save();

    $item = $this->item();
    $this->assertSame('sk-legacy', $item->getDataSurfaceTarget()->load($item->getFieldSurface())['token']);

    $this->submit(['endpoint' => 'https://example.test']);
    $this->assertTrue($this->codec()->isEncrypted($this->storedSettings()['token']));

    $item = $this->item();
    $this->assertSame('sk-legacy', $item->getDataSurfaceTarget()->load($item->getFieldSurface())['token']);
  }

  /**
   * Tests that the generated element is a password box holding nothing.
   */
  public function testTheElementCarriesNoSecret(): void {
    $this->submit(['endpoint' => 'https://example.com', 'token' => 'sk-1234']);

    $item = $this->item();
    $surface = $item->getFieldSurface();
    $values = $item->getDataSurfaceTarget()->load($surface);
    $this->assertSame('sk-1234', $values['token']);

    // Built from the values that do hold the secret, which is the point:
    // the builder is what refuses to place it.
    $container = $this->formBuilder()->buildSurfaceForm($surface, $values, new FormState());

    $this->assertSame('password', $container['token']['#type']);
    $this->assertArrayNotHasKey('#default_value', $container['token']);
    $this->assertArrayNotHasKey('#placeholder', $container['token']);
    $this->assertFalse($container['token']['#required']);
    $this->assertStringContainsString(
      'Leave blank to keep the current value.',
      (string) $container['token']['#description'],
    );
    $this->assertStringNotContainsString('sk-1234', serialize($container));

    // The ordinary key beside it is untouched by any of this.
    $this->assertSame('textfield', $container['endpoint']['#type']);
    $this->assertSame('https://example.com', $container['endpoint']['#default_value']);
  }

}
