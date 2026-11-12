<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the form's drawer and the widgets read the document alone.
 *
 * No class under src/Form/Document or src/Widget imports the data
 * surface, its definitions or Typed Data: what they draw is the served
 * document, and what changes when a value does is a new one, which the
 * host asks for. Read from each file's own `use` statements.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
#[Group('surface')]
class DocumentFormIsolationTest extends UnitTestCase {

  /**
   * The names no file in the two directories may import.
   */
  protected const REFUSED = [
    'Drupal\surface\SurfaceInterface',
    'Drupal\surface\DefinitionMap',
    'Drupal\surface\DefinitionMetadata',
    'Drupal\surface\Entry',
    'Drupal\surface\Variants',
  ];

  /**
   * The namespace no file in the two directories may import from.
   */
  protected const REFUSED_NAMESPACE = 'Drupal\Core\TypedData\\';

  /**
   * Tests that no file imports the data surface, a definition or Typed Data.
   */
  public function testNothingReadsTheSurface(): void {
    $module = dirname(__DIR__, 3);
    $files = [...glob($module . '/src/Form/Document/*.php'), ...glob($module . '/src/Widget/*.php')];
    $this->assertNotEmpty(glob($module . '/src/Form/Document/*.php'), 'The drawer is under src/Form/Document.');
    $found = [];
    foreach ($files as $file) {
      preg_match_all('/^use\s+([^;\s]+)\s*;/m', (string) file_get_contents($file), $matches);
      foreach ($matches[1] as $imported) {
        if (in_array($imported, self::REFUSED, TRUE) || str_starts_with($imported, self::REFUSED_NAMESPACE)) {
          $found[] = basename(dirname($file)) . '/' . basename($file) . ': ' . $imported;
        }
      }
    }
    $this->assertSame([], $found, 'The drawer and the widget layer import nothing of the data surface.');
  }

}
