<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\surface\Pipeline\PreparedValues;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Pipeline\ViolationSet;
use Drupal\surface\SurfaceInterface;

/**
 * The site's pipeline, which a test can tell to throw or to misreport.
 *
 * What OperationRunnerTest hands a runner to show what happens when
 * submit() throws, and when a storage reports outputs the surface does
 * not declare: `throws` is thrown from submit() when set; `outputs` is
 * answered by outputs() in place of what the storage reported, which
 * `real` keeps.
 */
final class MisreportingPipeline implements SurfacePipelineInterface {

  /**
   * What submit() throws, when set.
   */
  public ?\Throwable $throws = NULL;

  /**
   * What outputs() answers, when set.
   *
   * @var array<string, mixed>|null
   */
  public ?array $outputs = NULL;

  /**
   * What the storage reported, the last time outputs() was asked.
   *
   * @var array<string, mixed>
   */
  public array $real = [];

  /**
   * Wraps the site's pipeline.
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
    if ($this->throws !== NULL) {
      throw $this->throws;
    }
    return $this->inner->submit($surface, $input, $storage, $dry_run, $access);
  }

  /**
   * {@inheritdoc}
   */
  public function commitDryRun(SurfaceResult $dry, SurfaceStorageInterface $storage): SurfaceResult {
    return $this->inner->commitDryRun($dry, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public function outputs(SurfaceInterface $built, SurfaceStorageInterface $storage, SurfaceResult $result): array {
    $this->real = $this->inner->outputs($built, $storage, $result);
    return $this->outputs ?? $this->real;
  }

}
