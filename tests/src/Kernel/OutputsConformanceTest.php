<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
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
   * Tests a secret output is refused, however it is declared.
   *
   * Each way one could be declared, refused at seal at the latest: the
   * builder's own, setSecret() on the definition a blueprint's add()
   * returns, and add() with `writeOnly`, at the top and in a map.
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
        DefinitionMetadata::setSecret((new ResourceBlueprint($builder, $typed, TRUE))->add('added', 'string', 'Token'));
      },
      'schema' => static function (SurfaceBuilder $builder) use ($typed): void {
        (new ResourceBlueprint($builder, $typed, TRUE))->add('schema', Schema::string()->title('Token')->writeOnly(TRUE));
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
