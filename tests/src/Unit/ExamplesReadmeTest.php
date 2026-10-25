<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\data_surface_examples\CodeLines;
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
 * the three answers it quotes. The line counts it states for example 2's
 * classic twin are counted here from the files, as the twin's page counts
 * them.
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
    $this->assertContains('src/Resource/RegistrationStep1Resource.php', $quoted);
    $this->assertContains('src/Resource/RegistrationStep2Resource.php', $quoted);
    $this->assertContains('src/Resource/RegistrationStep3Resource.php', $quoted);
    $this->assertContains('src/Form/RegistrationStep1ClassicForm.php', $quoted);
    $this->assertContains('src/Form/RegistrationStep2ClassicForm.php', $quoted);
    $this->assertContains('../data_surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php', $quoted);
    $this->assertContains('../data_surface_examples_workflow/src/Alter/FeesWorkflowAlter.php', $quoted);
    $this->assertContains('../data_surface_examples_workflow/src/Alter/ProgramWorkflowAlter.php', $quoted);
    $concepts = [
      'Resource/BulletinResource',
      'Structure/PersonStructure',
      'Shape/PhoneNumber',
      'Keys/TicketTypes',
      'Access/FeesAccess',
      'Resource/ProgramResource',
      'Resource/DoorsResource',
      'Resource/SpotlightResource',
    ];
    foreach ($concepts as $class) {
      $this->assertContains('src/' . $class . '.php', $quoted);
    }
    // Example 12's provider is the main module's, and example 13 is the
    // component block's submodule.
    $this->assertContains('../../src/Variants/ComponentProps.php', $quoted);
    $this->assertContains('../data_surface_component_block/src/Structure/ComponentBlockStructure.php', $quoted);
    $this->assertContains('../data_surface_component_block/src/Plugin/Block/ComponentBlock.php', $quoted);
    // Example 14: the owner's way whole, and the alter's way, whose alter
    // is the keys module's.
    $extension = [
      'Resource/MailerResource',
      'Variants/CredentialSources',
      'Structure/PastedCredentialStructure',
      'Structure/ManagedCredentialStructure',
      'Resource/WebhookResource',
    ];
    foreach ($extension as $class) {
      $this->assertContains('src/' . $class . '.php', $quoted);
    }
    $this->assertContains('../data_surface_examples_keys/src/Alter/WebhookKeyAlter.php', $quoted);
    // Example 15: the entity type, the resource, the structures of its
    // two operations over the venues, the storage and the access class.
    $venues = [
      'Entity/EventVenue',
      'Resource/VenueResource',
      'Structure/VenueListingStructure',
      'Structure/VenueReorderStructure',
      'Structure/VenueOrderStructure',
      'Storage/VenueStorage',
      'Access/VenueAccess',
    ];
    foreach ($venues as $class) {
      $this->assertContains('src/' . $class . '.php', $quoted);
    }
    // Example 16: the resource, in its own module.
    $this->assertContains('../data_surface_examples_references/src/Resource/SponsorshipResource.php', $quoted);
    // Example 17: the resource, its item and its storage, in its own module.
    foreach (['Resource/RetitleResource', 'Structure/RetitleItemStructure', 'Storage/RetitleStorage'] as $class) {
      $this->assertContains('../data_surface_examples_retitle/src/' . $class . '.php', $quoted);
    }
    foreach ($blocks as [, $path, $code]) {
      $file = self::MODULE . '/' . $path;
      $this->assertFileExists($file);
      $this->assertSame(self::excerpt($file), $code, sprintf('The README quotes %s as it no longer is; copy the file from its class attributes down into the block.', $path));
    }
  }

  /**
   * Tests each component the README quotes is the file it names, whole.
   */
  public function testTheComponentBlocksMatchTheFiles(): void {
    preg_match_all('/<!-- component: (\S+) -->\n\n```yaml\n(.*?)\n```/s', self::readme(), $blocks, PREG_SET_ORDER);
    $this->assertSame(['components/card/card.component.yml'], array_column($blocks, 1));
    foreach ($blocks as [, $path, $yaml]) {
      $this->assertSame(rtrim((string) file_get_contents(self::MODULE . '/' . $path), "\n"), $yaml, sprintf('The README quotes %s as it no longer is.', $path));
    }
  }

  /**
   * Tests each config schema entry the README quotes is the schema file's.
   */
  public function testTheSchemaEntriesMatchTheFile(): void {
    preg_match_all('/<!-- schema: (\S+) -->\n\n```yaml\n(.*?)\n```/s', self::readme(), $blocks, PREG_SET_ORDER);
    $this->assertSame(['data_surface_examples.event_venue.*'], array_column($blocks, 1));
    foreach ($blocks as [, $key, $yaml]) {
      $this->assertSame(FirstResourceDocsTest::entry(self::MODULE . '/config/schema/data_surface_examples.schema.yml', $key), $yaml, sprintf('The README quotes the %s entry as it no longer is.', $key));
    }
  }

  /**
   * Tests the line counts stated for example 2's twin are the files'.
   */
  public function testTheSecondTwinsLineCountsAreTheFiles(): void {
    $src = self::MODULE . '/src/';
    $classic = CodeLines::count($src . 'Form/RegistrationStep2ClassicForm.php');
    $resource = CodeLines::count($src . 'Resource/RegistrationStep2Resource.php') + CodeLines::count($src . 'Storage/RegistrationStep2Storage.php');
    $this->assertGreaterThan(0, $classic);
    $this->assertGreaterThan(0, $resource);
    $stated = preg_replace('/\s+/', ' ', self::readme());
    $this->assertStringContainsString(sprintf("The twin is **%d lines of code**; example 2's resource and its storage are **%d**.", $classic, $resource), $stated, 'The README states line counts for example 2\'s twin that the files no longer have.');
  }

  /**
   * Tests the Drush commands are the ones ExampleCalls spells.
   */
  public function testTheDrushCommandsAreTheCalls(): void {
    $readme = self::readme();
    $this->assertStringContainsString('drush tool:info ' . ExampleCalls::TOOL, $readme);
    $this->assertStringContainsString(ExampleCalls::drushRead(), $readme);
    foreach (array_keys(ExampleCalls::CALLS) as $call) {
      $this->assertStringContainsString(ExampleCalls::drush($call), $readme);
    }
  }

}
