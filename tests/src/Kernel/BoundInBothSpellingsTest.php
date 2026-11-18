<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Refinement\Rule;
use Drupal\surface\Resource\OperationContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests a bound given as a keyword and as a check at once.
 *
 * `maximum(10)` and the check `drupal:Range` with `max: 5` say one bound
 * twice. A rule and a context keep the stricter of the two, in either
 * order, as the authored reader does for a key's schema.
 *
 * @see docs/decisions.md#slice-329-the-whole-reviews-fixes-2026-11-18
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class BoundInBothSpellingsTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'surface'];

  /**
   * The spellings, each with the Range it is.
   *
   * @return array<string, array{0: \PortableSchema\Builder\Schema, 1: array}>
   *   The schema and the Range options, by case.
   */
  protected static function cases(): array {
    return [
      'keyword then check' => [Schema::any()->maximum(10)->check('drupal:Range', ['max' => 5]), ['max' => 5]],
      'check then keyword' => [Schema::any()->check('drupal:Range', ['max' => 5])->maximum(10), ['max' => 5]],
      'minimum, keyword then check' => [Schema::any()->minimum(3)->check('drupal:Range', ['min' => 1]), ['min' => 3]],
      'minimum, check then keyword' => [Schema::any()->check('drupal:Range', ['min' => 1])->minimum(3), ['min' => 3]],
    ];
  }

  /**
   * Tests a rule keeps the stricter bound.
   */
  public function testRuleKeepsTheStricterBound(): void {
    foreach (static::cases() as $case => [$schema, $range]) {
      $applied = (new Rule('k'))->constrain($schema)->apply(DataDefinition::create('integer'))->getConstraints()['Range'];
      $this->assertSame($range, array_intersect_key($applied, ['min' => TRUE, 'max' => TRUE]), $case);
    }
    $length = (new Rule('k'))->constrain(Schema::any()->check('drupal:Length', ['max' => 4])->maxLength(8))->apply(DataDefinition::create('string'))->getConstraints()['Length'];
    $this->assertSame(4, $length['max']);
  }

  /**
   * Tests a context keeps the stricter bound.
   */
  public function testContextKeepsTheStricterBound(): void {
    foreach (static::cases() as $case => [$schema, $range]) {
      $constraints = (new OperationContext('configure'))->withConstraint('k', $schema)->constraints();
      $this->assertSame($range, array_intersect_key($constraints['k']['Range'], ['min' => TRUE, 'max' => TRUE]), $case);
    }
  }

}
