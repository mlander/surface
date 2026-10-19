<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a structure's ports in a browser: the container's key moves it.
 *
 * The venue's seating reads the venue's layout and capacity through two
 * ports. Choosing another layout rebuilds the seating's aisle over AJAX,
 * a standing room drawing none at all; a capacity of a hundred or less
 * caps the rows at ten, said under the field; and the seating is saved
 * under the venue's own key, with nothing of the ports in it.
 *
 * @see \Drupal\data_surface_resource_test\Resource\Venue\VenueResource
 * @see docs/resources.md#ports
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class PortsFormTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface',
    'data_surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The venue's config object.
   */
  protected const CONFIG = 'data_surface_resource_test.venue';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Reads what the venue's config object holds.
   *
   * @return array
   *   The stored values, less what core keeps.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset(self::CONFIG);
    return array_diff_key($this->config(self::CONFIG)->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Lists the values a select offers, less its empty option.
   *
   * @param string $name
   *   The select's name.
   *
   * @return string[]
   *   The values.
   */
  protected function offered(string $name): array {
    $values = [];
    foreach ($this->assertSession()->selectExists($name)->findAll('css', 'option') as $option) {
      if ($option->getValue() !== '') {
        $values[] = (string) $option->getValue();
      }
    }
    return $values;
  }

  /**
   * Tests the layout and the capacity move the seating over AJAX.
   */
  public function testTheContainersKeysMoveTheStructure(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('surface-test/venue');
    $this->assertSame(['centre', 'side'], $this->offered('surface[seating][aisle]'), 'A theatre has a centre and a side aisle.');
    $assert_session->pageTextContains('Up to 10 rows for up to 100 guests.');
    $this->assertSame('10', $assert_session->fieldExists('surface[seating][rows]')->getAttribute('max'));

    // A banquet: the aisle rebuilt from the layout port.
    $page->selectFieldOption('surface[layout]', 'banquet');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertSame(['side', 'none'], $this->offered('surface[seating][aisle]'));

    // Standing: no aisle at all.
    $page->selectFieldOption('surface[layout]', 'standing');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->fieldNotExists('surface[seating][aisle]');

    // More guests than a hundred: the rows as declared.
    $capacity = $assert_session->fieldExists('surface[capacity]');
    $capacity->setValue('300');
    $capacity->blur();
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->pageTextNotContains('Up to 10 rows for up to 100 guests.');
    $this->assertSame('40', $assert_session->fieldExists('surface[seating][rows]')->getAttribute('max'));
    $assert_session->fieldExists('surface[seating][rows]')->setValue('30');

    $page->pressButton('Save');
    $this->assertTrue($assert_session->waitForText('The changes have been saved.'));
    $this->assertSame([
      'layout' => 'standing',
      'capacity' => 300,
      'seating' => ['rows' => 30, 'aisle' => 'centre', 'ushers' => 0],
    ], $this->stored(), 'The seating under the venue\'s key; the aisle a standing room does not have is kept, for when it applies again.');
  }

}
