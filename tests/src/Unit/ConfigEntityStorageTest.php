<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Storage\ConfigEntityStorage;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how a config entity storage reads the map its subclass names.
 *
 * A plain entry is a key stored under its own name, a string a property
 * of another name, and `['method' => 'setX']` a setter the entity offers;
 * anything else is refused by name, before any entity is touched.
 */
#[Group('surface')]
class ConfigEntityStorageTest extends UnitTestCase {

  /**
   * Tests the three spellings of an entry.
   */
  public function testTheMapIsNormalized(): void {
    $plain = new class() extends ConfigEntityStorage {

      public const PROPERTIES = ['id', 'label'];

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function __construct() {}

    };
    $this->assertSame(['id' => 'id', 'label' => 'label'], $plain::properties(), 'A plain entry is stored under its own name.');
    $keyed = new class() extends ConfigEntityStorage {

      public const PROPERTIES = [
        'name' => 'label',
        'kind' => [self::METHOD => 'setKind'],
      ];

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function __construct() {}

    };
    $this->assertSame([
      'name' => 'label',
      'kind' => ['method' => 'setKind'],
    ], $keyed::properties());
  }

  /**
   * Tests an entry naming neither a property nor a method is refused.
   */
  public function testBrokenEntryIsRefused(): void {
    $storage = new class() extends ConfigEntityStorage {

      public const PROPERTIES = ['kind' => ['setter' => 'setKind']];

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function __construct() {}

    };
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('the "kind" entry must name an entity property or an entity method.');
    $storage::properties();
  }

}
