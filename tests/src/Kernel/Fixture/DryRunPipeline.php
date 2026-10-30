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
 * The pipeline, with every submit made a dry run and its result kept.
 *
 * What the form door is driven through in a kernel test: the operation
 * form submits as it always does, and nothing is written, so the next
 * document starts from what is stored, as it does at every other door.
 */
final class DryRunPipeline implements SurfacePipelineInterface {

  /**
   * The last submit's result and the document it answers, or NULL.
   *
   * @var array{0: \Drupal\surface\Pipeline\SurfaceResult, 1: array}|null
   */
  public ?array $last = NULL;

  /**
   * The data surface the last dry run judged.
   */
  protected ?SurfaceInterface $surface = NULL;

  /**
   * Constructs a DryRunPipeline.
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
    throw new \LogicException('The form door is driven dry: nothing is committed.');
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
   *
   * Always dry, whatever the caller asked; the result kept, with the
   * document the read tool would answer of it.
   */
  public function submit(SurfaceInterface $surface, array $input, SurfaceStorageInterface $storage, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): SurfaceResult {
    $result = $this->inner->submit($surface, $input, $storage, TRUE, $access);
    $this->last = [$result, $result->document($this->inner->outbound($surface, $result->held()))];
    $this->surface = $surface;
    return $result;
  }

  /**
   * {@inheritdoc}
   *
   * Dry still: the dry run is answered as it stands, nothing committed.
   */
  public function commitDryRun(SurfaceResult $dry, SurfaceStorageInterface $storage): SurfaceResult {
    assert($this->surface !== NULL);
    $this->last = [$dry, $dry->document($this->inner->outbound($this->surface, $dry->held()))];
    return $dry;
  }

}
