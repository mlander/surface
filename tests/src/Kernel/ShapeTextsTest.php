<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\Shapes;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface_resource_test\OutsideDiscovery\Texts\ArgumentTextsShape;
use Drupal\surface_resource_test\OutsideDiscovery\Texts\CheckMessageShape;
use Drupal\surface_resource_test\OutsideDiscovery\Texts\DraftTextsShape;
use Drupal\surface_resource_test\OutsideDiscovery\Texts\LibraryTextsShape;
use Drupal\surface_resource_test\OutsideDiscovery\Texts\TextsMade;
use Drupal\surface_schema_test\Shape\MarkAction;
use Drupal\Tests\surface\DerivedDefinition;
use Drupal\Tests\surface\Kernel\Fixture\FakeLanguageTranslator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests reading a schema renders no text its author holds.
 *
 * A TranslatableMarkup keeps the first language it is rendered in. Each
 * case is read under one language with nothing rendered first, then said
 * in another: every text the author made, its arguments among them, is
 * still unrendered after the read, and what is said is in the language it
 * is said in. What is tested is the reader, not how long a text that was
 * rendered keeps its language.
 *
 * @see docs/decisions.md#a-shape-is-a-schema-secrecy-is-read-from-a-schema
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class ShapeTextsTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_resource_test',
    'surface_schema_test',
  ];

  /**
   * The made-up language's words, by source.
   */
  protected const XX = [
    'Remove' => 'XX Remove',
    'Replace by separator' => 'XX Replace by separator',
    'No action (do not replace)' => 'XX No action',
    'Punctuation action' => 'XX Punctuation action',
    'Phone @kind' => 'XX Phone @kind',
    'number' => 'XX number',
    'Use @what only.' => 'XX Use @what only.',
    'the letter a' => 'XX the letter a',
    'Lowercase code' => 'XX Lowercase code',
    'Lowercase only.' => 'XX Lowercase only.',
    'Email' => 'XX Email',
    'Use an example.com address.' => 'XX Use an example.com address.',
    'Above one' => 'XX Above one',
    'More than one, as a number.' => 'XX More than one, as a number.',
    'Name' => 'XX Name',
    'Your name' => 'XX Your name',
    'Ay' => 'XX Ay',
    'Bee' => 'XX Bee',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('string_translation')->addTranslator(new FakeLanguageTranslator('xx', self::XX), 100);
  }

  /**
   * Sets the language texts are said in.
   *
   * @param string $langcode
   *   The language.
   */
  protected function in(string $langcode): void {
    $this->container->get('string_translation')->setDefaultLangcode($langcode);
  }

  /**
   * Lists every text a value holds, the arguments of each among them.
   *
   * @param mixed $value
   *   A text, a definition, or an array holding them.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The texts.
   */
  protected static function textsIn(mixed $value): array {
    if ($value instanceof TranslatableMarkup) {
      return [$value, ...static::textsIn($value->getArguments())];
    }
    if ($value instanceof DataDefinition) {
      return static::textsIn($value->toArray());
    }
    $texts = [];
    foreach (is_array($value) ? $value : [] as $member) {
      array_push($texts, ...static::textsIn($member));
    }
    return $texts;
  }

  /**
   * Lists the texts among some that were rendered, by source.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $texts
   *   The texts.
   *
   * @return string[]
   *   The source of each one rendered.
   */
  protected static function rendered(array $texts): array {
    $translated = new \ReflectionProperty(TranslatableMarkup::class, 'translatedMarkup');
    $rendered = [];
    foreach ($texts as $text) {
      if ($translated->getValue($text) !== NULL) {
        $rendered[] = $text->getUntranslatedString();
      }
    }
    return $rendered;
  }

  /**
   * Gets the definition Shapes keeps for a shape.
   *
   * @param string $shape
   *   The shape's class.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The kept definition, read once.
   */
  protected static function kept(string $shape): DataDefinitionInterface {
    $read = (new \ReflectionProperty(Shapes::class, 'read'))->getValue();
    $kept = $read[$shape][Shapes::idOf($shape)] ?? NULL;
    assert($kept instanceof DataDefinitionInterface);
    return $kept;
  }

  /**
   * Says a definition as its published node does, decoded.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   The schema, as JSON reads it, in the language set now.
   */
  protected function said(DataDefinitionInterface $definition): array {
    return (array) Schemas::decoded($this->container->get('surface.definition_schema')->published(DefinitionSchema::of($definition), FALSE));
  }

  /**
   * Says the labels of a definition's LabeledChoice.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array<string, string>
   *   Each label, by value, in the language set now: a labeled list holds
   *   its labels by value (`choices`), as add() declares one, since part
   *   32.9; before, beside its values (`labels`).
   */
  protected static function labels(DataDefinitionInterface $definition): array {
    return array_map('strval', $definition->getConstraints()['LabeledChoice']['choices'] ?? []);
  }

  /**
   * Tests a shipped shape's option labels are said in the served language.
   */
  public function testOptionLabelsAreSaidInTheServedLanguage(): void {
    $this->in('xx');
    $key = Shapes::unaltered(MarkAction::class);
    $this->assertSame([], static::rendered(static::textsIn(static::kept(MarkAction::class))), 'Reading rendered nothing the shape holds.');
    $this->assertCount(4, static::textsIn(static::kept(MarkAction::class)), 'The title and the three labels, kept as the author made them.');
    $this->in('en');
    $this->assertSame(['Remove', 'Replace by separator', 'No action (do not replace)'], array_values(static::labels($key)), 'Read under xx, said in en.');
    $this->assertSame('Punctuation action', (string) $key->getLabel());

    $key = Shapes::unaltered(MarkAction::class);
    $this->in('xx');
    $this->assertSame(['XX Remove', 'XX Replace by separator', 'XX No action'], array_values(static::labels($key)), 'And the other way.');
    $this->assertSame([], static::rendered(static::textsIn(static::kept(MarkAction::class))), 'Saying a copy in two languages rendered nothing kept.');
  }

  /**
   * Lists the shapes whose texts are said, and what each says in each.
   *
   * @return array<string, array{0: class-string, 1: array<string, array<string, string>>}>
   *   Each shape's class, and what its published node says by language:
   *   its title, its description and its message, where it says each.
   */
  public static function shapes(): array {
    $kept = 'Checked on the server against the schema it was read from: exclusiveMinimum.';
    return [
      'a title and a message with a text as an argument' => [
        ArgumentTextsShape::class,
        [
          'en' => ['title' => 'Phone number', 'message' => 'Use the letter a only.'],
          'xx' => ['title' => 'XX Phone XX number', 'message' => 'XX Use XX the letter a only.'],
        ],
      ],
      'a check\'s message' => [
        CheckMessageShape::class,
        [
          'en' => ['title' => 'Lowercase code', 'message' => 'Lowercase only.'],
          'xx' => ['title' => 'XX Lowercase code', 'message' => 'XX Lowercase only.'],
        ],
      ],
      'a type of the library' => [
        LibraryTextsShape::class,
        [
          'en' => ['title' => 'Email', 'message' => 'Use an example.com address.'],
          'xx' => ['title' => 'XX Email', 'message' => 'XX Use an example.com address.'],
        ],
      ],
      'a draft spelling, which preparing rewrites' => [
        DraftTextsShape::class,
        [
          'en' => [
            'title' => 'Above one',
            'description' => 'More than one, as a number.',
            'message' => $kept,
          ],
          'xx' => [
            'title' => 'XX Above one',
            'description' => 'XX More than one, as a number.',
            'message' => $kept,
          ],
        ],
      ],
    ];
  }

  /**
   * Says what a shape's published node says: its title and its message.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The shape's definition.
   *
   * @return array<string, string>
   *   The title, the description and the one message, where it says
   *   each, in the language set now.
   */
  protected function saidOf(DataDefinitionInterface $definition): array {
    $said = $this->said($definition);
    $messages = $said['x-portable-messages'] ?? [];
    $texts = [
      'title' => $said['title'] ?? NULL,
      'description' => $said['description'] ?? NULL,
      'message' => $messages === [] ? NULL : reset($messages),
    ];
    return array_filter($texts, static fn (mixed $text): bool => $text !== NULL);
  }

  /**
   * Tests each text of a shape stays its author's, read in either language.
   */
  public function testEveryTextStaysTheAuthors(): void {
    foreach (static::shapes() as $case => [$shape, $says]) {
      foreach ([['xx', 'en'], ['en', 'xx']] as [$read, $served]) {
        TextsMade::forget();
        (new \ReflectionProperty(Shapes::class, 'read'))->setValue(NULL, []);
        $this->in($read);
        $key = Shapes::unaltered($shape);
        $made = TextsMade::$made;
        $this->assertNotSame([], $made, $case);
        $this->assertSame([], static::rendered($made), "$case: read under $read, nothing the shape made is rendered.");
        $this->assertSame([], array_udiff($made, static::textsIn(static::kept($shape)), static fn (object $a, object $b): int => spl_object_id($a) <=> spl_object_id($b)), "$case: the kept definition holds the author's own texts.");
        $this->in($served);
        $definition = DefinitionMetadata::shapeDefinition($key);
        $this->assertNotNull($definition);
        $this->assertSame($says[$served], $this->saidOf($definition), "$case: read under $read, said in $served.");
        $this->assertSame([], static::rendered($made), "$case: saying a copy renders nothing the shape made.");
      }
    }
  }

  /**
   * Tests a check's message made with t() reads as a define() shape's did.
   *
   * At 20ef965 a shape's define() said `addConstraint('Regex', [...,
   * 'message' => t('Lowercase only.')])`, and the key's definition held
   * the message's source string.
   */
  public function testCheckMessageReadsAsTheConstraintMessage(): void {
    $this->in('en');
    $this->assertSame(['pattern' => '/^[a-z]+$/', 'message' => 'Lowercase only.'], Shapes::unaltered(CheckMessageShape::class)->getConstraints()['Regex']);
    $builder = new SurfaceBuilder();
    (new ResourceBlueprint($builder, $this->container->get('typed_data_manager')))->add('code', Schema::string()
      ->check('drupal:Regex', ['pattern' => '/^[a-z]+$/'])
      ->message('check.drupal:Regex', TextsMade::t('Lowercase only.')));
    $this->assertSame(['pattern' => '/^[a-z]+$/', 'message' => 'Lowercase only.'], DerivedDefinition::at($builder->seal(), 'code')->getConstraints()['Regex'], 'And so does an add() key.');
  }

  /**
   * Tests an add() key's texts stay its author's, in either language.
   */
  public function testAnAddedSchemaKeepsItsTexts(): void {
    foreach ([['xx', 'en'], ['en', 'xx']] as [$read, $served]) {
      TextsMade::forget();
      $this->in($read);
      $blueprint = new ResourceBlueprint(new SurfaceBuilder(), $this->container->get('typed_data_manager'));
      $blueprint->add('letter', Schema::options(['a' => TextsMade::t('Ay'), 'b' => TextsMade::t('Bee')])
        ->type('string')
        ->title(TextsMade::t('Name'))
        ->description(TextsMade::t('Your name'))
        ->pattern('^a+$')
        ->message('pattern', TextsMade::t('Use @what only.', ['@what' => TextsMade::t('the letter a')])));
      $made = TextsMade::$made;
      $this->assertSame([], static::rendered($made), "Read under $read, nothing the author made is rendered.");
      $definition = $blueprint->definitionOf('letter');
      $this->assertNotNull($definition);
      $this->in($served);
      $words = $served === 'en'
        ? ['Name', 'Your name', 'Use the letter a only.', ['a' => 'Ay', 'b' => 'Bee']]
        : ['XX Name', 'XX Your name', 'XX Use XX the letter a only.', ['a' => 'XX Ay', 'b' => 'XX Bee']];
      $this->assertSame($words, [
        (string) $definition->getLabel(),
        (string) $definition->getDescription(),
        (string) $definition->getConstraints()['Regex']['message'],
        static::labels($definition),
      ], "Read under $read, said in $served.");
      $this->assertSame([], array_udiff($made, static::textsIn($definition), static fn (object $a, object $b): int => spl_object_id($a) <=> spl_object_id($b)), 'The definition holds the author\'s own texts.');
    }
  }

}
