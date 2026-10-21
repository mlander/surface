<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holds the pages that quote code to the code they quote.
 *
 * Each block a page marks, as on the first resource page
 * (FirstResourceDocsTest says how), is that code as it is:
 *
 * - "Common settings recipes" quotes the test module's opening hours and
 *   the widget beside it, and every snippet on it is marked, so what a
 *   reader copies is code that builds. RecipesTest holds the fixture to
 *   what the page says each recipe does.
 * - "Resources and structures" quotes the examples' compliance alter
 *   whole, with the two schemas that describe its keys.
 * - "Storage" quotes the opening hours' fully validatable schema, which
 *   RecipesTest holds to what the page says it asks.
 */
#[Group('data_surface')]
class QuotedCodeDocsTest extends UnitTestCase {

  /**
   * The recipes page, relative to the module root.
   */
  public const RECIPES = 'docs/recipes.md';

  /**
   * The pages, relative to the module root.
   */
  public const PAGES = [self::RECIPES, 'docs/resources.md', 'docs/storage.md'];

  /**
   * Provides each marked block of the pages.
   *
   * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
   *   The blocks, keyed by page and position.
   */
  public static function providerBlocks(): array {
    $provided = [];
    foreach (FirstResourceDocsTest::blocks(self::PAGES) as $index => $block) {
      $provided[$block[0] . ' block ' . ($index + 1) . ' ' . $block[2]] = $block;
    }
    return $provided;
  }

  /**
   * Tests each marked block is the code as it is.
   */
  #[DataProvider('providerBlocks')]
  public function testTheBlockIsTheCode(string $page, string $kind, string $target, string $text): void {
    [$path, $key] = array_pad(explode('#', $target, 2), 2, NULL);
    $file = __DIR__ . '/../../../' . $path;
    $this->assertFileExists($file, sprintf('%s quotes %s, which is not there.', $page, $path));
    $this->assertSame(FirstResourceDocsTest::expected($kind, $file, $key, $text), $text, sprintf('%s quotes %s as it no longer is; copy it into the page again.', $page, $target));
  }

  /**
   * Tests the alter is quoted whole, beside both schemas.
   */
  public function testTheWholeAlterIsQuoted(): void {
    $quoted = array_column(FirstResourceDocsTest::blocks(['docs/resources.md']), 2);
    $this->assertContains('modules/data_surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php', $quoted);
    $this->assertContains('modules/data_surface_examples/config/schema/data_surface_examples.schema.yml', $quoted);
    $this->assertContains('modules/data_surface_examples_compliance/config/schema/data_surface_examples_compliance.schema.yml', $quoted);
  }

  /**
   * Tests the storage page quotes a fully validatable schema.
   */
  public function testFullyValidatableSchemaIsQuoted(): void {
    $quoted = array_column(FirstResourceDocsTest::blocks(['docs/storage.md']), 3);
    $this->assertNotEmpty(array_filter($quoted, static fn (string $text): bool => str_contains($text, 'FullyValidatable: ~') && str_contains($text, 'nullable: true')));
  }

  /**
   * Tests every snippet on the recipes page is marked, one per recipe.
   */
  public function testEveryRecipeIsPinned(): void {
    $page = FirstResourceDocsTest::page(self::RECIPES);
    $blocks = FirstResourceDocsTest::blocks([self::RECIPES]);
    $this->assertCount(substr_count($page, "```php\n"), $blocks, 'A snippet with no marker is held to nothing.');
    $this->assertGreaterThanOrEqual(substr_count($page, "\n## "), count($blocks));
  }

}
