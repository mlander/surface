<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\ConstraintKeywords;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\Keywords\BoundsRow;
use Drupal\surface\Contract\Keywords\ReadBack;
use Drupal\surface\Contract\LeafTypes;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the one table of constraints and their keywords, both ways.
 *
 * Every row is written with representative options and read back: the
 * keywords it writes, the constraint the reader reads from them, and the
 * same keywords written again. A row against a type it does not apply to
 * is left for the emitter to name under `portable-check`, never dropped;
 * two rows writing one keyword merge by the table's rule, in either
 * order.
 *
 * @see \Drupal\surface\Contract\ConstraintKeywords
 * @see docs/decisions.md#one-table-maps-a-constraint-to-its-keywords-both-ways
 */
#[Group('surface')]
class ConstraintKeywordsTest extends UnitTestCase {

  /**
   * Each row's cases: what is written, and what is read back.
   *
   * @return array<int, array{0: string, 1: array, 2: string[], 3: array, 4: string|null, 5: array, 6?: array}>
   *   The constraint, its options, the JSON types, the keywords written,
   *   the constraint read back and its options, and the extras written.
   */
  protected static function cases(): array {
    $lower = ['pattern' => '/^[a-z]+$/D', 'message' => 'Lower case.'];
    $digits = ['pattern' => '/^\d+$/D', 'match' => FALSE, 'message' => 'Not only digits.'];
    $both = ['constraints' => [['Length' => ['min' => 2]], ['Regex' => ['pattern' => '/^[a-z]+$/D']]]];
    $either = ['constraints' => [['Length' => ['max' => 2]], ['Regex' => ['pattern' => '/^\d+$/D', 'match' => FALSE]]]];
    return [
      [
        'Range', ['min' => 1, 'max' => '400'], ['integer'],
        ['minimum' => 1, 'maximum' => 400], 'Range', ['min' => 1, 'max' => 400],
      ],
      ['Range', ['min' => 0.5], ['number', 'null'], ['minimum' => 0.5], 'Range', ['min' => 0.5]],
      [
        'Length', ['min' => 2, 'max' => 8], ['string'],
        ['minLength' => 2, 'maxLength' => 8], 'Length', ['min' => 2, 'max' => 8],
      ],
      // Two equal bounds read back as `min` and `max`, which write the
      // same keywords again.
      [
        'Length', ['exactly' => 5], ['string'],
        ['minLength' => 5, 'maxLength' => 5], 'Length', ['min' => 5, 'max' => 5],
      ],
      // Beside a bound, Symfony ignores `exactly`, and so does the row.
      [
        'Length', ['exactly' => 5, 'min' => 3], ['string'],
        ['minLength' => 3], 'Length', ['min' => 3],
      ],
      [
        'Count', ['min' => 1, 'max' => 3], ['array'],
        ['minItems' => 1, 'maxItems' => 3], 'Count', ['min' => 1, 'max' => 3],
      ],
      [
        'Count', ['exactly' => 2], ['array'],
        ['minItems' => 2, 'maxItems' => 2], 'Count', ['min' => 2, 'max' => 2],
      ],
      [
        'Count', ['exactly' => 2, 'max' => 5], ['array'],
        ['maxItems' => 5], 'Count', ['max' => 5],
      ],
      ['UniqueItems', [], ['array'], ['uniqueItems' => TRUE], 'UniqueItems', []],
      // NotBlank reads back as the literal Length: the document is
      // stable, the constraint id is not.
      ['NotBlank', [], ['string'], ['minLength' => 1], 'Length', ['min' => 1]],
      // Email reads back as the email leaf type (LeafTypes), no constraint.
      ['Email', [], ['string'], ['format' => 'email'], NULL, []],
      [
        'Regex', $lower, ['string'],
        ['pattern' => '^[a-z]+$'], 'Regex', $lower, ['messages' => ['pattern' => 'Lower case.']],
      ],
      [
        'Regex', $digits, ['string'],
        ['not' => ['pattern' => '^\d+$']], 'Regex', $digits, ['messages' => ['not' => 'Not only digits.']],
      ],
      // Symfony holds `match` as a boolean: 0 is FALSE.
      [
        'Regex', ['pattern' => '/^\d+$/D', 'match' => 0], ['string'],
        ['not' => ['pattern' => '^\d+$']], 'Regex', ['pattern' => '/^\d+$/D', 'match' => FALSE],
      ],
      ['Uuid', [], ['string'], ['format' => 'uuid'], 'Uuid', []],
      [
        'Sequentially', $both, ['string'],
        ['allOf' => [['minLength' => 2], ['pattern' => '^[a-z]+$']]], 'Sequentially', $both,
      ],
      [
        'AtLeastOneOf', $either, ['string'],
        ['anyOf' => [['maxLength' => 2], ['not' => ['pattern' => '^\d+$']]]], 'AtLeastOneOf', $either,
      ],
    ];
  }

  /**
   * Tests every row: written, read back, and written again the same.
   */
  public function testEveryRowWritesAndReadsBack(): void {
    $covered = [];
    foreach (static::cases() as $case) {
      [$id, $options, $types, $keywords, $read_as, $read_options] = $case;
      $extras = $case[6] ?? [];
      $covered[$id] = TRUE;
      $stated = ConstraintKeywords::state([$id => $options], $types);
      $this->assertSame($keywords, $stated['keywords'], $id . ' writes its keywords.');
      $this->assertSame($extras, $stated['extras'], $id . ' writes its extras.');
      // Email and Uuid are stated by their format, and named beside it.
      $twin = isset(ConstraintKeywords::FORMAT_TWINS[$id]);
      $this->assertSame($twin ? [[$id, $options, TRUE]] : [], $stated['left'], $id . ($twin ? ' is left too, for portable-check beside its format.' : ' is stated whole.'));

      $schema = $keywords + ($extras === [] ? [] : [PortableKeywords::MESSAGES => $extras['messages']]);
      $read = array_values(array_filter(ConstraintKeywords::read($schema, $types), static fn (ReadBack $back): bool => $back->constraint !== NULL));
      if ($read_as === NULL) {
        $this->assertSame([], $read, $id . ' is read by the leaf type, not the table.');
        $this->assertNotNull(LeafTypes::read($types[0], $keywords['format'] ?? NULL), $id . ' is read by a leaf type.');
        $this->assertNotSame(LeafTypes::read($types[0]), LeafTypes::read($types[0], $keywords['format'] ?? NULL), $id . ' is read by a leaf type of its own.');
        continue;
      }
      $this->assertCount(1, $read, $id . ' reads back as one constraint.');
      $this->assertSame($read_as, $read[0]->constraint, $id . ' reads back as the constraint its row documents.');
      $this->assertSame($read_options, $read[0]->options, $id . ' reads back its options.');
      $this->assertSame([], $read[0]->kept, $id . ' keeps nothing.');

      $again = ConstraintKeywords::state([$read_as => $read_options], $types);
      $this->assertSame($keywords, $again['keywords'], $id . ' written, read and written again gives the same keywords.');
    }
    $this->assertSame([], array_diff(ConstraintKeywords::ids(), array_keys($covered)), 'Every row has a case.');
  }

  /**
   * Tests a row against a type it does not apply to is left, never dropped.
   */
  public function testRowNotApplyingIsLeft(): void {
    $cases = [
      'Range' => [['min' => 1], ['string']],
      'Length' => [['max' => 5], ['integer']],
      'Count' => [['max' => 5], ['string']],
      'UniqueItems' => [[], ['string']],
      'NotBlank' => [[], ['array']],
      'Email' => [[], ['integer']],
      'Regex' => [['pattern' => '/^a$/D'], ['integer']],
      'Uuid' => [[], ['boolean']],
      'Sequentially' => [['constraints' => [['Length' => ['min' => 1]]]], ['integer']],
      'AtLeastOneOf' => [['constraints' => [['Range' => ['min' => 1]]]], ['string']],
    ];
    $this->assertSame(ConstraintKeywords::ids(), array_keys($cases), 'Every row has a type it does not apply to.');
    foreach ($cases as $id => [$options, $types]) {
      $stated = ConstraintKeywords::state([$id => $options], $types);
      $this->assertSame([], $stated['keywords'], $id . ' writes no keyword on ' . $types[0] . '.');
      $this->assertSame([[$id, $options, TRUE]], $stated['left'], $id . ' is left, for portable-check.');
    }

    // Options a row cannot state are left too, and a part of them leaves
    // what it can say written beside.
    $unstated_options = ['Range' => ['min' => 'now'], 'Length' => ['max' => 4, 'normalizer' => 'trim']];
    $left = ConstraintKeywords::state($unstated_options, ['string', 'integer']);
    $this->assertSame([], $left['keywords']);
    $this->assertSame(['Range', 'Length'], array_column($left['left'], 0));
    $partly = ConstraintKeywords::state(['Range' => ['min' => 1, 'maxPropertyPath' => 'end']], ['integer']);
    $this->assertSame(['minimum' => 1], $partly['keywords']);
    $this->assertSame(['Range'], array_column($partly['left'], 0), 'Stated in part, and named besides.');
    $unknown = ConstraintKeywords::state(['Choice' => ['choices' => ['a']]], ['string']);
    $this->assertSame([['Choice', ['choices' => ['a']], FALSE]], $unknown['left'], 'A constraint no row maps is the caller\'s.');

    // A Regex no pattern carries has no keyword to give its message
    // under: the check it is left for says it.
    $anything = ['Regex' => ['pattern' => '/^.+$/', 'message' => 'Anything.']];
    $regex = ConstraintKeywords::state($anything, ['string']);
    $this->assertSame([], $regex['keywords']);
    $this->assertSame([], $regex['extras']);
    $this->assertSame(['Regex'], array_column($regex['left'], 0));

    // Read on a type it does not apply to, a row's keywords are kept.
    $read = ConstraintKeywords::read(['minimum' => 1, 'minLength' => 2], ['string']);
    $this->assertSame([NULL, 'Length'], array_map(static fn (ReadBack $back): ?string => $back->constraint, $read));
    $this->assertSame(['minimum' => 1], $read[0]->kept);
  }

  /**
   * Tests two rows on one keyword merge the same in either order.
   */
  public function testMergingIsOrderIndependent(): void {
    $pairs = [
      [['NotBlank' => []], ['Length' => ['min' => 0]], ['minLength' => 1]],
      [
        ['NotBlank' => []], ['Length' => ['min' => 3, 'max' => 9]],
        ['minLength' => 3, 'maxLength' => 9],
      ],
      [['UniqueItems' => []], ['Count' => ['min' => 1]], ['minItems' => 1, 'uniqueItems' => TRUE]],
      [
        ['Sequentially' => ['constraints' => [['Length' => ['min' => 4]]]]], ['NotBlank' => []],
        ['allOf' => [['minLength' => 4]], 'minLength' => 1],
      ],
    ];
    foreach ($pairs as [$one, $other, $merged]) {
      $forward = ConstraintKeywords::state($one + $other, ['string', 'array']);
      $backward = ConstraintKeywords::state($other + $one, ['string', 'array']);
      $sorted = static function (array $keywords): array {
        ksort($keywords);
        return $keywords;
      };
      $this->assertSame($sorted($merged), $sorted($forward['keywords']));
      $this->assertSame($sorted($forward['keywords']), $sorted($backward['keywords']), 'The same keywords in either order.');
      $this->assertSame([], $forward['left']);
      $this->assertSame([], $backward['left']);
    }
    $last = ConstraintKeywords::state(['UniqueItems' => [], 'Count' => ['min' => 1]], ['array'])['keywords'];
    $this->assertSame('uniqueItems', array_key_last($last), 'uniqueItems is written last, where the reader reads it.');
    // A keyword two rows write differently and no rule merges: the
    // later is left.
    $clash = ConstraintKeywords::state(['Uuid' => []], ['string'], ['type' => 'string', 'format' => 'email']);
    $this->assertSame(['type' => 'string', 'format' => 'email'], $clash['keywords']);
    $this->assertSame(['Uuid'], array_column($clash['left'], 0));
  }

  /**
   * Tests a keyword no rule merges is decided by the table's order.
   *
   * Email and Uuid both write `format`: Email stands first in the table,
   * so it is written and Uuid is left, whichever was added first. Both
   * are left for `portable-check`: Email beside its format, Uuid alone.
   */
  public function testClashIsDecidedByTheTableOrder(): void {
    $forward = ConstraintKeywords::state(['Email' => [], 'Uuid' => []], ['string']);
    $backward = ConstraintKeywords::state(['Uuid' => [], 'Email' => []], ['string']);
    $this->assertSame(['format' => 'email'], $forward['keywords']);
    $this->assertSame($forward['keywords'], $backward['keywords'], 'One document in either order.');
    $this->assertSame(['Email', 'Uuid'], array_column($forward['left'], 0));
    $this->assertSame(['Uuid', 'Email'], array_column($backward['left'], 0));
    $this->assertSame(['Email'], ConstraintKeywords::accepted(['Uuid' => [], 'Email' => []], ['string']));
  }

  /**
   * Tests every merge rule: either order, the stricter bound.
   *
   * The bounds are taken from the rows themselves, each written once with
   * a looser and once with a tighter value, so removing an entry of MERGE
   * leaves the later constraint unstated and fails here; an entry no row
   * exercises fails the last assertion.
   */
  public function testEveryMergeRule(): void {
    $exercised = [];
    foreach (ConstraintKeywords::rows() as $id => $row) {
      if (!$row instanceof BoundsRow) {
        continue;
      }
      $types = $id === 'Range' ? ['integer'] : ($id === 'Length' ? ['string'] : ['array']);
      foreach (['min' => [2, 5, 5], 'max' => [9, 6, 6]] as $option => [$loose, $tight, $stricter]) {
        $one = ConstraintKeywords::write($id, [$option => $loose], $types);
        $other = ConstraintKeywords::write($id, [$option => $tight], $types);
        $keyword = (string) array_key_first($one->keywords);
        $forward = ConstraintKeywords::merge(ConstraintKeywords::merge([], $one->keywords), $other->keywords);
        $backward = ConstraintKeywords::merge(ConstraintKeywords::merge([], $other->keywords), $one->keywords);
        $this->assertSame([$keyword => $stricter], $forward, $keyword . ' merges to the stricter bound.');
        $this->assertSame($forward, $backward, $keyword . ' merges the same in either order.');
        $exercised[] = $keyword;
      }
    }
    // Two composites of all their parts: every part holds, in either
    // order of the parts.
    $first = ConstraintKeywords::write('Sequentially', ['constraints' => [['Length' => ['min' => 1]]]], ['string']);
    $second = ConstraintKeywords::write('Sequentially', ['constraints' => [['Length' => ['max' => 4]]]], ['string']);
    $forward = ConstraintKeywords::merge($first->keywords, $second->keywords);
    $backward = ConstraintKeywords::merge($second->keywords, $first->keywords);
    $this->assertSame(['allOf'], array_keys($forward));
    $parts = static function (array $parts): array {
      $parts = array_map('json_encode', $parts);
      sort($parts);
      return $parts;
    };
    $this->assertSame($parts($forward['allOf']), $parts($backward['allOf']), 'allOf holds every part in either order.');
    $this->assertCount(2, $forward['allOf']);
    $exercised[] = 'allOf';
    $this->assertEqualsCanonicalizing(array_keys(ConstraintKeywords::MERGE), $exercised, 'Every merge rule is exercised by a row.');
  }

  /**
   * Tests what the reader keeps, rather than reads, and its order.
   */
  public function testReadingKeepsWhatItCannotRead(): void {
    // Both a pattern and one it must not match: two Regex constraints,
    // of which a definition holds one, so the pair is kept whole.
    $pair = ['pattern' => '^[a-z]+$', 'not' => ['pattern' => '^x']];
    $both = ConstraintKeywords::read($pair, ['string']);
    $this->assertCount(1, $both);
    $this->assertNull($both[0]->constraint);
    $this->assertSame($pair, $both[0]->kept);
    $this->assertNotNull($both[0]->note);

    // False is what an array says with no keyword at all.
    $unique = ConstraintKeywords::read(['uniqueItems' => FALSE], ['array']);
    $this->assertSame([NULL, ['uniqueItems'], []], [$unique[0]->constraint, $unique[0]->consumed, $unique[0]->kept]);

    // An exclusive bound is the next whole number in on an integer, and
    // kept on a number.
    $exclusive = ['exclusiveMinimum' => 0, 'exclusiveMaximum' => 10];
    $this->assertSame(['min' => 1, 'max' => 9], ConstraintKeywords::read($exclusive, ['integer'])[0]->options);
    $this->assertSame(['exclusiveMinimum' => 0], ConstraintKeywords::read(['exclusiveMinimum' => 0], ['number'])[0]->kept);

    // A part that says more than one constraint keeps the composite.
    $two = ['allOf' => [['minLength' => 2, 'pattern' => '^a$']]];
    $composite = ConstraintKeywords::read($two, ['string']);
    $this->assertSame($two, $composite[0]->kept);

    // A composite with a part no row reads back (Email, read as a leaf
    // type) is written, named under `portable-check` besides since the
    // format admits what Email refuses, and read back as the JsonSchema
    // constraint.
    // A NotBlank part reads back as the literal Length, as NotBlank alone
    // does.
    $mail = ['constraints' => [['Email' => []], ['Length' => ['max' => 60]]]];
    $written = ConstraintKeywords::state(['Sequentially' => $mail], ['string']);
    $this->assertSame(['allOf' => [['format' => 'email'], ['maxLength' => 60]]], $written['keywords']);
    $this->assertSame([['Sequentially', $mail, TRUE]], $written['left']);
    $back = ConstraintKeywords::read($written['keywords'], ['string']);
    $pairs = array_map(static fn (ReadBack $back): array => [$back->constraint, $back->kept], $back);
    $this->assertSame([[NULL, $written['keywords']]], $pairs);
    $parts = ['constraints' => [['NotBlank' => []], ['Length' => ['max' => 0]]]];
    $blank = ConstraintKeywords::state(['AtLeastOneOf' => $parts], ['string'])['keywords'];
    $either = ConstraintKeywords::read($blank, ['string'])[0];
    $read_parts = ['constraints' => [['Length' => ['min' => 1]], ['Length' => ['max' => 0]]]];
    $this->assertSame(['AtLeastOneOf', $read_parts], [$either->constraint, $either->options]);

    // In the order the reader declares: where each stands, the bounds
    // after, UniqueItems last.
    $list = ['uniqueItems' => TRUE, 'maxItems' => 3, 'allOf' => [['minItems' => 1]]];
    $read = ConstraintKeywords::read($list, ['array']);
    $this->assertSame(['Sequentially', 'Count', 'UniqueItems'], array_map(static fn (ReadBack $back): ?string => $back->constraint, $read));
    $this->assertTrue(ConstraintKeywords::readsFormat('uuid'));
    $this->assertFalse(ConstraintKeywords::readsFormat('email'));
  }

}
