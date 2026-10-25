<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface_examples_retitle\Retitle;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\data_surface\Traits\ServedApiTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 17 through its form and over HTTP, as two accounts.
 *
 * A retitler, whose role may edit pages, retitles through the form and
 * with PATCH; an account whose role may retitle and edit no page is
 * refused on each page, on the form and with a 422 per item, and nothing
 * is written; the titles read back as written.
 *
 * @see docs/decisions.md#a-gate-is-borrowed-never-copied
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class RetitleFormTest extends BrowserTestBase {

  use ServedApiTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples_retitle',
    'data_surface_react',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The resource's collection over HTTP.
   */
  protected const PATH = 'surface-api/ai.retitle';

  /**
   * Gets the ids of the pages the module wrote, by name.
   *
   * @return array<string, int>
   *   The ids.
   */
  protected function pages(): array {
    return (array) $this->container->get('state')->get(Retitle::NODES, []);
  }

  /**
   * Makes an account holding a role the module ships, and logs it in.
   *
   * @param string $role
   *   The role.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function loginAs(string $role): AccountInterface {
    $account = $this->drupalCreateUser();
    $this->assertNotFalse($account);
    $account->addRole($role);
    $account->save();
    $this->drupalLogin($account);
    return $account;
  }

  /**
   * Reads a page's title as stored now.
   *
   * @param string $name
   *   The page's name.
   *
   * @return string
   *   Its title.
   */
  protected function title(string $name): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $node = $storage->load($this->pages()[$name]);
    $this->assertInstanceOf(Node::class, $node);
    return (string) $node->getTitle();
  }

  /**
   * Tests the form, as an account the gate refuses and as the retitler.
   */
  public function testTheFormAsBothAccounts(): void {
    $pages = $this->pages();
    $this->assertSame(array_keys(Retitle::WRITTEN), array_keys($pages), 'The module wrote its three pages when it was installed.');
    $this->drupalGet('surface-examples/17');
    $this->assertSession()->statusCodeEquals(403);

    $this->loginAs('retitle_requester');
    $this->drupalGet('surface-examples/17');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('What this example shows');
    $this->submitForm(['surface[items][0][node]' => (string) $pages['two'], 'surface[style]' => 'sentence'], 'Save');
    $this->assertSession()->pageTextContains("You may not update WHERE TO PARK ON FESTIVAL DAYS: The 'edit any page content' permission is required.");
    $this->assertSame(Retitle::WRITTEN['two'], $this->title('two'), 'Nothing was written.');

    $this->loginAs('node_retitler');
    $this->drupalGet('surface-examples/17');
    $this->submitForm(['surface[items][0][node]' => (string) $pages['two'], 'surface[style]' => 'sentence'], 'Save');
    $this->assertSession()->statusMessageContains('The changes have been saved.');
    $this->assertSame('Where to park on festival days', $this->title('two'), 'The page drawn again made its calls as dry runs, and left the title saved.');
    $this->assertSession()->pageTextContains('A page of this example is not titled as the module wrote it, so each call below is made now as a dry run');
  }

  /**
   * Tests PATCH: a 422 per item for one account, a write for the other.
   */
  public function testThePatchRefusesPerItemAndWrites(): void {
    $pages = $this->pages();
    $items = ['items' => [['node' => $pages['one']], ['node' => $pages['three'], 'new_title' => 'Food stalls']]];
    $this->loginAs('retitle_requester');
    $refused = $this->problem($this->send('PATCH', self::PATH, $items), 422);
    $this->assertSame([
      [
        'path' => '/items/0/node',
        'code' => 'access',
        'message' => "You may not update volunteer briefing for the night shift: The 'edit any page content' permission is required.",
      ],
      [
        'path' => '/items/1/node',
        'code' => 'access',
        'message' => "You may not update menu of the food stalls: The 'edit any page content' permission is required.",
      ],
    ], $refused['errors']);
    $this->assertSame(Retitle::WRITTEN['one'], $this->title('one'));

    $this->loginAs('node_retitler');
    $dry = $this->decoded($this->send('PATCH', self::PATH . '?dryRun=true', $items));
    $this->assertFalse($dry['committed']);
    $this->assertSame('Volunteer Briefing for the Night Shift', $dry['outputs']['titles'][0]['new'], 'A dry run answers the titles it would write.');
    $this->assertSame(Retitle::WRITTEN['one'], $this->title('one'));
    $written = $this->decoded($this->send('PATCH', self::PATH, $items));
    $this->assertTrue($written['committed']);
    $this->assertSame([
      ['node' => $pages['one'], 'old' => Retitle::WRITTEN['one'], 'new' => 'Volunteer Briefing for the Night Shift'],
      ['node' => $pages['three'], 'old' => Retitle::WRITTEN['three'], 'new' => 'Food stalls'],
    ], $written['outputs']['titles'], 'A write answers what it wrote, read back.');
    // The read: the titles as stored, and the retitle holds no values of
    // its own.
    $this->assertSame('Volunteer Briefing for the Night Shift', $this->title('one'));
    $this->assertSame('Food stalls', $this->title('three'));
    $this->assertSame(Retitle::WRITTEN['two'], $this->title('two'), 'A page not sent is not touched.');
    $read = $this->decoded($this->send('GET', self::PATH));
    $this->assertSame('retitle', $read['operation']);
  }

}
