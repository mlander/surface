<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

/**
 * A store that can list an account's open stages.
 *
 * Optional: a StageStoreInterface implementation may implement this too
 * when it can answer the question without reading every record. The
 * shipped StageStore does not, since its collection has no query by
 * owner. When the store lists, the first step of a flow offers to resume
 * an open stage for the same thing.
 *
 * Decision: see
 * docs/decisions.md#one-store-contract-a-stages-lifetime-is-data-on-its-record.
 */
interface ListsStagesInterface {

  /**
   * Lists the open stages an account owns.
   *
   * @param int $owner
   *   The account's id.
   *
   * @return list<string>
   *   The ids of the stages whose record's `owner` is the account and
   *   whose `status` is open, the most recently opened first.
   */
  public function openStages(int $owner): array;

}
