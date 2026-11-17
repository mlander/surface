<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\RangeConstraint;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\surface\Plugin\Validation\Constraint\UniqueItemsConstraint;
use Drupal\surface\Validation\ConstraintRunner;
use Drupal\Tests\surface\Kernel\Fixture\ContextReadingConstraint;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\Constraints\Unique;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests the one adapter that runs a constraint through Typed Data.
 *
 * The runner's check() runs one plugin on one value and nothing else, a
 * core plugin and one of Surface's alike, its violations at the value
 * itself.
 *
 * @see docs/decisions.md#one-adapter-runs-a-constraint-the-typed-data-footprint-is-pinned
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ConstraintRunnerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
  ];

  /**
   * Gets the runner.
   *
   * @return \Drupal\surface\Validation\ConstraintRunner
   *   The service.
   */
  protected function runner(): ConstraintRunner {
    return $this->container->get('surface.constraint_runner');
  }

  /**
   * Lists the violations by their constraint's class, code and path.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface $violations
   *   The violations.
   *
   * @return list<array{0: class-string|null, 1: string|null, 2: string}>
   *   One row per violation.
   */
  protected static function rows(ConstraintViolationListInterface $violations): array {
    $rows = [];
    foreach ($violations as $violation) {
      $constraint = $violation->getConstraint();
      $rows[] = [
        $constraint === NULL ? NULL : get_class($constraint),
        $violation->getCode(),
        (string) $violation->getPropertyPath(),
      ];
    }
    return $rows;
  }

  /**
   * Tests check() runs one core constraint, and that alone.
   */
  public function testCheckRunsCoreConstraint(): void {
    $integer = DataDefinition::create('integer')->setRequired(TRUE);
    $range = ['min' => 1, 'max' => 10];
    $this->assertSame([], static::rows($this->runner()->check('Range', $range, 5, $integer)), 'In range: passes.');
    $refused = [[RangeConstraint::class, RangeConstraint::NOT_IN_RANGE_ERROR, '']];
    $this->assertSame($refused, static::rows($this->runner()->check('Range', $range, 11, $integer)), 'Out of range: refused, at the value.');
    $this->assertSame([], static::rows($this->runner()->check('Range', $range, NULL, $integer)), 'The definition says the value is required; check() runs the one constraint, never NotNull.');
    $this->assertSame($refused, static::rows($this->runner()->check('Range', $range, 11)), 'With no definition, the value is held as `any`.');
  }

  /**
   * Tests check() runs one of Surface's own constraints.
   */
  public function testCheckRunsSurfaceConstraint(): void {
    $tags = ListDataDefinition::create('string');
    $this->assertSame([], static::rows($this->runner()->check('UniqueItems', [], ['a', 'b'], $tags)), 'Distinct items pass.');
    $refused = [[UniqueItemsConstraint::class, Unique::IS_NOT_UNIQUE, '']];
    $this->assertSame($refused, static::rows($this->runner()->check('UniqueItems', [], ['a', 'a'], $tags)), 'A repeated item is refused, at the list.');
  }

  /**
   * Tests Surface's own plugins run on the plain value, core's on a typed one.
   *
   * The plain validator words the message as Typed Data's does
   * (ValueOnlyConstraintsTest holds the two paths alike for every plugin);
   * the value it is handed is the value itself.
   */
  public function testSurfacePluginsRunOnThePlainValue(): void {
    $plain = $this->runner()->check('UniqueItems', [], ['a', 'a'], ListDataDefinition::create('string'));
    $this->assertCount(1, $plain);
    $this->assertSame(['a', 'a'], $plain[0]->getRoot(), 'No typed value is built for UniqueItems.');
    $this->assertSame('The item %value is listed more than once.', $plain[0]->getMessageTemplate());
    $this->assertSame('The item &quot;a&quot; is listed more than once.', strip_tags((string) $plain[0]->getMessage()), 'Its message is filled, as Typed Data fills it.');
    $typed = $this->runner()->typed('UniqueItems', [], ['a', 'a'], ListDataDefinition::create('string'));
    $this->assertInstanceOf(TypedDataInterface::class, $typed[0]->getRoot(), 'The typed path builds one.');
    $this->assertSame((string) $typed[0]->getMessage(), (string) $plain[0]->getMessage());
    $core = $this->runner()->check('Range', ['max' => 3], 5, DataDefinition::create('integer'));
    $this->assertInstanceOf(TypedDataInterface::class, $core[0]->getRoot(), 'A core plugin runs on a typed value.');
  }

  /**
   * Tests a plugin whose class was altered runs on a typed value.
   *
   * The plain path is for the class Surface ships under an id
   * (ConstraintRunner::VALUE_ONLY). Here the constraint manager's
   * definition of UniqueItems names another class, as an alter hook would
   * leave it, whose validator reads the typed value: check() runs it on
   * one, so it refuses in its own message and does not throw.
   */
  public function testAlteredPluginRunsOnTypedValue(): void {
    $manager = $this->container->get('validation.constraint');
    $definitions = $manager->getDefinitions();
    $definitions['UniqueItems']['class'] = ContextReadingConstraint::class;
    // What hook_validation_constraint_alter() would leave in the manager.
    (new \ReflectionProperty(DefaultPluginManager::class, 'definitions'))->setValue($manager, $definitions);
    $violations = $this->runner()->check('UniqueItems', [], ['a', 'b'], ListDataDefinition::create('string'));
    $this->assertCount(1, $violations);
    $this->assertSame('Refused by the altered class.', (string) $violations[0]->getMessage());
    $this->assertInstanceOf(TypedDataInterface::class, $violations[0]->getRoot(), 'The altered class is handed a typed value.');
  }

}
