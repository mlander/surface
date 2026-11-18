<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Pipeline\NotEmitted;
use Drupal\Tests\surface\HandCorpus\OutputCases;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests outputs held to their validation document by the dialect's validator.
 *
 * The pipeline's conform verb answers in the input path's codes, an unknown
 * key and an absent output in Surface's own words, an object refused before
 * the validator reads it.
 *
 * @see \Drupal\surface\Pipeline\Conforming
 * @see \Drupal\surface\Contract\OutputsDocument
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class OutputsConformanceTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'surface'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
  }

  /**
   * Tests the validation document: the served one, closed, its checks kept.
   */
  public function testTheValidationDocument(): void {
    $surface = OutputCases::handSurface();
    $validation = Schemas::arrayed($this->container->get('surface.contract_emitter')->outputsDocument()->of($surface));
    $served = Schemas::arrayed($this->container->get('surface.contract_emitter')->emit($surface, [], 'hand')->outputs());

    // Closed at the top and at every depth that names keys.
    $this->assertFalse($validation['additionalProperties']);
    $this->assertFalse($validation['properties']['meta']['additionalProperties']);
    $this->assertFalse($validation['properties']['meta']['properties']['inner']['additionalProperties']);
    $this->assertFalse($validation['properties']['rows']['items']['additionalProperties']);
    // A check no keyword states is named, as the served document does not.
    $this->assertArrayHasKey('drupal:SurfaceValidReference', $validation['properties']['user']['portable-check']);
    $this->assertArrayNotHasKey('portable-check', $served['properties']['user']);
    // Otherwise the served document, less the checks and messages.
    $this->assertSame($served, self::withoutChecks($validation));
  }

  /**
   * Tests what the check answers, in the input path's codes.
   */
  public function testTheCheckAnswersInTheInputPathsCodes(): void {
    $surface = OutputCases::handSurface();
    $good = [];
    foreach ($surface->getOutputDefinitions()->entries() as $name => $entry) {
      $good[(string) $name] = OutputCases::good($entry->schema());
    }
    $said = function (array $changes) use ($surface, $good): array {
      $answer = [];
      foreach ($this->pipeline()->conformOutput($surface, array_replace_recursive($good, $changes)) as $violation) {
        $answer[] = [$violation->fullPath(), $violation->code, $violation->params, (string) $violation->message];
      }
      return $answer;
    };

    $this->assertSame([], $said([]));
    // A required output absent, in its own words; NotEmitted is absence.
    $without = $good;
    unset($without['text']);
    $this->assertSame([['text', 'required', [], 'Text was not emitted.']], array_map(
      static fn ($violation): array => [
        $violation->fullPath(),
        $violation->code,
        $violation->params,
        (string) $violation->message,
      ],
      iterator_to_array($this->pipeline()->conformOutput($surface, $without)),
    ));
    $this->assertSame([['text', 'required', [], 'Text was not emitted.']], $said(['text' => NotEmitted::value()]));
    // NULL is a value: refused where the type takes none.
    $null = ['expected' => ['string'], 'actual' => 'null'];
    $this->assertSame([['text', 'type', $null, 'This value must be of type string, null given.']], $said(['text' => NULL]));
    // A required property left out of a map: at the property.
    $this->assertSame([['meta.count', 'required', [], 'Count was not emitted.']], $said(['meta' => ['count' => NotEmitted::value()]]));
    // A key nothing declares, at the top and at every depth.
    $this->assertSame([[OutputCases::UNKNOWN, 'unknown_key', [], 'Unknown output key zz_unknown.']], $said([OutputCases::UNKNOWN => 1]));
    $this->assertSame([['meta.inner.zz_unknown', 'unknown_key', [], 'Unknown output key meta.inner.zz_unknown.']], $said(['meta' => ['inner' => [OutputCases::UNKNOWN => 1]]]));
    $this->assertSame([['rows.0.zz_unknown', 'unknown_key', [], 'Unknown output key rows.0.zz_unknown.']], $said(['rows' => [[OutputCases::UNKNOWN => 1]]]));
    // An object is refused before the validator reads it, whatever it
    // would serialize to.
    $object = ['expected' => ['string'], 'actual' => 'object'];
    $this->assertSame([['text', 'type', $object, 'This value must be of type string, object given.']], $said(['text' => new TranslatableMarkup('Markup')]));
    $plain = ['expected' => [], 'actual' => 'object'];
    $this->assertSame([['open.a', 'type', $plain, 'This value must be a plain value, stdClass given.']], $said(['open' => ['a' => new \stdClass()]]));
    // The keywords' codes.
    $this->assertSame('maxLength', $said(['bounded' => 'xxxxxx'])[0][1]);
    $this->assertSame('minLength', $said(['bounded' => 'x'])[0][1]);
    $this->assertSame('maximum', $said(['count' => 10])[0][1]);
    $this->assertSame('minimum', $said(['count' => 0])[0][1]);
    $this->assertSame('enum', $said(['choice' => 'epic'])[0][1]);
    $this->assertSame('format.email', $said(['email' => 'not-an-email'])[0][1]);
    $this->assertSame('format.date-time', $said(['when' => '2024-01-01T00:00:00'])[0][1]);
    $this->assertSame('maxItems', $said(['rows' => array_fill(0, 4, ['id' => 'a'])])[0][1]);
    $this->assertSame('minLength', $said(['filled' => ''])[0][1]);
    // A check no keyword states runs, from the entry's schema.
    $this->assertSame([['user', 'check.drupal:SurfaceValidReference']], $this->answer($surface, array_replace($good, ['user' => 7])));
    // The verdicts the dialect gives: a map for a list, a list for a map,
    // an empty map with required keys, a numeric string for a timestamp
    // refused; a whole float for an integer taken.
    $this->assertSame([['tags', 'type']], $this->answer($surface, array_replace($good, ['tags' => [3 => 'x']])));
    $this->assertSame([['meta', 'type']], $this->answer($surface, array_replace($good, ['meta' => [1, 'x']])));
    $this->assertSame([['meta.count', 'required']], $this->answer($surface, array_replace($good, ['meta' => []])));
    $this->assertSame('type', $said(['stamp' => '123'])[0][1]);
    $this->assertSame([], $said(['count' => 3.0]));
  }

  /**
   * Tests NULL at an optional map that names no keys is taken.
   *
   * Such a map is typed `object` and null where it is optional, as every
   * optional output takes NULL; where it is required, NULL is refused.
   */
  public function testNullAtAnOptionalOpenMapIsTaken(): void {
    $surface = OutputCases::handSurface();
    $good = [];
    foreach ($surface->getOutputDefinitions()->entries() as $name => $entry) {
      $good[(string) $name] = OutputCases::good($entry->schema());
    }
    $this->assertSame([], $this->answer($surface, array_replace($good, ['open' => NULL])));
    $required = (new SurfaceBuilder())->setOutputDefinition('open', MapDataDefinition::create()->setRequired(TRUE))->seal();
    $this->assertSame([['open', 'type']], $this->answer($required, ['open' => NULL]));
  }

  /**
   * Tests property names that are numbers, in a map and at the top.
   *
   * PHP holds the names "0" and "1" as integer keys, so a map that names
   * them is held as a map by its schema, never by whether its keys run
   * from 0: the validator is handed an object, as the input path hands it
   * one. A list given for a map that names none of its places is still
   * refused whole, with `type`, and so is a list given for an open map,
   * which PHP cannot tell from a map named by numbers (the hand corpus
   * pins it).
   */
  public function testNumericPropertyNames(): void {
    $numeric = MapDataDefinition::create()->setLabel('Numbered')
      ->setPropertyDefinition('0', DataDefinition::create('string')->setLabel('Zero'))
      ->setPropertyDefinition('1', DataDefinition::create('string')->setLabel('One'));
    $surface = (new SurfaceBuilder())->setOutputDefinition('k', $numeric)->seal();
    $this->assertSame([], $this->answer($surface, ['k' => ['1' => 'b']]));
    $this->assertSame([], $this->answer($surface, ['k' => ['0' => 'a', '1' => 'b']]));
    $this->assertSame([], $this->answer($surface, ['k' => ['0' => 'a']]));
    $this->assertSame([['k.2', 'unknown_key']], $this->answer($surface, ['k' => ['2' => 'c']]));
    $this->assertSame([['k.0', 'type']], $this->answer($surface, ['k' => ['0' => 5]]));
    // In a list of such maps, and two maps deep.
    $listed = (new SurfaceBuilder())->setOutputDefinition('k', ListDataDefinition::create('map')->setItemDefinition(clone $numeric))->seal();
    $this->assertSame([], $this->answer($listed, ['k' => [['1' => 'b'], ['0' => 'a', '1' => 'b']]]));
    $deep = (new SurfaceBuilder())->setOutputDefinition('k', MapDataDefinition::create()->setPropertyDefinition('7', clone $numeric))->seal();
    $this->assertSame([], $this->answer($deep, ['k' => ['7' => ['1' => 'b']]]));
    // Output keys that are numbers.
    $top = (new SurfaceBuilder())
      ->setOutputDefinition('0', DataDefinition::create('string'))
      ->setOutputDefinition('1', DataDefinition::create('string'))
      ->seal();
    $this->assertSame([], $this->answer($top, ['1' => 'a']));
    $this->assertSame([], $this->answer($top, ['0' => 'a', '1' => 'b']));
    $this->assertSame([['1', 'type']], $this->answer($top, ['0' => 'a', '1' => 5]));
    // An open map named by numbers that do not run from 0 is a map.
    $open = (new SurfaceBuilder())->setOutputDefinition('k', MapDataDefinition::create())->seal();
    $this->assertSame([], $this->answer($open, ['k' => ['5' => 'a']]));
    $this->assertSame([['k', 'type']], $this->answer($open, ['k' => ['a', 'b']]));
    // The watched-value check holds a value the same way, and answers.
    $check = $this->container->get('surface.watched_value_check');
    $schema = $surface->getOutputDefinitions()->entry('k')->schema();
    $this->assertTrue($check->admits($schema, ['1' => 'b']));
    $this->assertTrue($check->admits($schema, ['0' => 'a']));
    $this->assertFalse($check->admits($schema, ['2' => 'c']));
  }

  /**
   * Tests a secret output is refused, however it is declared.
   *
   * Each way one could be declared, refused at seal at the latest: the
   * builder's own, setSecret() on the definition a blueprint's add()
   * returns, and add() with `writeOnly`, at the top, in a map, on a list's
   * items, in a list of maps and in a map in a map.
   */
  public function testSecretOutputIsRefused(): void {
    $typed = $this->container->get('typed_data_manager');
    $token = static function (): DataDefinition {
      $definition = DataDefinition::create('string')->setLabel('Token');
      DefinitionMetadata::setSecret($definition);
      return $definition;
    };
    $ways = [
      'token' => static fn (SurfaceBuilder $builder) => $builder->setOutputDefinition('token', $token()),
      'meta.token' => static fn (SurfaceBuilder $builder) => $builder->setOutputDefinition('meta', MapDataDefinition::create()->setPropertyDefinition('token', $token())),
      'added' => static function (SurfaceBuilder $builder) use ($typed): void {
        DefinitionMetadata::setSecret((new ResourceBlueprint($builder, $typed, TRUE))->addTyped('added', 'string', 'Token'));
      },
      'schema' => static function (SurfaceBuilder $builder) use ($typed): void {
        (new ResourceBlueprint($builder, $typed, TRUE))->add('schema', Schema::string()->title('Token')->writeOnly(TRUE));
      },
      'items.*' => static function (SurfaceBuilder $builder) use ($typed): void {
        (new ResourceBlueprint($builder, $typed, TRUE))->add('items', Schema::list(Schema::string()->writeOnly(TRUE)));
      },
      'rows.*.token' => static function (SurfaceBuilder $builder) use ($typed): void {
        (new ResourceBlueprint($builder, $typed, TRUE))->add('rows', Schema::list(Schema::object(['token' => Schema::string()->writeOnly(TRUE)])));
      },
      'outer.inner.token' => static function (SurfaceBuilder $builder) use ($typed): void {
        (new ResourceBlueprint($builder, $typed, TRUE))->add('outer', Schema::object(['inner' => Schema::object(['token' => Schema::string()->writeOnly(TRUE)])]));
      },
    ];
    foreach ($ways as $at => $declare) {
      try {
        $builder = new SurfaceBuilder();
        $declare($builder);
        $builder->seal();
        $this->fail(sprintf('The secret output "%s" was declared.', $at));
      }
      catch (\InvalidArgumentException $e) {
        $this->assertSame(sprintf('The "%s" output is a secret. An output is served to whoever runs the operation, and a secret is never read back: a secret is an input alone.', $at), $e->getMessage());
      }
    }
    // At an input, a list's secret items are each marked secret, so the
    // input secrets path leaves them out of what is read back.
    $builder = new SurfaceBuilder();
    (new ResourceBlueprint($builder, $typed, FALSE))->add('items', Schema::list(Schema::string()->writeOnly(TRUE)));
    $item = DefinitionSchema::itemOf($builder->seal()->getDefinitions()->entry('items')->schema());
    $this->assertNotNull($item);
    $this->assertTrue(Secrets::marked($item));
  }

  /**
   * Tests a list of open objects at an output is a list of open maps.
   *
   * Each item is a map that names no keys: an object, never null, an
   * object inside it refused as anywhere.
   */
  public function testListOfOpenObjectsIsAListOfOpenMaps(): void {
    $builder = new SurfaceBuilder();
    (new ResourceBlueprint($builder, $this->container->get('typed_data_manager'), TRUE))->add('rows', Schema::list(Schema::object()));
    $surface = $builder->seal();
    $served = Schemas::arrayed($this->container->get('surface.contract_emitter')->emit($surface, [], 'p')->outputs());
    $this->assertSame(['type' => 'object'], $served['properties']['rows']['items']);
    $this->assertSame([], $this->answer($surface, ['rows' => [['a' => 1], []]]));
    $this->assertSame([['rows.0', 'type']], $this->answer($surface, ['rows' => ['x']]));
    $this->assertSame([['rows.0', 'type']], $this->answer($surface, ['rows' => [NULL]]));
    $this->assertSame([['rows.0.a', 'type']], $this->answer($surface, ['rows' => [['a' => new \stdClass()]]]));
  }

  /**
   * Tests null on the items of an output's list of objects is refused.
   *
   * An item is never null in either schema, so an author's schema that
   * allows null on the items of a list of maps is refused at the part not
   * read as written, rather than served without null.
   */
  public function testNullableItemsOfAListOfMapsAreRefused(): void {
    $typed = $this->container->get('typed_data_manager');
    foreach (['a map' => Schema::object(['id' => Schema::string()->required()]), 'an open map' => Schema::object()] as $what => $item) {
      try {
        (new ResourceBlueprint(new SurfaceBuilder(), $typed, TRUE))->add('rows', Schema::list($item->with('type', ['object', 'null'])));
        $this->fail(sprintf('Null items of %s are refused.', $what));
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('/properties/rows/items/type: Not read: an item of a list is never null.', $e->getMessage(), $what);
      }
    }
  }

  /**
   * Holds outputs to a surface, each violation as its path and code.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $output
   *   The outputs.
   *
   * @return array<int, array{0: string, 1: string}>
   *   Each violation's full path and code.
   */
  protected function answer($surface, array $output): array {
    $answer = [];
    foreach ($this->pipeline()->conformOutput($surface, $output) as $violation) {
      $answer[] = [$violation->fullPath(), $violation->code];
    }
    return $answer;
  }

  /**
   * Removes the checks and their messages from a document, at every depth.
   *
   * @param array $document
   *   The document, as an array.
   *
   * @return array
   *   The same, with no `portable-check` and no `x-portable-messages`.
   */
  protected static function withoutChecks(array $document): array {
    unset($document['portable-check'], $document['x-portable-messages']);
    foreach ($document as $keyword => $value) {
      if (is_array($value)) {
        $document[$keyword] = self::withoutChecks($value);
      }
    }
    return $document;
  }

}
