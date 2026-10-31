<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Behat\Mink\Element\NodeElement;
use Drupal\surface\Drush\Commands\CatalogCommand;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the catalog page: what `drush surface:catalog` lists, with links.
 *
 * On a site with the first resource and every door's module on: the page
 * is the permission's alone; it lists the newsletter with each door,
 * linked where the door has a URL; the per-resource page lists the
 * store's keys; the Refused table is empty until the resource test
 * module, whose broken fixtures do not build, is on. Its table is the
 * command's, row for row, read off the page as text: the drift check
 * holds for whatever the site has, not for a list written here.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class CatalogPageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'surface_examples_first',
    'tool',
    'surface_examples_react',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The page.
   */
  protected const PAGE = 'admin/config/development/surfaces';

  /**
   * Tests access, the listing, one resource, the refusals and the drift.
   *
   * One method: each one is a site install.
   */
  public function testTheCatalogPage(): void {
    $assert = $this->assertSession();

    // The permission's alone.
    $this->drupalGet(self::PAGE);
    $assert->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet(self::PAGE);
    $assert->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['access surface catalog']));
    $this->drupalGet(self::PAGE);
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('This listing is built for no account');

    // The newsletter, with its doors: linked where a door has a URL.
    $newsletter = $this->row('first.newsletter');
    $this->assertSame([
      'first.newsletter',
      'resource',
      'configure (update)',
      'administer surface_examples_first',
      'nothing',
    ], array_slice($this->cells($newsletter), 0, 5));
    $doors = [];
    foreach ($newsletter->findAll('css', 'li[data-door]') as $door) {
      $link = $door->find('css', 'a');
      $doors[(string) $door->getAttribute('data-door')] = $link === NULL ? 'text: ' . $door->find('css', 'code')?->getText() : (string) $link->getAttribute('href');
    }
    $base = base_path();
    $this->assertSame([
      'form' => $base . 'admin/config/services/surface-newsletter',
      'http' => $this->getAbsoluteUrl('/surface-api/first.newsletter'),
      'tool' => 'text: surface:first.newsletter:configure',
      'read tool' => 'text: surface:first.newsletter:configure:read',
      'openapi' => $base . 'surface-api/redoc#operation/first.newsletter:configure:write',
      'react' => $base . 'surface-react/first.newsletter/configure',
    ], $doors);

    // One resource in full: the store's keys.
    $newsletter->findLink('first.newsletter')->click();
    $assert->addressEquals(self::PAGE . '/first.newsletter');
    $this->drupalGet(self::PAGE . '/first.store');
    $assert->statusCodeEquals(200);
    $assert->elementTextContains('css', '#surface-catalog-facts', 'Label: Store location');
    $keys = array_map(fn (NodeElement $row): string => $this->cells($row)[0], $this->getSession()->getPage()->findAll('css', '#surface-catalog-keys tbody tr'));
    $this->assertSame(['country', 'region'], $keys);
    $assert->elementTextContains('css', '#surface-catalog-keys', 'regionOfCountry');
    $this->drupalGet(self::PAGE . '/no.such.thing');
    $assert->statusCodeEquals(404);

    // Nothing refused here.
    $this->drupalGet(self::PAGE);
    $assert->elementTextContains('css', '#surface-catalog-refused', 'Nothing discovery found was refused');
    $this->assertSame($this->commandRows(), $this->pageRows());

    // The resource test module's broken fixtures are refused, each with
    // its message and its file.
    $this->container->get('module_installer')->install(['surface_resource_test']);
    $this->rebuildContainer();
    $this->drupalGet(self::PAGE);
    $refused = [];
    foreach ($this->getSession()->getPage()->findAll('css', '#surface-catalog-refused tbody tr') as $row) {
      $refused[$this->cells($row)[0]] = $this->cells($row)[3];
    }
    $this->assertArrayHasKey('resource_test.broken.identity', $refused);
    $this->assertStringEndsWith('src/Resource/Broken/UndeclaredIdentityResource.php', $refused['resource_test.broken.identity']);
    $this->assertSame($this->commandRows(), $this->pageRows());
  }

  /**
   * Finds the table's first row of a resource or structure.
   *
   * @param string $resource
   *   Its id.
   *
   * @return \Behat\Mink\Element\NodeElement
   *   The row.
   */
  protected function row(string $resource): NodeElement {
    foreach ($this->getSession()->getPage()->findAll('css', '#surface-catalog tbody tr') as $row) {
      if ($this->cells($row)[0] === $resource) {
        return $row;
      }
    }
    $this->fail($resource . ' is listed.');
  }

  /**
   * Reads a row's cells as text.
   *
   * @param \Behat\Mink\Element\NodeElement $row
   *   The row.
   *
   * @return list<string>
   *   Each cell's text.
   */
  protected function cells(NodeElement $row): array {
    return array_map(static fn (NodeElement $cell): string => trim($cell->getText()), $row->findAll('css', 'td'));
  }

  /**
   * Reads the page's table as the command prints it: doors by their name.
   *
   * @return list<list<string>>
   *   The rows.
   */
  protected function pageRows(): array {
    $rows = [];
    foreach ($this->getSession()->getPage()->findAll('css', '#surface-catalog tbody tr') as $row) {
      $cells = $this->cells($row);
      $doors = array_map(static fn (NodeElement $door): string => (string) $door->getAttribute('data-door'), $row->findAll('css', 'li[data-door]'));
      if ($doors !== []) {
        $cells[5] = implode(', ', $doors);
      }
      $rows[] = $cells;
    }
    return $rows;
  }

  /**
   * Lists the command's table rows, built in this process.
   *
   * @return list<list<string>>
   *   The rows.
   */
  protected function commandRows(): array {
    return CatalogCommand::rows($this->container->get('surface.site_catalog')->describe());
  }

}
