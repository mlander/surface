<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel\Fixture;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Pipeline\PreparedValues;
use Drupal\surface\Pipeline\ViolationSet;

/**
 * The pipeline, counting the runs a door asks of it.
 *
 * Every submit() that reaches prepare prepares once; commitDryRun()
 * prepares nothing. So the calls say how often storage rehearsed a write.
 */
final class CountingPipeline implements SurfacePipelineInterface {

  /**
   * The calls made, by method, a submit by whether it ran dry.
   *
   * @var array<string, int>
   */
  public array $calls = ['submit dry' => 0, 'submit' => 0, 'commitDryRun' => 0];

  /**
   * Constructs a CountingPipeline.
   *
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $inner
   *   The pipeline.
   */
  public function __construct(protected readonly SurfacePipelineInterface $inner) {}

  /**
   * {@inheritdoc}
   */
  public function accept(SurfaceInterface $surface, array $input, array $current = []): array {
    return $this->inner->accept($surface, $input, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(SurfaceInterface $surface, array $values, array $current = []): ViolationSet {
    return $this->inner->validate($surface, $values, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function conformOutput(SurfaceInterface $surface, array $output): ViolationSet {
    return $this->inner->conformOutput($surface, $output);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(SurfaceInterface $surface, array $values, SurfaceStorageInterface $storage): PreparedValues {
    return $this->inner->prepare($surface, $values, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public function kept(SurfaceInterface $surface, array $values, array $current, ?array $input = NULL): array {
    return $this->inner->kept($surface, $values, $current, $input);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared, SurfaceStorageInterface $storage): void {
    $this->inner->commit($prepared, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public function outbound(SurfaceInterface $surface, array $values): array {
    return $this->inner->outbound($surface, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function document(SurfaceInterface $surface, array $values): \stdClass {
    return $this->inner->document($surface, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function submit(SurfaceInterface $surface, array $input, SurfaceStorageInterface $storage, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): SurfaceResult {
    $this->calls[$dry_run ? 'submit dry' : 'submit']++;
    return $this->inner->submit($surface, $input, $storage, $dry_run, $access);
  }

  /**
   * {@inheritdoc}
   */
  public function commitDryRun(SurfaceResult $dry, SurfaceStorageInterface $storage): SurfaceResult {
    $this->calls['commitDryRun']++;
    return $this->inner->commitDryRun($dry, $storage);
  }

}
