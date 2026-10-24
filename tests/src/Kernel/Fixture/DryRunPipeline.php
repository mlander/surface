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
 * The pipeline, with every submit made a dry run and its result kept.
 *
 * What the form door is driven through in a kernel test: the operation
 * form submits as it always does, and nothing is written, so the next
 * document starts from what is stored, as it does at every other door.
 */
final class DryRunPipeline implements DataSurfacePipelineInterface {

  /**
   * The last submit's result and the document it answers, or NULL.
   *
   * @var array{0: \Drupal\data_surface\Pipeline\DataSurfaceResult, 1: array}|null
   */
  public ?array $last = NULL;

  /**
   * Constructs a DryRunPipeline.
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
    throw new \LogicException('The form door is driven dry: nothing is committed.');
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
   *
   * Always dry, whatever the caller asked; the result kept, with the
   * document the read tool would answer of it.
   */
  public function submit(DataSurfaceInterface $surface, array $input, DataSurfaceStorageInterface $storage, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): DataSurfaceResult {
    $result = $this->inner->submit($surface, $input, $storage, TRUE, $access);
    $this->last = [$result, $result->document($this->inner->outbound($surface, $result->held()))];
    return $result;
  }

}
