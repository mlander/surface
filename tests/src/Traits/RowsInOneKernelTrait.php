<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Traits;

use PHPUnit\Framework\ExpectationFailedException;

/**
 * Runs the rows of a table in one kernel, as InputCoercionMatrixTest does.
 *
 * For a table whose rows read no state a previous row left: one boot
 * serves them all, in place of one process for each row. A failed row does
 * not stop the rest: each failure message starts with the row's name, and
 * the test fails once at the end listing every failed row.
 */
trait RowsInOneKernelTrait {

  /**
   * Runs a check over the rows of a table in this kernel.
   *
   * @param iterable $rows
   *   The rows of a provider, by name. Each is the arguments of $check after
   *   the name.
   * @param callable $check
   *   Takes the row's name, then the row; its assertion messages start with
   *   the name.
   */
  protected function checkRows(iterable $rows, callable $check): void {
    $count = 0;
    $failed = [];
    foreach ($rows as $name => $row) {
      $count++;
      try {
        $check($name, ...$row);
      }
      catch (ExpectationFailedException $e) {
        $failed[] = $e->getMessage();
      }
      catch (\Throwable $e) {
        $failed[] = $name . ': ' . $e::class . ': ' . $e->getMessage();
      }
    }
    $this->assertGreaterThan(0, $count, 'The table has rows.');
    if ($failed) {
      $this->fail(count($failed) . ' of ' . $count . " rows failed:\n\n" . implode("\n\n", $failed));
    }
  }

}
