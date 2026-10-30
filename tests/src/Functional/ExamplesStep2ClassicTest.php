<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 2 and its classic twin take and refuse the same answers.
 *
 * Both forms are posted the same values the way a browser without
 * JavaScript posts them, every answer in one request: a room of another
 * venue, a capacity the room cannot hold, then a valid set. Each pair is
 * refused by both, in the same words, or written by both, to the same
 * config object, identically.
 *
 * @see \Drupal\Tests\surface\FunctionalJavascript\ExamplesStep2ClassicAjaxTest
 *   For the twin's rebuild over AJAX.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ExamplesStep2ClassicTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The config object both forms write.
   */
  protected const CONFIG = 'surface_examples.registration_step2';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Posts the same answers to example 2 and to its classic twin.
   *
   * @param array $values
   *   The answers, keyed by setting.
   * @param string $resource_says
   *   Text example 2's page must show after the post.
   * @param string|null $classic_says
   *   Text the twin's page must show after the post; NULL for the same.
   *
   * @return string[]
   *   The settings both refused, the same on each page.
   */
  protected function postBoth(array $values, string $resource_says, ?string $classic_says = NULL): array {
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/2');
    $fields = [];
    foreach ($values as $key => $value) {
      $fields['surface[' . $key . ']'] = $value;
    }
    $this->submitForm($fields, 'Save');
    $assert->pageTextContains($resource_says);
    $assert->pageTextNotContains('The changes have been saved.');
    $refused = $this->refused($values, 'surface[%s]');

    $this->drupalGet('surface-examples/2/classic');
    $this->submitForm($values, 'Save configuration');
    $assert->pageTextContains($classic_says ?? $resource_says);
    $assert->pageTextNotContains('The configuration options have been saved.');
    $this->assertSame($refused, $this->refused($values, '%s'));
    $this->assertNotSame([], $refused);
    return $refused;
  }

  /**
   * Lists the settings whose element the page marks with an error.
   *
   * @param array $values
   *   The answers posted, keyed by setting.
   * @param string $name
   *   The element name pattern, with %s for the setting.
   *
   * @return string[]
   *   The settings refused.
   */
  protected function refused(array $values, string $name): array {
    $page = $this->getSession()->getPage();
    return array_values(array_filter(array_keys($values), static fn (string $key): bool => $page->find('css', sprintf('[name="%s"].error', sprintf($name, $key))) !== NULL));
  }

  /**
   * Reads the stored settings, without core's own keys.
   *
   * @return array
   *   The settings.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset(self::CONFIG);
    return array_diff_key($this->config(self::CONFIG)->get(), ['_core' => TRUE]);
  }

  /**
   * Tests both forms refuse the same answers and store a valid set alike.
   */
  public function testBothFormsTakeAndRefuseTheSameAnswers(): void {
    $shipped = $this->stored();

    // A room of the venue the page was built for, sent with another venue:
    // refused on the room by both. Example 2's form lists the rooms of the
    // venue sent, so core's own choice check speaks before the resource
    // does; the twin lists the rooms of the venue it was built for, so its
    // validateForm() speaks, in the resource's words.
    $refused = $this->postBoth(
      ['title' => 'Twin meetup', 'venue' => 'library', 'room' => 'riverside_main', 'capacity' => '20'],
      'The submitted value riverside_main in the Room element is not allowed.',
      'The value you selected is not a valid choice. This applies when Venue is Old Library. Choose Reading room or Garden room.',
    );
    $this->assertSame(['room'], $refused);
    $this->assertSame($shipped, $this->stored());

    // More people than the room seats, and fewer than one.
    $this->assertSame(['capacity'], $this->postBoth(
      ['title' => 'Twin meetup', 'venue' => 'riverside', 'room' => 'riverside_east', 'capacity' => '121'],
      'This value should be between 1 and 120. This applies when Room is East room.',
    ));
    $this->assertSame(['capacity'], $this->postBoth(
      ['title' => 'Twin meetup', 'venue' => 'riverside', 'room' => 'riverside_main', 'capacity' => '0'],
      'This value should be between 1 and 400. This applies when Room is Main hall.',
    ));
    $this->assertSame($shipped, $this->stored());

    // A valid set, through example 2 first, then the shipped settings put
    // back and the same set through the twin: the same object, the same
    // values, the same types.
    $valid = [
      'title' => 'Twin meetup',
      'open' => FALSE,
      'venue' => 'riverside',
      'room' => 'riverside_east',
      'capacity' => '120',
    ];
    $this->drupalGet('surface-examples/2');
    $this->submitForm([
      'surface[title]' => $valid['title'],
      'surface[open]' => $valid['open'],
      'surface[venue]' => $valid['venue'],
      'surface[room]' => $valid['room'],
      'surface[capacity]' => $valid['capacity'],
    ], 'Save');
    $this->assertSession()->pageTextContains('The changes have been saved.');
    $through_resource = $this->stored();
    $this->assertSame(array_replace($valid, ['capacity' => 120]), $through_resource);

    $this->container->get('config.factory')->getEditable(self::CONFIG)->setData($shipped)->save();
    $this->assertSame($shipped, $this->stored());
    $this->drupalGet('surface-examples/2/classic');
    $this->submitForm($valid, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame($through_resource, $this->stored());
  }

}
