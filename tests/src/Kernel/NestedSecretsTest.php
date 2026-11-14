<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Collection;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\Tests\surface\HandCorpus\DoorCorpusRecorder;
use Drupal\Tests\surface\HandCorpus\HandFormatter;
use Drupal\Tests\surface\HandCorpus\HandStorage;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a stored secret is served at no depth, by any door.
 *
 * One data surface holds a secret at the top, in a map, in a map in a
 * map, in a list of maps, as a list of secrets, in a collection's items,
 * in an attached child, in a chosen variant, in a variant nothing has
 * chosen and in the map other modules mount keys in. What is stored
 * holds a text with "hidden" in it at each secret and one with "shown"
 * in it at each key beside them. Each door serves no stored secret and
 * echoes no secret sent, and serves the keys beside each as before. What
 * a caller may do stays: a secret left out keeps what is stored. A
 * formatter's settings summary prints no line for a secret.
 *
 * Decision: see docs/decisions.md#a-secret-is-not-served-at-any-depth.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class NestedSecretsTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'serialization', 'tool', 'surface'];

  /**
   * What storage holds: a secret text at each secret, a plain one beside.
   */
  protected const STORED = [
    'name' => 'shown name',
    'code' => 'hidden top',
    'map' => ['a' => 'shown a', 'code' => 'hidden map'],
    'deep' => ['in' => ['b' => 'shown b', 'code' => 'hidden deep']],
    'rows' => [['n' => 'shown row', 'code' => 'hidden row']],
    'codes' => ['hidden list'],
    'items' => [['n' => 'shown item', 'code' => 'hidden item']],
    'child' => ['n' => 'shown child', 'code' => 'hidden child'],
    'kind' => 'a',
    'settings' => ['n' => 'shown variant', 'code' => 'hidden variant'],
    'more' => ['n' => 'shown more', 'code' => 'hidden more'],
    'third_party_settings' => ['surface' => ['extra' => ['a' => 'shown mount', 'code' => 'hidden mount']]],
  ];

  /**
   * What a caller sends: a secret text at each secret, nothing else.
   */
  protected const SENT = [
    'code' => 'typed top',
    'map' => ['code' => 'typed map'],
    'deep' => ['in' => ['code' => 'typed deep']],
    'rows' => [['n' => 'shown row', 'code' => 'typed row']],
    'items' => [['n' => 'shown item', 'code' => 'typed item']],
    'child' => ['code' => 'typed child'],
    'settings' => ['code' => 'typed variant'],
    'more' => ['n' => 'shown more', 'code' => 'typed more'],
    'third_party_settings' => ['surface' => ['extra' => ['code' => 'typed mount']]],
  ];

  /**
   * What the read answers, as it always has: every secret left out.
   */
  protected const READ = [
    'name' => 'shown name',
    'map' => ['a' => 'shown a'],
    'deep' => ['in' => ['b' => 'shown b']],
    'rows' => [['n' => 'shown row']],
    'items' => [['n' => 'shown item']],
    'child' => ['n' => 'shown child'],
    'kind' => 'a',
    'settings' => ['n' => 'shown variant'],
    'more' => ['n' => 'shown more'],
    'third_party_settings' => ['surface' => ['extra' => ['a' => 'shown mount']]],
  ];

  /**
   * What a document's values hold over what is stored.
   *
   * A secret where the document draws an element is null, as at the top;
   * one inside a value the document holds whole (a list's items, variants
   * nothing has chosen) is left out of it.
   */
  protected const DOCUMENT = [
    'name' => 'shown name',
    'code' => NULL,
    'map' => ['a' => 'shown a', 'code' => NULL],
    'deep' => ['in' => ['b' => 'shown b', 'code' => NULL]],
    'rows' => [['n' => 'shown row']],
    'codes' => NULL,
    'items' => [['n' => 'shown item', 'code' => NULL]],
    'child' => ['n' => 'shown child', 'code' => NULL],
    'kind' => 'a',
    'settings' => ['n' => 'shown variant', 'code' => NULL],
    'kind2' => NULL,
    'more' => ['n' => 'shown more'],
    'third_party_settings' => ['surface' => ['extra' => ['a' => 'shown mount', 'code' => NULL]]],
  ];

  /**
   * Tests the tool door's `values` defaults hold no stored secret.
   */
  public function testToolDefaults(): void {
    $defs = $this->container->get('surface.input_definitions');
    $surface = static::surface();
    $map = $defs->withStored($defs->fromSurface($surface, 'Values', 'The values'), $surface, self::STORED);
    $this->assertSame([
      'name' => 'shown name',
      'code' => NULL,
      'map' => ['a' => 'shown a'],
      'deep' => ['in' => ['b' => 'shown b']],
      'rows' => [['n' => 'shown row']],
      'codes' => NULL,
      'items' => [['n' => 'shown item']],
      'child' => NULL,
      'kind' => 'a',
      'settings' => NULL,
      'kind2' => NULL,
      'more' => NULL,
      'third_party_settings' => ['surface' => ['extra' => ['a' => 'shown mount']]],
    ], static::defaults($map));
    $child = $map->getPropertyDefinition('child');
    $this->assertInstanceOf(MapInputDefinition::class, $child);
    $this->assertSame(['n' => 'shown child', 'code' => NULL], static::defaults($child));
    $this->assertNoSecret(DoorCorpusRecorder::tree($map));
  }

  /**
   * Tests the `values` input of an update holds no stored secret.
   */
  public function testToolValuesInput(): void {
    $owner = new ResourceDefinition(NULL, 'nested.secrets', 'surface', [], NULL, NULL, []);
    $values = $this->container->get('surface.operation_inputs')->values($owner, static::surface(), new OperationContext('update', FALSE), new HandStorage(self::STORED));
    $this->assertSame('shown name', $values->getPropertyDefinition('name')?->getDefaultValue());
    $this->assertSame(['a' => 'shown a'], $values->getPropertyDefinition('map')?->getDefaultValue());
    $this->assertNoSecret(DoorCorpusRecorder::tree($values));
  }

  /**
   * Tests the read and every door's projection leave every secret out.
   */
  public function testReadAndProjection(): void {
    $owner = new ResourceDefinition(NULL, 'nested.secrets', 'surface', [], NULL, NULL, []);
    $surface = static::surface();
    $read = $this->container->get('surface.operation_inputs')->stored($owner, $surface, new OperationContext('update', FALSE), new HandStorage(self::STORED));
    $this->assertSame(self::READ, $read);
    $this->assertSame(self::READ, $this->pipeline()->outbound($surface, self::STORED));
  }

  /**
   * Tests a document over what is stored holds no stored secret.
   */
  public function testDocument(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $surface = static::surface();
    $this->assertSame(self::DOCUMENT, static::plain($emitter->emit($surface, self::STORED, 'nested', 'op')->document['values']));
    $this->assertSame(self::DOCUMENT, static::plain($emitter->emit($surface, self::STORED, 'nested', 'op', NULL, TRUE)->document['values']));
    $narrowed = $this->container->get('surface.document_narrowing')->narrow($surface, self::STORED, []);
    $this->assertSame(self::DOCUMENT, static::plain($narrowed->contract()->document['values']));
  }

  /**
   * Tests a document over what was sent echoes no secret sent.
   */
  public function testDocumentOverSent(): void {
    $surface = static::surface();
    $accepted = $this->pipeline()->accept($surface, self::SENT, self::STORED);
    // What a caller may do stays: a secret sent is taken, one left out
    // keeps what is stored, at every depth.
    $this->assertSame('typed deep', $accepted['deep']['in']['code']);
    $this->assertSame('hidden list', $accepted['codes'][0]);
    $document = $this->container->get('surface.contract_emitter')->emit($surface, $accepted, 'nested', 'op', NULL, TRUE)->document;
    $this->assertSame(self::DOCUMENT, static::plain($document['values']));
    $narrowed = $this->container->get('surface.document_narrowing')->narrow($surface, self::STORED, self::SENT);
    $this->assertNoSecret($narrowed->contract()->document);
  }

  /**
   * Tests the form draws no stored secret and no secret sent.
   */
  public function testForm(): void {
    $surface = static::surface();
    foreach (['stored' => self::STORED, 'sent' => $this->pipeline()->accept($surface, self::SENT, self::STORED)] as $over => $values) {
      $shown = static::elementValues($this->formBuilder()->buildSurfaceForm($surface, $values, new FormState()));
      $this->assertContains('shown name', $shown, "The form over what is $over.");
      $this->assertContains('shown a', $shown, "The form over what is $over.");
      $this->assertContains('shown mount', $shown, "The form over what is $over.");
      $this->assertNoSecret($shown);
    }
  }

  /**
   * Tests a formatter's settings summary prints no stored secret.
   */
  public function testFormatterSummary(): void {
    $lines = array_map('strval', (new HandFormatter(static::surface(), self::STORED))->settingsSummary());
    $this->assertContains('Name: shown name', $lines);
    $this->assertContains('A: shown a', $lines);
    $this->assertNoSecret($lines);
  }

  /**
   * Builds the data surface.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   A secret at every depth, a plain key beside each.
   */
  protected static function surface(): SurfaceInterface {
    $kid = (new SurfaceBuilder())
      ->setDefinition('n', static::text('Name'))
      ->setDefinition('code', static::secret(static::text('Code')))
      ->seal();
    $other = (new SurfaceBuilder())->setDefinition('m', static::text('Other'))->seal();
    $builder = new SurfaceBuilder();
    $builder->setDefinition('name', static::text('Name'));
    $builder->setDefinition('code', static::secret(static::text('Code')));
    $builder->setDefinition('map', MapDataDefinition::create()->setLabel('Map')
      ->setPropertyDefinition('a', static::text('A'))
      ->setPropertyDefinition('code', static::secret(static::text('Code'))));
    $builder->setDefinition('deep', MapDataDefinition::create()->setLabel('Deep')
      ->setPropertyDefinition('in', MapDataDefinition::create()->setLabel('In')
        ->setPropertyDefinition('b', static::text('B'))
        ->setPropertyDefinition('code', static::secret(static::text('Code')))));
    $builder->setDefinition('rows', new ListDataDefinition(['label' => 'Rows'], MapDataDefinition::create()->setLabel('Row')
      ->setPropertyDefinition('n', static::text('N'))
      ->setPropertyDefinition('code', static::secret(static::text('Code')))));
    $builder->setDefinition('codes', new ListDataDefinition(['label' => 'Codes'], static::secret(static::text('Code'))));
    $builder->attachList('items', new Collection(new Attachment($kid)));
    $builder->setDefinition('child', MapDataDefinition::create()->setLabel('Child'));
    $builder->attach('child', new Attachment($kid));
    $builder->setDefinition('kind', static::text('Kind')->addConstraint('Choice', ['choices' => ['a', 'b']]));
    $builder->setDefinition('settings', MapDataDefinition::create()->setLabel('Settings'));
    $builder->attachVariants('settings', 'kind', ['a' => new Attachment($kid), 'b' => new Attachment($other)]);
    $builder->setDefinition('kind2', static::text('Kind two')->addConstraint('Choice', ['choices' => ['a', 'b']]));
    $builder->setDefinition('more', MapDataDefinition::create()->setLabel('More'));
    $builder->attachVariants('more', 'kind2', ['a' => new Attachment($kid), 'b' => new Attachment($other)]);
    $builder->setThirdPartyDefinition('surface', 'extra', MapDataDefinition::create()->setLabel('Extra')
      ->setPropertyDefinition('a', static::text('A'))
      ->setPropertyDefinition('code', static::secret(static::text('Code'))));
    return $builder->seal();
  }

  /**
   * Makes a string definition.
   *
   * @param string $label
   *   The label.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  protected static function text(string $label): DataDefinition {
    return DataDefinition::create('string')->setLabel($label);
  }

  /**
   * Marks a definition secret.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The same definition.
   */
  protected static function secret(DataDefinitionInterface $definition): DataDefinitionInterface {
    DefinitionMetadata::setSecret($definition);
    return $definition;
  }

  /**
   * Reads the default of each property of a map input.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition $map
   *   The map.
   *
   * @return array<string, mixed>
   *   Each property's default, by name.
   */
  protected static function defaults(MapInputDefinition $map): array {
    $defaults = [];
    foreach ($map->getPropertyDefinitions() as $name => $property) {
      $defaults[$name] = $property instanceof InputDefinitionInterface ? $property->getDefaultValue() : NULL;
    }
    return $defaults;
  }

  /**
   * Gives a document's values as plain arrays.
   *
   * @param mixed $values
   *   The values, an object for a map.
   *
   * @return mixed
   *   The same, every object an array.
   */
  protected static function plain(mixed $values): mixed {
    return json_decode((string) json_encode($values), TRUE);
  }

  /**
   * Collects every value an element of a form is drawn with.
   *
   * @param array $element
   *   The form, or an element inside it.
   *
   * @return list<mixed>
   *   Each `#default_value` and `#value`, and the values the container
   *   keeps for the form (SurfaceFormBuilderInterface::VALUES_KEY), at
   *   every depth, their strings listed one by one.
   */
  protected static function elementValues(array $element): array {
    $found = [];
    foreach ($element as $key => $child) {
      if (in_array($key, ['#default_value', '#value', SurfaceFormBuilderInterface::VALUES_KEY], TRUE)) {
        array_push($found, ...(is_array($child) ? static::strings($child) : [$child]));
      }
      elseif (is_array($child)) {
        array_push($found, ...static::elementValues($child));
      }
    }
    return $found;
  }

  /**
   * Lists every string inside an array, at every depth.
   *
   * @param array $values
   *   The array.
   *
   * @return list<string>
   *   The strings.
   */
  protected static function strings(array $values): array {
    $found = [];
    array_walk_recursive($values, static function (mixed $value) use (&$found): void {
      if (is_string($value)) {
        $found[] = $value;
      }
    });
    return $found;
  }

  /**
   * Asserts no stored or sent secret text is anywhere in what a door gave.
   *
   * @param mixed $served
   *   What the door gave.
   */
  protected function assertNoSecret(mixed $served): void {
    $text = (string) json_encode($served, JSON_PARTIAL_OUTPUT_ON_ERROR);
    $this->assertSame(0, preg_match_all('/(hidden|typed) [a-z]+/', $text, $found), 'Served: ' . implode(', ', array_unique($found[0])));
  }

}
