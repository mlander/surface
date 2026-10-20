<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Gettext\PoItem;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface_examples\ExampleCalls;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool\PlainTextMarkup;
use Drupal\Tests\data_surface\Kernel\Fixture\FakeLanguageTranslator;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a tool listing reads in the language that reads it.
 *
 * A tool definition is cached for every language, so it was read in the
 * language that discovered it: the derivation flattened the operation's
 * label into the tool's label and description, and each description a
 * note was appended to, before the note. Read back in another language,
 * the tool spoke one language and its keys another. And a description
 * read as plain text was rebuilt from its source, which spells a plural
 * as both of its forms with a literal count.
 *
 * @see docs/decisions.md#a-machine-document-carries-plain-text
 * @see docs/decisions.md#a-static-tool-definition-is-no-accounts
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ToolListingLanguageTest extends DataSurfaceKernelTestBase {

  /**
   * The made-up language the listing is read back in.
   */
  protected const LANGCODE = 'xx';

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->container->get('string_translation')->addTranslator(new FakeLanguageTranslator(self::LANGCODE, [
      'Configure registration' => 'XX Configure registration',
      'Event title' => 'XX Event title',
      'Depends on @keys.' => 'XX Depends on @keys.',
      'Required; a value already stored stands when left out.' => 'XX Required.',
      'At least 1 steward for @capacity attendees.' . PoItem::DELIMITER . 'At least @count stewards for @capacity attendees.' => 'XX 1 steward, @capacity.' . PoItem::DELIMITER . 'XX @count stewards, @capacity.',
    ]), 100);
  }

  /**
   * Reads every string as the made-up language would.
   */
  protected function readInTheOtherLanguage(): void {
    $this->container->get('string_translation')->setDefaultLangcode(self::LANGCODE);
  }

  /**
   * Tests the tool and its keys, discovered in one language, read in another.
   */
  public function testTheToolAndItsKeysAgreeInTheLanguageThatReads(): void {
    $definition = $this->container->get('plugin.manager.tool')->getDefinition(ExampleCalls::TOOL);
    $this->assertInstanceOf(ToolDefinition::class, $definition);
    // As discovery caches it: serialized in the language that discovered
    // it, and read back by a request in another.
    $cached = serialize($definition);
    $this->readInTheOtherLanguage();
    // The discovery cache restores the whole definition, every class in
    // it, which is the thing under test.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $definition = unserialize($cached);
    $this->assertInstanceOf(ToolDefinition::class, $definition);

    $this->assertSame('XX Configure registration', (string) $definition->getLabel());
    $this->assertStringStartsWith('XX Configure registration: the "configure" operation', (string) $definition->getDescription());
    $values = $definition->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame('XX Event title', (string) $properties['title']->getLabel());
    $this->assertSame('XX Depends on venue. XX Required.', (string) $properties['room']->getDescription(), 'A description a note was appended to is read in the same language as the note.');
  }

  /**
   * Tests a plural read as plain text keeps one form and its count.
   */
  public function testPlainTextKeepsThePluralCount(): void {
    $stewards = static fn (int $count): PluralTranslatableMarkup => new PluralTranslatableMarkup(
      $count,
      'At least 1 steward for @capacity attendees.',
      'At least @count stewards for @capacity attendees.',
      ['@capacity' => $count * 40],
    );
    $this->assertSame('At least 3 stewards for 120 attendees.', (string) PlainTextMarkup::translatable($stewards(3)));
    $this->assertSame('At least 1 steward for 40 attendees.', (string) PlainTextMarkup::of($stewards(1)));

    $cached = serialize(PlainTextMarkup::translatable($stewards(3)));
    $this->readInTheOtherLanguage();
    $read = unserialize($cached, ['allowed_classes' => [PlainTextMarkup::class, PluralTranslatableMarkup::class]]);
    $this->assertInstanceOf(PlainTextMarkup::class, $read);
    $this->assertInstanceOf(TranslatableMarkup::class, $read);
    $this->assertSame('XX 3 stewards, 120.', (string) $read);
  }

}
