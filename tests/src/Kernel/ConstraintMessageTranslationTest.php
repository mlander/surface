<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\ResourceBuild\Shapes;
use Drupal\data_surface_examples_compliance\Shape\License;
use Drupal\Tests\data_surface\Kernel\Fixture\FakeLanguageTranslator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a constraint message is one source a translator can see.
 *
 * A custom message was a bare literal in a constraint's options, which
 * potx never extracts; and the served contract copied it into
 * `x-surface.patternMessage` untranslated, so a document in another
 * language said the pattern in English while the refusal for the same
 * value was translated. A message is written with t() now, and the build
 * hands Symfony its source, the string core's validator translates.
 *
 * @see docs/semantics.md#violation-messages-are-objects
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ConstraintMessageTranslationTest extends DataSurfaceKernelTestBase {

  /**
   * The doors example's message for its opening time.
   */
  protected const MESSAGE = 'Write the time as HH:MM, such as 18:30.';

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->container->get('string_translation')->addTranslator(new FakeLanguageTranslator('xx', [
      self::MESSAGE => 'XX ' . self::MESSAGE,
    ]), 100);
  }

  /**
   * Tests the build hands Symfony the source, and the refusal reads it.
   */
  public function testTheBuildHandsTheValidatorTheSource(): void {
    $built = $this->container->get('data_surface.resources')->buildOperation('event.doors', 'configure');
    $this->assertSame(self::MESSAGE, $built->getDefinitions()->get('opens')->getConstraints()['Regex']['message'], 'The message written with t() is its source string.');

    $license = DefinitionMetadata::shapeDefinition(Shapes::unaltered(License::class));
    $this->assertNotNull($license);
    $this->assertSame('A license number is four digits, such as 2048.', $license->getConstraints()['Regex']['message'], 'The shape a document writes once carries the source too.');

    $values = ['opens' => '25:00'] + $built->getDefaultValues();
    $refused = fn (): array => array_map(
      static fn ($violation): string => PlainTextOutput::renderFromHtml((string) $violation->message),
      iterator_to_array($this->pipeline()->validate($built, $values)),
    );
    $this->assertSame([self::MESSAGE], array_values($refused()));
    $this->container->get('string_translation')->setDefaultLangcode('xx');
    $this->assertSame(['XX ' . self::MESSAGE], array_values($refused()), "Core's validator translates the source when it refuses a value.");
  }

  /**
   * Tests the served contract says the message in the document's language.
   */
  public function testThePatternMessageIsTranslated(): void {
    $built = $this->container->get('data_surface.resources')->buildOperation('event.doors', 'configure');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $message = static fn (array $document): mixed => $document['schema']['properties']['opens']['x-surface']['patternMessage'] ?? NULL;

    $source = $message($emitter->emitFull($built, 'event.doors')->document);
    $this->assertSame(self::MESSAGE, $source);
    $this->container->get('string_translation')->setDefaultLangcode('xx');
    $full = $message($emitter->emitFull($built, 'event.doors')->document);
    $this->assertSame('XX ' . self::MESSAGE, $full);
    $answers = $message($emitter->emit($built, $built->getDefaultValues(), 'event.doors')->document);
    $this->assertSame('XX ' . self::MESSAGE, $answers, 'The schema for these answers says the same.');
  }

}
