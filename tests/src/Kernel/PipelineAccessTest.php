<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface_test\RecordingTarget;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the access stage the pipeline runs before anything else.
 *
 * The claim being held to: one answer per operation, consulted in one
 * place, and the tri-state respected. A forbidden answer stops the run
 * before the target is read, which is what makes it a gate rather than a
 * late refusal — a caller who may not write must not be able to learn
 * what storage holds by being refused. A neutral answer is no opinion
 * and must leave the run exactly as it was, which is the compatibility
 * promise for every provider that has not answered for itself. An
 * allowed answer adds nothing but its cacheability.
 *
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface::submit()
 * @see \Drupal\data_surface\DataSurfaceAccess
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class PipelineAccessTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface', 'data_surface_test'];

  /**
   * The shared log the recording targets append their writes to.
   *
   * @var \ArrayObject<int, string>
   */
  protected \ArrayObject $log;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->log = new \ArrayObject();
  }

  /**
   * Builds a target that records what it was asked to do.
   *
   * @return \Drupal\data_surface_test\RecordingTarget
   *   The target.
   */
  protected function target(): RecordingTarget {
    return new RecordingTarget('gated', $this->log);
  }

  /**
   * Tests that a forbidden answer refuses before the target is read.
   */
  public function testForbiddenRefusesBeforeLoad(): void {
    $surface = $this->casingVariantSurface();
    $target = $this->target();

    $result = $this->pipeline()->submit(
      $surface,
      ['casing' => 'uppercase'],
      $target,
      access: AccessResult::forbidden('No configuring today.'),
    );

    $this->assertFalse($result->isValid());
    $this->assertTrue($result->isAccessRefused());
    // The stage ran before any other: storage was never asked what it
    // holds, nothing was prepared, and nothing was written.
    $this->assertSame(0, $target->loads);
    $this->assertSame([], $this->log->getArrayCopy());
    $this->assertNull($result->prepared);
    $this->assertFalse($result->committed);
    // And the values come back empty rather than carrying what storage
    // holds, because storage was never read.
    $this->assertSame([], $result->values);
  }

  /**
   * Tests that the refusal arrives as one violation carrying the reason.
   */
  public function testForbiddenReasonSurvivesAsMessageObject(): void {
    $result = $this->pipeline()->submit(
      $this->casingVariantSurface(),
      ['casing' => 'uppercase'],
      $this->target(),
      access: AccessResult::forbidden('The gate is shut.'),
    );

    $this->assertCount(1, $result->violations);
    $this->assertSame(
      [DataSurfacePipelineInterface::ACCESS_VIOLATION_KEY],
      $result->violations->keys(),
    );
    $violation = $result->violations->byKey(DataSurfacePipelineInterface::ACCESS_VIOLATION_KEY)[0];
    // The refusal belongs to the run, not to a value inside a key.
    $this->assertSame('', $violation->path);
    // A message object like every other violation's, with the reason
    // still a placeholder rather than text something would escape twice.
    $this->assertInstanceOf(\Stringable::class, $violation->message);
    $this->assertStringContainsString('The gate is shut.', (string) $violation->message);
  }

  /**
   * Tests that a refusal with no reason still says what happened.
   */
  public function testForbiddenWithoutReasonStillCarriesMessage(): void {
    $result = $this->pipeline()->submit(
      $this->casingVariantSurface(),
      ['casing' => 'uppercase'],
      $this->target(),
      access: AccessResult::forbidden(),
    );

    $violation = $result->violations->byKey(DataSurfacePipelineInterface::ACCESS_VIOLATION_KEY)[0];
    $this->assertSame('Access refused.', (string) $violation->message);
  }

  /**
   * Tests that the answer itself, and its cacheability, reach the caller.
   *
   * A caller that renders or caches what a run produced has to be able
   * to say how long the decision may be reused, and the only object that
   * knows is the access answer, so the result carries it rather than a
   * boolean read off it.
   */
  public function testTheAccessAnswerIsExposedWithItsCacheability(): void {
    $access = AccessResult::forbidden('Not for you.')
      ->addCacheContexts(['user.permissions'])
      ->addCacheTags(['config:node_type_list']);

    $result = $this->pipeline()->submit(
      $this->casingVariantSurface(),
      ['casing' => 'uppercase'],
      $this->target(),
      access: $access,
    );

    $this->assertSame($access, $result->access);
    $cacheability = CacheableMetadata::createFromObject($result->access);
    $this->assertContains('user.permissions', $cacheability->getCacheContexts());
    $this->assertContains('config:node_type_list', $cacheability->getCacheTags());
  }

  /**
   * Tests that neutral and allowed leave the run exactly as it was.
   *
   * The compatibility promise: a provider that has not answered for
   * itself inherits neutral, so every existing caller must produce the
   * same result it produced before this stage existed. Allowed is held
   * to the same thing, because a grant is not a bypass of anything the
   * rest of the pipeline does.
   */
  public function testNeutralAndAllowedProceedIdentically(): void {
    $surface = $this->casingVariantSurface();
    $input = ['casing' => 'uppercase', 'variant' => 'bold'];

    $unasked = $this->target();
    $baseline = $this->pipeline()->submit($surface, $input, $unasked);
    $neutral_target = $this->target();
    $neutral = $this->pipeline()->submit($surface, $input, $neutral_target, access: AccessResult::neutral());
    $allowed_target = $this->target();
    $allowed = $this->pipeline()->submit($surface, $input, $allowed_target, access: AccessResult::allowed());

    foreach ([$baseline, $neutral, $allowed] as $result) {
      $this->assertTrue($result->isValid());
      $this->assertFalse($result->isAccessRefused());
      $this->assertTrue($result->committed);
      $this->assertSame(['casing' => 'uppercase', 'variant' => 'bold'], $result->values);
    }
    // Every stage ran for all three, in the same order.
    $this->assertSame(1, $unasked->loads);
    $this->assertSame(1, $neutral_target->loads);
    $this->assertSame(1, $allowed_target->loads);
    $this->assertSame(
      ['gated prepare', 'gated commit', 'gated prepare', 'gated commit', 'gated prepare', 'gated commit'],
      $this->log->getArrayCopy(),
    );
    // Nothing was refused, and the answer still travels with the result
    // so its cacheability is not lost by being permissive.
    $this->assertNull($baseline->access);
    $this->assertTrue($neutral->access?->isNeutral());
    $this->assertTrue($allowed->access?->isAllowed());
  }

  /**
   * Tests that an allowed answer does not excuse an invalid value.
   *
   * Access answers who, not what: the stages after it are unchanged, so
   * a value the surface refuses is refused for a caller who was granted
   * the operation exactly as it is for everybody else.
   */
  public function testAllowedDoesNotBypassValidation(): void {
    $target = $this->target();

    $result = $this->pipeline()->submit(
      $this->casingVariantSurface(),
      ['casing' => 'sideways'],
      $target,
      access: AccessResult::allowed(),
    );

    $this->assertFalse($result->isValid());
    $this->assertFalse($result->isAccessRefused());
    $this->assertSame(['casing'], $result->violations->keys());
    $this->assertSame([], $this->log->getArrayCopy());
  }

  /**
   * Tests that a host with nothing to say inherits the neutral default.
   *
   * The default lives in the host trait, so every base class that
   * composes it answers the same way until it says otherwise — which is
   * what makes adding the method to the interface a no-op for providers
   * that have not adopted it.
   */
  public function testHostsDefaultToNoOpinion(): void {
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_test_block');

    $access = $block->surfaceAccess();

    $this->assertTrue($access->isNeutral());
    $this->assertFalse($access->isForbidden());
    // Asked for an operation it has never heard of, it still has no
    // opinion rather than an error.
    $this->assertTrue($block->surfaceAccess('settings_tray')->isNeutral());
  }

}
