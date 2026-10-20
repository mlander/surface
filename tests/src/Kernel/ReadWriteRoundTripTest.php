<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigEvents;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests writing back what the read tool returned changes nothing.
 *
 * B12 of the 2026-10-20 reviews: the read tool returned step 3's stored
 * empty strings, and writing them back stored NULL; a declared default
 * storage never held (the compliance module's stewards count) was
 * written though nothing sent it; the config object was saved whatever
 * changed, and the result said only that it was committed. A read then a
 * write is a fixed point now, and the result lists what changed.
 *
 * @see docs/decisions.md#absent-keeps-null-clears
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ReadWriteRoundTripTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_compliance',
  ];

  /**
   * The config object step 3 stores in.
   */
  protected const CONFIG = 'data_surface_examples.registration_step3';

  /**
   * The write tool, and its read tool with the suffix.
   */
  protected const TOOL = 'data_surface:registration.step3:configure';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Reads step 3's values through the read tool.
   *
   * @return array
   *   The values it answers.
   */
  protected function read(): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(self::TOOL . ':read');
    $tool->execute();
    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    return $result->getContextValues()[OperationInputs::VALUES];
  }

  /**
   * Writes values through the write tool.
   *
   * @param array $values
   *   The values.
   * @param bool $dry_run
   *   Whether to rehearse only.
   *
   * @return array{0: string, 1: array}
   *   The message, as plain text, and the outputs.
   */
  protected function write(array $values, bool $dry_run = FALSE): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(self::TOOL);
    $tool->setInputValue(OperationInputs::VALUES, $values);
    $tool->setInputValue(OperationInputs::DRY_RUN, $dry_run);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    return [strip_tags((string) $result->getMessage()), $result->getContextValues()];
  }

  /**
   * Reads the stored object as a config export would write it.
   *
   * @return string
   *   The YAML.
   */
  protected function exported(): string {
    return Yaml::encode($this->container->get('config.storage')->read(self::CONFIG));
  }

  /**
   * Tests a read written back leaves storage byte for byte as it was.
   */
  public function testWritingBackWhatWasReadChangesNothing(): void {
    $before = $this->exported();
    $read = $this->read();
    // The installed object holds empty strings, which the read returns.
    $this->assertSame('', $read['contact']['phone']);
    $this->assertSame('', $read['ticket']['note']);
    $this->assertArrayNotHasKey('third_party_settings', $read, 'The stewards default was never stored, so it is not read.');

    $saved = 0;
    $this->container->get('event_dispatcher')->addListener(ConfigEvents::SAVE, function () use (&$saved): void {
      $saved++;
    });
    [, $dry] = $this->write($read, TRUE);
    $this->assertSame([], $dry[OperationInputs::CHANGES], 'A dry run of the round trip plans no change.');

    [$message, $outputs] = $this->write($read);
    $this->assertTrue($outputs[OperationInputs::COMMITTED]);
    $this->assertSame([], $outputs[OperationInputs::CHANGES]);
    $this->assertSame('Configure registration (step 3): the values were accepted, and they are what is stored, so nothing was written.', $message);
    $this->assertSame(0, $saved, 'Nothing was saved.');
    $this->assertSame($before, $this->exported());
    $this->assertSame($read, $this->read());
  }

  /**
   * Tests a write lists what it changes, and a sent default is written.
   */
  public function testWriteListsWhatItChanges(): void {
    $read = $this->read();
    [, $dry] = $this->write(['capacity' => 45] + $read, TRUE);
    $this->assertSame(['capacity'], $dry[OperationInputs::CHANGES]);
    $this->assertSame(50, $this->config(self::CONFIG)->get('capacity'), 'A dry run writes nothing.');

    // Null clears, and a key holding the empty string holds nothing
    // already, so clearing it keeps the stored representation.
    [, $outputs] = $this->write(['capacity' => 45, 'contact' => ['phone' => NULL]]);
    $this->assertSame(['capacity'], $outputs[OperationInputs::CHANGES]);
    $this->assertSame(45, $this->config(self::CONFIG)->get('capacity'));
    $this->assertSame('', $this->config(self::CONFIG)->get('contact.phone'));
    [, $outputs] = $this->write(['contact' => ['phone' => '555 0100']]);
    $this->assertSame(['contact.phone'], $outputs[OperationInputs::CHANGES]);
    [, $outputs] = $this->write(['contact' => ['phone' => '']]);
    $this->assertSame(['contact.phone'], $outputs[OperationInputs::CHANGES], 'The empty string sent clears a value.');
    $this->assertNull($this->config(self::CONFIG)->get('contact.phone'));

    // A key storage never held reads as its default: sent as that
    // default, as a form posts every element it drew, it changes nothing;
    // sent another value, it is written.
    $compliance = ['data_surface_examples_compliance' => ['license' => '', 'stewards' => 1]];
    [, $outputs] = $this->write(['capacity' => 50, 'third_party_settings' => $compliance]);
    $this->assertSame(['capacity'], $outputs[OperationInputs::CHANGES]);
    $this->assertNull($this->config(self::CONFIG)->get('third_party_settings'));
    $compliance = ['data_surface_examples_compliance' => ['license' => '2048', 'stewards' => 1]];
    [, $outputs] = $this->write(['third_party_settings' => $compliance]);
    $this->assertSame(['third_party_settings'], $outputs[OperationInputs::CHANGES]);
    $this->assertSame('2048', $this->config(self::CONFIG)->get('third_party_settings.data_surface_examples_compliance.license'));
  }

}
