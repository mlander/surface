<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\data_surface_examples\ExampleCalls;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holds the examples' README to the files it quotes.
 *
 * The README is written for someone watching the examples being
 * explained, and shows each step's code inline. Every block it marks
 * with `<!-- code: <path> -->` must be that file from its first attribute
 * or its class line to its closing brace, so a change to a step that
 * leaves the README behind fails here, naming the file. Its Drush
 * commands must be the ones ExampleCalls spells; ExamplesToolTest checks
 * the three answers it quotes.
 */
#[Group('data_surface')]
class ExamplesReadmeTest extends UnitTestCase {

  /**
   * The examples module.
   */
  protected const MODULE = __DIR__ . '/../../../modules/data_surface_examples';

  /**
   * Reads the README.
   *
   * @return string
   *   Its contents.
   */
  public static function readme(): string {
    return (string) file_get_contents(self::MODULE . '/README.md');
  }

  /**
   * Gets the part of a file the README quotes.
   *
   * @param string $file
   *   The file.
   *
   * @return string
   *   From its first attribute or class line to its end, trimmed.
   */
  public static function excerpt(string $file): string {
    $lines = explode("\n", (string) file_get_contents($file));
    foreach ($lines as $index => $line) {
      if (preg_match('/^(#\[|(final |abstract )?class )/', $line)) {
        return trim(implode("\n", array_slice($lines, $index)), "\n");
      }
    }
    return '';
  }

  /**
   * Tests every quoted file is quoted as it is.
   */
  public function testTheCodeBlocksMatchTheFiles(): void {
    preg_match_all('/<!-- code: (\S+) -->\n\n```php\n(.*?)\n```/s', self::readme(), $blocks, PREG_SET_ORDER);
    $quoted = array_column($blocks, 1);
    $this->assertContains('src/Surface/RegistrationStep1Surface.php', $quoted);
    $this->assertContains('src/Surface/RegistrationStep2Surface.php', $quoted);
    $this->assertContains('src/Surface/RegistrationStep3Surface.php', $quoted);
    $this->assertContains('../data_surface_examples_compliance/src/SurfaceAlter/RegistrationComplianceAlter.php', $quoted);
    foreach ($blocks as [, $path, $code]) {
      $file = self::MODULE . '/' . $path;
      $this->assertFileExists($file);
      $this->assertSame(self::excerpt($file), $code, sprintf('The README quotes %s as it no longer is; copy the file from its class attributes down into the block.', $path));
    }
  }

  /**
   * Tests the Drush commands are the ones ExampleCalls spells.
   */
  public function testTheDrushCommandsAreTheCalls(): void {
    $readme = self::readme();
    $this->assertStringContainsString('drush tool:info ' . ExampleCalls::TOOL, $readme);
    foreach (array_keys(ExampleCalls::CALLS) as $call) {
      $this->assertStringContainsString(ExampleCalls::drush($call), $readme);
    }
  }

}
