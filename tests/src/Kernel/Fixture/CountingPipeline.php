<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel\Fixture;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Pipeline\ViolationSet;

/**
 * The pipeline, counting the runs a door asks of it.
 *
 * Every submit() that reaches prepare prepares once; commitDryRun()
 * prepares nothing. So the calls say how often storage rehearsed a write.
 */
final class CountingPipeline implements DataSurfacePipelineInterface {

  /**
   * The calls made, by method, a submit by whether it ran dry.
   *
   * @var array<string, int>
   */
  public array $calls = ['submit dry' => 0, 'submit' => 0, 'commitDryRun' => 0];

  /**
   * Constructs a CountingPipeline.
   *
   * @param \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface $inner
   *   The pipeline.
   */
  public function __construct(protected readonly DataSurfacePipelineInterface $inner) {}

  /**
   * {@inheritdoc}
   */
  public function accept(DataSurfaceInterface $surface, array $input, array $current = []): array {
    return $this->inner->accept($surface, $input, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(DataSurfaceInterface $surface, array $values, array $current = []): ViolationSet {
    return $this->inner->validate($surface, $values, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function conformOutput(DataSurfaceInterface $surface, array $output): ViolationSet {
    return $this->inner->conformOutput($surface, $output);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values, DataSurfaceStorageInterface $storage): PreparedValues {
    return $this->inner->prepare($surface, $values, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public function kept(DataSurfaceInterface $surface, array $values, array $current, ?array $input = NULL): array {
    return $this->inner->kept($surface, $values, $current, $input);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared, DataSurfaceStorageInterface $storage): void {
    $this->inner->commit($prepared, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public function outbound(DataSurfaceInterface $surface, array $values): array {
    return $this->inner->outbound($surface, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function document(DataSurfaceInterface $surface, array $values): \stdClass {
    return $this->inner->document($surface, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function submit(DataSurfaceInterface $surface, array $input, DataSurfaceStorageInterface $storage, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): DataSurfaceResult {
    $this->calls[$dry_run ? 'submit dry' : 'submit']++;
    return $this->inner->submit($surface, $input, $storage, $dry_run, $access);
  }

  /**
   * {@inheritdoc}
   */
  public function commitDryRun(DataSurfaceResult $dry, DataSurfaceStorageInterface $storage): DataSurfaceResult {
    $this->calls['commitDryRun']++;
    return $this->inner->commitDryRun($dry, $storage);
  }

}
