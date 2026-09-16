<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Pipeline\SurfaceViolation;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the two objects every caller of the pipeline reads its answer from.
 *
 * A form submit handler, a config action, a service endpoint and an
 * agent all branch on one method, so what isValid() counts as valid is
 * part of the module's public contract rather than an implementation
 * detail. The rule is that violations, and only violations, decide it:
 * not whether anything was written, not whether an artifact was
 * prepared, and not whether any values came back. A result that reported
 * a dry run as invalid would make every preview look like a failure, and
 * one that reported an empty violation set as invalid because nothing
 * was committed would make the dry run unusable.
 *
 * @see \Drupal\data_surface\Pipeline\DataSurfaceResult
 * @see \Drupal\data_surface\Pipeline\PreparedValues
 */
#[Group('data_surface')]
class PipelineResultTest extends UnitTestCase {

  /**
   * Every combination of the three things isValid() must ignore.
   *
   * @return array<string, array{0: \Drupal\data_surface\Pipeline\ViolationSet, 1: bool, 2: bool}>
   *   Test cases: the violations, whether an artifact was prepared, and
   *   whether the run counts as valid.
   */
  public static function validityCases(): array {
    $title = new SurfaceViolation('title', '', 'Refused.');
    $limit = new SurfaceViolation('limit', '', 'Refused.');
    return [
      'nothing refused, nothing prepared' => [new ViolationSet(), FALSE, TRUE],
      'nothing refused, a dry run prepared' => [new ViolationSet(), TRUE, TRUE],
      'one key refused' => [new ViolationSet([$title]), FALSE, FALSE],
      'one key refused despite a prepared artifact' => [new ViolationSet([$title]), TRUE, FALSE],
      'two keys refused' => [
        new ViolationSet([$title, $limit]),
        FALSE,
        FALSE,
      ],
      // Two violations on one key are one refused key and two
      // violations, which a caller reporting per key has to be able to
      // tell apart.
      'one key refused twice' => [new ViolationSet([$title, $title]), FALSE, FALSE],
    ];
  }

  /**
   * Tests that violations alone decide whether a run was valid.
   */
  #[DataProvider('validityCases')]
  public function testIsValidCountsViolationsOnly(ViolationSet $violations, bool $prepared, bool $expected): void {
    $result = new DataSurfaceResult(
      values: ['title' => 'Hello'],
      violations: $violations,
      prepared: $prepared ? new PreparedValues(['title' => 'Hello'], ['title' => 'Hello']) : NULL,
    );

    $this->assertSame($expected, $result->isValid());
  }

  /**
   * Tests the defaults a result takes when a stage never ran.
   */
  public function testResultThatOnlyReachedAccept(): void {
    $result = new DataSurfaceResult(values: ['title' => 'Hello']);

    $this->assertTrue($result->isValid());
    // Nothing prepared and nothing written, which is what a caller that
    // only accepted values has to be able to tell.
    $this->assertNull($result->prepared);
    $this->assertFalse($result->committed);
    $this->assertCount(0, $result->violations);
  }

  /**
   * Tests that a message object survives the trip through a result.
   *
   * A constraint builds its message as a TranslatableMarkup with the
   * placeholders still placeholders, and the result is one of the places
   * an early (string) cast would substitute and escape them once, so
   * whatever printed the error would escape it a second time.
   */
  public function testViolationMessagesStayObjects(): void {
    $message = new TranslatableMarkup('@label is required.', ['@label' => 'Title']);
    $result = new DataSurfaceResult(
      values: [],
      violations: new ViolationSet([new SurfaceViolation('title', '', $message)]),
    );

    $this->assertFalse($result->isValid());
    $this->assertSame($message, $result->violations->byKey('title')[0]->message);
  }

  /**
   * Tests what a result says about the access answer it was gated by.
   *
   * Two separate questions, and a caller needs both. isValid() is about
   * the values and counts an access refusal like any other violation, so
   * a caller that only branches on it needs to know nothing new.
   * isAccessRefused() is about the caller, which is what tells a 403
   * apart from a 422 and a "you may not" apart from a "that is wrong".
   */
  public function testAccessAnswersAreReadableSeparately(): void {
    $allowed = new DataSurfaceResult(
      values: ['title' => 'Hello'],
      access: AccessResult::allowed(),
    );
    $refused = new DataSurfaceResult(
      values: [],
      violations: new ViolationSet([
        new SurfaceViolation(DataSurfacePipelineInterface::ACCESS_VIOLATION_KEY, '', 'Access refused.'),
      ]),
      access: AccessResult::forbidden(),
    );
    $invalid = new DataSurfaceResult(
      values: ['title' => ''],
      violations: new ViolationSet([new SurfaceViolation('title', '', 'Refused.')]),
      access: AccessResult::allowed(),
    );

    $this->assertTrue($allowed->isValid());
    $this->assertFalse($allowed->isAccessRefused());
    $this->assertFalse($refused->isValid());
    $this->assertTrue($refused->isAccessRefused());
    // Refused by a constraint, not by the gate, although both are
    // invalid: the two reasons never collapse into one.
    $this->assertFalse($invalid->isValid());
    $this->assertFalse($invalid->isAccessRefused());
  }

  /**
   * Tests that a caller applying no access answer is not "allowed".
   *
   * NULL means nothing was asked, which is what every caller written
   * before the stage existed means. Reading it as a grant would turn a
   * missing question into a positive answer.
   */
  public function testNoAccessAnswerIsNotRefusalEither(): void {
    $result = new DataSurfaceResult(values: ['title' => 'Hello']);

    $this->assertNull($result->access);
    $this->assertFalse($result->isAccessRefused());
    $this->assertTrue($result->isValid());
  }

  /**
   * Tests that a prepared artifact carries the write without doing it.
   *
   * The preview object: the values in surface shape, whatever storage
   * shape the target turned them into, and the dependencies that shape
   * carries. A caller can render or discard all three, and nothing has
   * been written until commit takes the same object.
   */
  public function testPreparedValuesCarryTheStorageShape(): void {
    $values = ['available_countries' => ['US']];
    $artifact = ['available_countries' => ['US' => 'US'], 'fields' => []];
    $dependencies = ['module' => ['address']];

    $prepared = new PreparedValues($values, $artifact, $dependencies);

    $this->assertSame($values, $prepared->values);
    $this->assertSame($artifact, $prepared->artifact);
    $this->assertSame($dependencies, $prepared->dependencies);
  }

  /**
   * Tests that a target with nothing to declare declares nothing.
   */
  public function testPreparedValuesDefaultToNoDependencies(): void {
    $this->assertSame([], (new PreparedValues([], NULL))->dependencies);
    // The artifact is whatever the target commits, which for some
    // targets is an object and for others is nothing at all.
    $this->assertNull((new PreparedValues([], NULL))->artifact);
  }

}
