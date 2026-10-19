<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\data_surface\Contract\ContractChange;
use Drupal\data_surface\Contract\ContractDiff;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the contract diff classifies each change as docs/evolution.md says.
 *
 * One row per row of the compatibility table that a schema can show, on
 * a small resource with a capacity, a venue and a contact structure.
 *
 * @see docs/evolution.md
 */
#[Group('data_surface')]
class ContractDiffTest extends UnitTestCase {

  /**
   * The baseline: a resource, one of its keys a structure's def.
   */
  protected const BASELINE = [
    'surface' => 'event',
    'situation' => 'configure',
    'schema' => [
      'x-surface' => [
        'operation' => [
          'id' => 'configure',
          'label' => 'Configure',
          'creates' => FALSE,
          'readOnly' => FALSE,
          'destructive' => FALSE,
          'idempotent' => TRUE,
        ],
        'version' => 1,
      ],
      'type' => 'object',
      'properties' => [
        'capacity' => ['title' => 'Capacity', 'type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 1000],
        'venue' => [
          'title' => 'Venue',
          'type' => 'string',
          'oneOf' => [['const' => 'hall', 'title' => 'Hall'], ['const' => 'barn', 'title' => 'Barn']],
        ],
        'code' => ['title' => 'Code', 'type' => ['string', 'null']],
        'contact' => ['$ref' => '#/$defs/event.contact', 'title' => 'Contact'],
      ],
      'required' => ['venue'],
      'additionalProperties' => FALSE,
      'allOf' => [
        [
          'if' => ['properties' => ['venue' => ['const' => 'barn']], 'required' => ['venue']],
          'then' => ['properties' => ['capacity' => ['maximum' => 80]]],
        ],
      ],
      '$defs' => [
        'event.contact' => [
          'type' => 'object',
          'properties' => ['email' => ['title' => 'Email', 'type' => ['string', 'null']]],
          'additionalProperties' => FALSE,
        ],
      ],
    ],
  ];

  /**
   * Marks a patch that removes what is at its path.
   */
  protected const REMOVE = '(removed)';

  /**
   * Tests one change is classified as the table says.
   *
   * The effect is among what is found, and the lint fails exactly when
   * the verdict is breaking: a rename is a key removed and one added.
   *
   * @param array<string, mixed> $patches
   *   What to set in a copy of the baseline's schema, by slash-separated
   *   path; self::REMOVE removes it.
   * @param string $effect
   *   The effect expected.
   * @param string $status
   *   The verdict expected.
   */
  #[DataProvider('providerChanges')]
  public function testChangeIsClassified(array $patches, string $effect, string $status): void {
    $current = self::BASELINE;
    foreach ($patches as $path => $value) {
      $current['schema'] = self::patched($current['schema'], explode('/', $path), $value);
    }
    $found = array_values(array_filter(
      (new ContractDiff())->compare(self::BASELINE, $current),
      static fn (ContractChange $change): bool => $change->effect !== ContractDiff::NOTE,
    ));
    $said = implode("\n", array_map(static fn (ContractChange $change): string => sprintf('%s: %s (%s, %s)', $change->path, $change->change, $change->effect, $change->status), $found));
    $verdicts = array_map(static fn (ContractChange $change): array => [$change->effect, $change->status], $found);
    $this->assertContains([$effect, $status], $verdicts, $said);
    $fails = array_filter($found, static fn (ContractChange $change): bool => $change->fails());
    $this->assertSame($status === ContractChange::BREAKING, $fails !== [], $said);
  }

  /**
   * Sets or removes one value at a path.
   *
   * @param array $schema
   *   The schema.
   * @param string[] $keys
   *   The path, key by key.
   * @param mixed $value
   *   The value, or self::REMOVE.
   *
   * @return array
   *   The schema, patched.
   */
  protected static function patched(array $schema, array $keys, mixed $value): array {
    $key = array_shift($keys);
    if ($keys !== []) {
      $schema[$key] = self::patched($schema[$key] ?? [], $keys, $value);
    }
    elseif ($value === self::REMOVE) {
      unset($schema[$key]);
    }
    else {
      $schema[$key] = $value;
    }
    return $schema;
  }

  /**
   * Gives one change per row of the compatibility table.
   *
   * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
   *   The patches, the effect and the verdict.
   */
  public static function providerChanges(): array {
    $compatible = ContractChange::COMPATIBLE;
    $breaking = ContractChange::BREAKING;
    $notes = ['title' => 'Notes', 'type' => ['string', 'null']];
    $tent = ['const' => 'tent', 'title' => 'Tent'];
    $when = static fn (string $venue): array => [
      'properties' => ['venue' => ['const' => $venue]],
      'required' => ['venue'],
    ];
    return [
      'an optional key with a default added' => [
        ['properties/notes' => $notes + ['default' => 'None']],
        ContractDiff::ADDS,
        $compatible,
      ],
      'an optional key without a default added' => [['properties/notes' => $notes], ContractDiff::ADDS, $compatible],
      'a constraint widened' => [['properties/capacity/maximum' => 2000], ContractDiff::WIDENS, $compatible],
      'a choice added' => [['properties/venue/oneOf/2' => $tent], ContractDiff::WIDENS, $compatible],
      'a variant added' => [
        [
          'properties/venue/oneOf/2' => $tent,
          'allOf/1' => ['if' => $when('tent'), 'then' => ['properties' => ['capacity' => ['maximum' => 40]]]],
        ],
        ContractDiff::WIDENS,
        $compatible,
      ],
      'a required key made optional' => [['required' => []], ContractDiff::WIDENS, $compatible],
      'a rule dropped' => [['allOf' => []], ContractDiff::WIDENS, $compatible],
      'a mixin with an optional member added' => [
        [
          'properties/created' => TRUE,
          'allOf/1' => ['$ref' => '#/$defs/event.stamps'],
          '$defs/event.stamps' => ['type' => 'object', 'properties' => ['created' => ['type' => ['integer', 'null']]]],
        ],
        ContractDiff::ADDS,
        $compatible,
      ],
      'a key removed' => [['properties/code' => self::REMOVE], ContractDiff::BREAKS, $breaking],
      'a key renamed' => [
        ['properties/code' => self::REMOVE, 'properties/licence' => ['title' => 'Code', 'type' => ['string', 'null']]],
        ContractDiff::BREAKS,
        $breaking,
      ],
      'a required key added' => [
        ['properties/notes' => ['title' => 'Notes', 'type' => 'string'], 'required' => ['venue', 'notes']],
        ContractDiff::NARROWS,
        $breaking,
      ],
      'a constraint tightened' => [['properties/capacity/maximum' => 500], ContractDiff::NARROWS, $breaking],
      'a pattern added' => [['properties/code/pattern' => '^[A-Z]{2}$'], ContractDiff::NARROWS, $breaking],
      'a choice removed' => [['properties/venue/oneOf/1' => self::REMOVE], ContractDiff::NARROWS, $breaking],
      'an optional key made required' => [['required' => ['venue', 'code']], ContractDiff::NARROWS, $breaking],
      'a type changed' => [['properties/capacity/type' => ['string', 'null']], ContractDiff::BREAKS, $breaking],
      'null no longer allowed' => [['properties/capacity/type' => 'integer'], ContractDiff::NARROWS, $breaking],
      'a rule added on a value the baseline allowed' => [
        ['allOf/1' => ['if' => $when('hall'), 'then' => ['required' => ['code']]]],
        ContractDiff::NARROWS,
        $breaking,
      ],
      'a rule tightened' => [['allOf/0/then/properties/capacity/maximum' => 60], ContractDiff::NARROWS, $breaking],
      'a constraint only the server checks added' => [
        ['properties/code/x-surface' => ['checkedOnServer' => ['UniqueCode']]],
        ContractDiff::NARROWS,
        $breaking,
      ],
      'a key inside a structure removed' => [
        ['$defs/event.contact/properties/email' => self::REMOVE],
        ContractDiff::BREAKS,
        $breaking,
      ],
      'a required port added' => [
        ['$defs/event.contact/x-surface' => ['ports' => ['seats' => ['type' => 'integer', 'required' => TRUE]]]],
        ContractDiff::BREAKS,
        $breaking,
      ],
      'the operation made destructive' => [['x-surface/operation/destructive' => TRUE], ContractDiff::BREAKS, $breaking],
      'a keyword the lint does not read' => [
        ['properties/code/if' => ['minLength' => 3]],
        ContractDiff::BREAKS,
        $breaking,
      ],
    ];
  }

  /**
   * Tests a label, a description and a default only note.
   */
  public function testWordsAndDefaultsOnlyNote(): void {
    $current = self::BASELINE;
    $current['schema']['properties']['capacity']['title'] = 'Seats';
    $current['schema']['properties']['capacity']['description'] = 'How many may come.';
    $current['schema']['properties']['capacity']['default'] = 40;
    $current['schema']['properties']['venue']['oneOf'][0]['title'] = 'Main hall';
    $changes = (new ContractDiff())->compare(self::BASELINE, $current);
    $this->assertCount(4, $changes);
    foreach ($changes as $change) {
      $this->assertSame([ContractDiff::NOTE, ContractChange::COMPATIBLE], [$change->effect, $change->status], $change->change);
    }
  }

  /**
   * Tests whose version answers for a breaking change.
   *
   * The document's for its own keys, a structure's for the keys of its
   * def, and an allowance for its exact path.
   */
  public function testTheOwnersVersionOrAnAllowanceAnswers(): void {
    $diff = new ContractDiff();
    $current = self::BASELINE;
    $current['schema']['properties']['capacity']['maximum'] = 500;
    unset($current['schema']['$defs']['event.contact']['properties']['email']);
    $verdicts = static fn (array $changes): array => array_map(static fn (ContractChange $change): string => $change->owner . ' ' . $change->change . ': ' . $change->status, $changes);

    $this->assertSame([
      'event maximum lowered from 1000 to 500: breaking',
      'event.contact key removed: breaking',
    ], $verdicts($diff->compare(self::BASELINE, $current)));

    $current['schema']['x-surface']['version'] = 2;
    $this->assertSame([
      'event maximum lowered from 1000 to 500: versioned',
      'event.contact key removed: breaking',
      'event version raised from 1 to 2: compatible',
    ], $verdicts($diff->compare(self::BASELINE, $current)));

    $current['schema']['$defs']['event.contact']['x-surface'] = ['version' => 2];
    $allowed = ['configure#/properties/capacity/maximum' => 'No room seats more than 500.'];
    $current['schema']['x-surface']['version'] = 1;
    $this->assertSame([
      'event maximum lowered from 1000 to 500: allowed',
      'event.contact key removed: versioned',
      'event.contact version raised from 1 to 2: compatible',
    ], $verdicts($diff->compare(self::BASELINE, $current, $allowed)));

    $lowered = self::BASELINE;
    $lowered['schema']['x-surface']['version'] = 2;
    $this->assertSame(['event version lowered from 2 to 1: breaking'], $verdicts($diff->compare($lowered, self::BASELINE)));
  }

  /**
   * Tests outputs are judged the other way round.
   *
   * A client reads them: a bound widened may hand it what it did not
   * expect, and one tightened hands it less.
   */
  public function testOutputsAreJudgedAsRead(): void {
    $id = ['type' => 'integer', 'maximum' => 100];
    $baseline = self::BASELINE + ['outputs' => ['type' => 'object', 'properties' => ['id' => $id]]];
    $wider = $baseline;
    $wider['outputs']['properties']['id']['maximum'] = 200;
    $narrower = $baseline;
    $narrower['outputs']['properties']['id']['maximum'] = 50;
    $diff = new ContractDiff();
    $this->assertSame(ContractChange::BREAKING, $diff->compare($baseline, $wider)[0]->status);
    $this->assertSame(ContractChange::COMPATIBLE, $diff->compare($baseline, $narrower)[0]->status);
  }

  /**
   * Tests a version above 1 is refused until migrations run.
   */
  public function testVersionAboveOneIsRefusedForNow(): void {
    $version = new \ReflectionMethod(ResourceRegistry::class, 'versionOf');
    $this->assertSame(1, $version->invoke(NULL, self::class, 1));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('A version above 1 needs its stored-value migrations');
    $version->invoke(NULL, self::class, 2);
  }

}
