<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\Schemas;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\Validation\ApplicableSchemas;
use Drupal\Tests\surface\HandCorpus\DoorSurfaces;
use Drupal\Tests\surface\HandCorpus\HandSurfaces;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Registry;
use PortableSchema\Validator;

/**
 * Tests ApplicableSchemas reads each keyword as the dialect's validator does.
 *
 * ApplicableSchemas decides which `drupal:` checks are left unasked: a
 * check is not run on a value that `type`, `pattern`, `maxLength`,
 * `minLength` or `format` refuses at the same place. A check skipped
 * inside an `anyOf` or `oneOf` branch causes no second run of the
 * validator (CheckInBranchTest), so a keyword it reads otherwise than the
 * validator inside a branch is not caught when a request runs. This test
 * catches it instead: at each place of each document, for each schema
 * that applies there, certain or in a branch, the verdict of
 * ApplicableSchemas (that schema refuses the value or not) is held to the
 * dialect's own errors for that schema alone, over those five keywords.
 *
 * The documents: every document of the contract baselines
 * (tests/fixtures/contracts), and the document validate() holds each case
 * of the hand corpus and the door corpus to (HandSurfaces, DoorSurfaces),
 * with its values; and a hand set of branches of mixed types. A document
 * with no values behind it is held over probe values: each schema of it
 * that writes one of the five keywords, at any depth, with values of each
 * JSON type and strings at each length bound.
 *
 * @see docs/decisions.md#applicableschemas-is-held-to-the-dialect-by-a-test
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ApplicableSchemasVerdictTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'tool',
    'surface',
    'surface_widget_test',
  ];

  /**
   * The keywords ApplicableSchemas reads to refuse a value.
   */
  protected const READ = ['type', 'pattern', 'maxLength', 'minLength', 'format'];

  /**
   * The keywords that hold schemas by name, or a list of schemas.
   */
  protected const SCHEMAS_BY_NAME = [
    'properties',
    '$defs',
    'definitions',
    'patternProperties',
    'dependentSchemas',
    'allOf',
    'anyOf',
    'oneOf',
    'prefixItems',
  ];

  /**
   * The keywords that hold one schema.
   */
  protected const SCHEMA_AT = [
    'items',
    'additionalProperties',
    'not',
    'if',
    'then',
    'else',
    'contains',
    'propertyNames',
  ];

  /**
   * The values every schema is probed with, but the two maps.
   */
  protected const PROBES = [
    NULL, TRUE, FALSE, 0, 1, -1, 1.0, 1.5,
    '', 'a', 'ab', 'abc', 'abcd', 'abcde', 'A b', 'ß', '😀😀', '1', 'true',
    [], ['a'], [1, 2],
    'a@example.com', 'not an email', 'https://example.com/x', 'internal:/node/1', 'example.com',
    '2024-01-02', '2024-02-30', '2024-01-02T03:04:05Z', '2024-01-02T03:04:05',
    '00000000-0000-4000-8000-000000000000', 'g0000000-0000-4000-8000-000000000000',
  ];

  /**
   * The dialect's verdict for each schema and value, by both encoded.
   *
   * @var array<string, bool>
   */
  protected array $dialect = [];

  /**
   * The places compared, by source.
   *
   * @var array<string, int>
   */
  protected array $compared = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['surface']);
    User::create(['name' => 'one', 'status' => 1])->save();
  }

  /**
   * Tests each document of the contract baselines, over probe values.
   */
  public function testContractBaselines(): void {
    $differ = [];
    $files = glob(dirname(__DIR__, 2) . '/fixtures/contracts/*.json') ?: [];
    $this->assertNotEmpty($files);
    foreach ($files as $file) {
      $baseline = json_decode((string) file_get_contents($file), TRUE, flags: JSON_THROW_ON_ERROR);
      foreach ($baseline['documents'] ?? [] as $n => $document) {
        $differ = [...$differ, ...$this->probed($document['schema'] ?? [], basename($file) . " #$n")];
      }
    }
    $this->assertSame([], array_slice($differ, 0, 40), count($differ) . ' verdicts differ.');
    $this->assertGreaterThan(1000, $this->compared['probe'] ?? 0);
  }

  /**
   * Tests the document of each case of the hand and door corpora.
   */
  public function testHandAndDoorCorpora(): void {
    $differ = [];
    $built = [];
    foreach (HandSurfaces::NAMES as $name) {
      $built["hand $name"] = HandSurfaces::build($name);
    }
    foreach (DoorSurfaces::NAMES as $name) {
      $built["door $name"] = DoorSurfaces::build($name);
    }
    $pipeline = $this->pipeline();
    $validator = $this->container->get('surface.portable_validator');
    $emitter = $this->container->get('surface.contract_emitter');
    foreach ($built as $source => [$surface, $stored, $cases, $alternates]) {
      $forms = ['plain' => [$surface, $stored, FALSE]] + $alternates;
      foreach ($forms as $form => [$each, $held, $stored_only]) {
        $currents = $stored_only ? ['stored' => $held] : ['none' => [], 'stored' => $held];
        foreach ($currents as $current_name => $current) {
          $probed = FALSE;
          foreach (['nothing' => []] + $cases as $case => $input) {
            try {
              $values = $pipeline->accept($each, $input, $current);
              $document = $emitter->emit($each, $values, 'surface.validate')->schema();
              $data = $validator->provider($each, $values, $pipeline)->document();
            }
            catch (\Throwable) {
              // A case accept() or the document refuses reaches no
              // validator.
              continue;
            }
            $decoded = Schemas::decoded($document);
            $decoded = is_array($decoded) ? $decoded : [];
            $at = "$source | $form | $current_name | $case";
            $differ = [...$differ, ...$this->walked($decoded, $data, $at)];
            if (!$probed) {
              $differ = [...$differ, ...$this->probed($decoded, "$at (probes)")];
              $probed = TRUE;
            }
          }
        }
      }
    }
    $this->assertSame([], array_slice($differ, 0, 40), count($differ) . ' verdicts differ.');
    $this->assertGreaterThan(1000, $this->compared['walk'] ?? 0);
  }

  /**
   * Tests a hand set of branches of mixed types.
   *
   * The shapes of CheckInBranchTest and of the review of slice 34 (S2): a
   * check named in one branch of an `anyOf` or `oneOf`, a boolean or an
   * integer in the other, nested branches, a branch reached by `$ref`, and
   * a conditional's `then` and `else`.
   */
  public function testMixedTypeBranches(): void {
    $check = ['portable-check' => ['drupal:EntityBundleExists' => ['entityTypeId' => 'user']]];
    $string = ['type' => 'string', 'minLength' => 2, 'maxLength' => 4, 'pattern' => '^[a-z]+$'] + $check;
    $boolean = ['type' => 'boolean'];
    $b = static fn (array $schema): array => ['type' => 'object', 'properties' => ['b' => $schema]];
    $documents = [
      'anyOf string or boolean' => $b(['anyOf' => [$string, $boolean]]),
      'oneOf string or integer' => $b(['oneOf' => [$string, ['type' => 'integer', 'maximum' => 9]]]),
      'nested anyOf' => $b([
        'anyOf' => [
          ['anyOf' => [$string, ['type' => 'null']]],
          ['type' => ['boolean', 'number']],
        ],
      ]),
      'branch by ref' => [
        '$defs' => ['s' => $string, 'u' => ['type' => 'string', 'format' => 'uuid']],
      ] + $b(['anyOf' => [['$ref' => '#/$defs/s'], ['$ref' => '#/$defs/u'], $boolean]]),
      'then and else' => [
        'type' => 'object',
        'properties' => ['kind' => ['type' => 'string'], 'b' => []],
        'if' => ['properties' => ['kind' => ['const' => 's']]],
        'then' => ['properties' => ['b' => $string]],
        'else' => ['properties' => ['b' => $boolean]],
      ],
      'variants of a map' => [
        'type' => 'object',
        'properties' => [
          'settings' => [
            'anyOf' => [
              ['type' => 'object', 'properties' => ['x' => $string]],
              ['type' => 'object', 'properties' => ['x' => $boolean]],
              ['type' => 'null'],
            ],
          ],
          'other' => $string,
        ],
      ],
      'formats in branches' => $b([
        'anyOf' => [
          ['type' => 'string', 'format' => 'email'],
          ['type' => 'string', 'format' => 'date-time'],
          ['type' => 'string', 'format' => 'date'],
          ['type' => 'string', 'format' => 'uri'],
          ['type' => 'integer'],
        ],
      ]),
      'list of mixed items' => $b(['type' => 'array', 'items' => ['anyOf' => [$string, $boolean]]]),
    ];
    // The data each document is walked with: the probe at each place.
    $placed = static fn (mixed $probe): array => [
      ['b' => $probe, 'kind' => 's'],
      ['b' => $probe, 'kind' => 'x'],
      ['settings' => (object) ['x' => $probe], 'other' => $probe],
      ['b' => [$probe, 'ab']],
    ];
    $differ = [];
    foreach ($documents as $label => $document) {
      foreach (self::probes($document) as $n => $probe) {
        foreach ($placed($probe) as $m => $data) {
          $differ = [...$differ, ...$this->walked($document, (object) $data, "$label | probe $n | data $m")];
        }
      }
      $differ = [...$differ, ...$this->probed($document, "$label (probes)")];
    }
    $this->assertSame([], array_slice($differ, 0, 40), count($differ) . ' verdicts differ.');
  }

  /**
   * Compares the verdicts at each place of a document with its data.
   *
   * @param array $document
   *   The document, decoded as arrays.
   * @param mixed $data
   *   The data, as the validator is handed it.
   * @param string $source
   *   What is compared, for the message.
   *
   * @return string[]
   *   One line for each verdict that differs.
   */
  protected function walked(array $document, mixed $data, string $source): array {
    $schemas = new ApplicableSchemas($document, $data);
    $places = (fn (): array => $this->at)->call($schemas);
    $differ = [];
    foreach ($places as $location => $applying) {
      $value = self::valueAt($data, (string) $location);
      foreach ($applying as [$schema, , $branch]) {
        $this->compared['walk'] = ($this->compared['walk'] ?? 0) + 1;
        $line = $this->compare($schema, $value);
        if ($line !== NULL) {
          $differ[] = "$source | at '$location' in branch '$branch': $line";
        }
      }
    }
    return $differ;
  }

  /**
   * Compares the verdicts of each schema of a document over probe values.
   *
   * @param mixed $document
   *   The document, decoded as arrays.
   * @param string $source
   *   What is compared, for the message.
   *
   * @return string[]
   *   One line for each verdict that differs.
   */
  protected function probed(mixed $document, string $source): array {
    $differ = [];
    foreach (self::schemasIn($document) as $pointer => $schema) {
      foreach (self::probes($schema) as $probe) {
        $this->compared['probe'] = ($this->compared['probe'] ?? 0) + 1;
        $line = $this->compare($schema, $probe);
        if ($line !== NULL) {
          $differ[] = "$source | schema at '$pointer': $line";
        }
      }
    }
    return $differ;
  }

  /**
   * Compares one schema's verdict on one value with the dialect's.
   *
   * @param array $schema
   *   The schema, decoded as arrays.
   * @param mixed $value
   *   The value, as the validator is handed it.
   *
   * @return string|null
   *   What differs, or NULL when both agree.
   */
  protected function compare(array $schema, mixed $value): ?string {
    $read = array_intersect_key($schema, array_flip(self::READ));
    // The one reading of a schema every verdict of ApplicableSchemas is
    // made of, private to it.
    $refuses = \Closure::bind(static fn (array $schema, mixed $value): bool => self::refuses($schema, $value), NULL, ApplicableSchemas::class);
    $ours = $refuses($read, $value);
    $theirs = $this->dialectRefuses($read, $value);
    if ($ours === $theirs) {
      return NULL;
    }
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
    return sprintf('%s on %s: ApplicableSchemas says %s, the dialect %s.', json_encode($read, $flags), json_encode($value, $flags), $ours ? 'refused' : 'taken', $theirs ? 'refused' : 'taken');
  }

  /**
   * Answers whether the dialect's validator refuses a value by a schema.
   *
   * @param array $schema
   *   The schema, of the keywords READ names alone.
   * @param mixed $value
   *   The value, as the validator is handed it.
   *
   * @return bool
   *   TRUE when the validator files an error.
   */
  protected function dialectRefuses(array $schema, mixed $value): bool {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
    $encoded = (string) json_encode($schema === [] ? new \stdClass() : $schema, $flags);
    $key = $encoded . "\n" . serialize($value);
    if (!isset($this->dialect[$key])) {
      $id = 'urn:surface:verdict:' . hash('xxh128', $encoded);
      $registry = (new Registry())->add(json_decode($encoded), $id);
      $this->dialect[$key] = !(new Validator($registry))->validate($value, $id)->valid;
    }
    return $this->dialect[$key];
  }

  /**
   * Lists every schema of a document that writes a keyword READ names.
   *
   * @param mixed $schema
   *   The document, or a schema of it, decoded as arrays.
   * @param string $pointer
   *   Where it stands in the document.
   *
   * @return array<string, array>
   *   The schemas, by where each stands.
   */
  protected static function schemasIn(mixed $schema, string $pointer = ''): array {
    if (!is_array($schema)) {
      return [];
    }
    $found = array_intersect_key($schema, array_flip(self::READ)) === [] ? [] : [$pointer => $schema];
    foreach ($schema as $keyword => $member) {
      if (!is_array($member)) {
        continue;
      }
      // A map of schemas by name, or a list of schemas.
      if (in_array($keyword, self::SCHEMAS_BY_NAME, TRUE)) {
        foreach ($member as $name => $one) {
          $found += self::schemasIn($one, "$pointer/$keyword/$name");
        }
        continue;
      }
      if (in_array($keyword, self::SCHEMA_AT, TRUE)) {
        $found += self::schemasIn($member, "$pointer/$keyword");
      }
    }
    return $found;
  }

  /**
   * Gives the values a schema is probed with.
   *
   * One of each JSON type, the values the schema writes (`const`, `enum`,
   * `default`, `examples`), strings at each side of its length bounds, and
   * strings near each format.
   *
   * @param array $schema
   *   The schema, decoded as arrays.
   *
   * @return list<mixed>
   *   The values, as the validator is handed them.
   */
  protected static function probes(array $schema): array {
    $probes = [...self::PROBES, new \stdClass(), (object) ['a' => 1]];
    foreach (['minLength', 'maxLength'] as $bound) {
      if (is_int($schema[$bound] ?? NULL)) {
        foreach ([-1, 0, 1] as $step) {
          $length = $schema[$bound] + $step;
          if ($length >= 0 && $length < 5000) {
            $probes[] = str_repeat('x', $length);
            $probes[] = str_repeat('é', $length);
          }
        }
      }
    }
    foreach (['const', 'default'] as $keyword) {
      if (array_key_exists($keyword, $schema)) {
        $probes[] = self::asHanded($schema[$keyword]);
      }
    }
    foreach (['enum', 'examples'] as $keyword) {
      foreach (is_array($schema[$keyword] ?? NULL) ? $schema[$keyword] : [] as $one) {
        $probes[] = self::asHanded($one);
      }
    }
    return $probes;
  }

  /**
   * Writes a decoded value as the validator is handed it: maps as objects.
   *
   * @param mixed $value
   *   The value, decoded as arrays.
   *
   * @return mixed
   *   The value, maps as objects.
   */
  protected static function asHanded(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    $each = array_map(self::asHanded(...), $value);
    return $value !== [] && !array_is_list($value) ? (object) $each : $each;
  }

  /**
   * Reads the value at a place of the data.
   *
   * @param mixed $data
   *   The data, as the validator is handed it.
   * @param string $location
   *   The place, a JSON Pointer.
   *
   * @return mixed
   *   The value there.
   */
  protected static function valueAt(mixed $data, string $location): mixed {
    foreach (Location::segments($location) as $segment) {
      $data = match (TRUE) {
        $data instanceof \stdClass => $data->{$segment},
        is_array($data) => $data[$segment],
        default => throw new \LogicException("No value at $location."),
      };
    }
    return $data;
  }

}
