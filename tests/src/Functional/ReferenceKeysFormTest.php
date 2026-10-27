<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\data_surface_examples_references\Sponsorship;
use Drupal\data_surface_examples_references\Storage\SponsorshipStorage;
use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\data_surface\Traits\ServedApiTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 16 through its form, the lookup and the React page.
 *
 * The form draws a reference key as a select up to the limit and as
 * core's entity autocomplete past it, and saves the id either way; the
 * lookup answers for the account, refusing one without the operation's
 * permission and leaving out what an account may not reference; the
 * React page is told where the lookup is, and the full schema it narrows
 * from states a bare key's id per entity type, so it narrows with no
 * request.
 *
 * @see docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ReferenceKeysFormTest extends BrowserTestBase {

  use ServedApiTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples_references',
    'data_surface_react',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The lookup.
   */
  protected const LOOKUP = 'surface-api/event.sponsorship:lookup';

  /**
   * Gets the ids of the nodes the module wrote, by name.
   *
   * @return array<string, int>
   *   The ids.
   */
  protected function nodes(): array {
    return (array) $this->container->get('state')->get(Sponsorship::NODES, []);
  }

  /**
   * Tests the form: a select up to the limit, the autocomplete past it.
   */
  public function testTheFormListsThenLooksUp(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'set event sponsorship',
      'administer site configuration',
      'bypass node access',
      'administer event venues',
    ]));
    $nodes = $this->nodes();
    $this->assertSame(['article', 'draft', 'page'], array_keys($nodes), 'The module wrote its three nodes when it was installed.');
    $this->drupalGet('surface-examples/16');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'select[name="surface[venue]"]');
    $this->assertSession()->optionExists('surface[page]', (string) $nodes['article']);
    $this->assertSession()->optionNotExists('surface[page]', (string) $nodes['page']);
    $this->submitForm(['surface[venue]' => 'library', 'surface[page]' => (string) $nodes['article']], 'Save');
    $this->assertSession()->statusMessageContains('The changes have been saved.');
    $stored = $this->config(SponsorshipStorage::CONFIG);
    $this->assertSame('library', $stored->get('venue'));
    $this->assertSame(Node::load($nodes['article'])->uuid(), $stored->get('page'), 'An article is stored as its uuid, a content entity.');
    $this->assertTrue($this->assertSession()->optionExists('surface[page]', (string) $nodes['article'])->isSelected(), 'Read back as its id.');

    // Past the limit: core's entity autocomplete, read back as the id.
    $this->config('data_surface.settings')->set('reference_list_limit', 1)->save();
    $this->drupalGet('surface-examples/16');
    $venue = $this->assertSession()->elementExists('css', 'input[name="surface[venue]"]');
    $this->assertStringContainsString('/entity_reference_autocomplete/event_venue/default/', (string) $venue->getAttribute('data-autocomplete-path'));
    $this->assertSame('Old Library (library)', $venue->getValue());
    $this->assertSame('Sponsor packages (' . $nodes['article'] . ')', $this->assertSession()->elementExists('css', 'input[name="surface[page]"]')->getValue(), 'The stored uuid, drawn as the id.');
    $this->submitForm([
      'surface[venue]' => 'Riverside Hall (riverside)',
      'surface[page]' => 'Sponsor packages (' . $nodes['article'] . ')',
    ], 'Save');
    $this->assertSession()->statusMessageContains('The changes have been saved.');
    $this->container->get('config.factory')->reset();
    $this->assertSame('riverside', $this->config(SponsorshipStorage::CONFIG)->get('venue'));
    $this->submitForm([
      'surface[venue]' => 'concert_hall',
      'surface[page]' => 'Sponsor stand map (' . $nodes['page'] . ')',
    ], 'Save');
    $this->assertSession()->pageTextContains('This entity (event_venue: concert_hall) cannot be referenced.');
    $this->assertSession()->pageTextContains(sprintf('This entity (node: %d) cannot be referenced.', $nodes['page']));
  }

  /**
   * Tests the lookup's answer, and whom it answers.
   */
  public function testTheLookupRoute(): void {
    $this->problem($this->send('GET', self::LOOKUP . '?key=/venue&q=riv', NULL, [], FALSE), 403);
    $nodes = $this->nodes();
    $this->drupalLogin($this->drupalCreateUser(['set event sponsorship', 'access content', 'bypass node access']));
    $this->assertSame(
      [
        'key' => '/venue',
        'entityType' => 'event_venue',
        'bundles' => [],
        'items' => [['id' => 'riverside', 'label' => 'Riverside Hall']],
      ],
      $this->decoded($this->send('GET', self::LOOKUP . '?key=/venue&q=riv')),
    );
    $this->assertSame([
      ['id' => $nodes['draft'], 'label' => 'Draft sponsor terms'],
      ['id' => $nodes['article'], 'label' => 'Sponsor packages'],
    ], $this->decoded($this->send('GET', self::LOOKUP . '?key=page&q=Sponsor'))['items']);
    $this->assertSame([['id' => (string) $nodes['page'], 'label' => 'Sponsor stand map']], $this->decoded($this->send('GET', self::LOOKUP . '?key=/thing/id&type=node&q=stand'))['items'], "A bare key's id, in its type: a string.");
    $this->problem($this->send('GET', self::LOOKUP . '?key=/setting&type=node'), 404);
    $this->problem($this->send('POST', self::LOOKUP . '?key=/venue', []), 405);

    // An account that may not see unpublished content finds no draft.
    $this->drupalLogin($this->drupalCreateUser(['set event sponsorship', 'access content']));
    $this->assertSame([['id' => $nodes['article'], 'label' => 'Sponsor packages']], $this->decoded($this->send('GET', self::LOOKUP . '?key=/page&q=Sponsor'))['items']);
  }

  /**
   * Tests the React page and the full schema it narrows from.
   */
  public function testTheReactPageNarrowsWithNoRequest(): void {
    $this->drupalLogin($this->drupalCreateUser(['set event sponsorship', 'access content']));
    $this->drupalGet('surface-react/event.sponsorship/configure');
    $this->assertSession()->statusCodeEquals(200);
    $settings = $this->getDrupalSettings()['dataSurfaceReact']['endpoints'];
    $this->assertStringEndsWith('/surface-api/event.sponsorship:lookup', $settings['lookup']);

    $schema = $this->decoded($this->send('GET', 'surface-api/event.sponsorship/schema'), 200, 'application/schema+json');
    $thing = $schema['$defs']['event.sponsorship.thing'];
    $this->assertTrue($thing['properties']['id']['x-surface']['enumerated'], 'Stated per entity type, not refined on request.');
    $this->assertArrayNotHasKey('refine', $thing['properties']['id']['x-surface']);
    $node = array_values(array_filter($thing['allOf'], static fn (array $condition): bool => ($condition['if']['properties']['entity_type']['const'] ?? NULL) === 'node'));
    $article = (string) $this->nodes()['article'];
    $this->assertContains($article, array_column($node[0]['then']['properties']['id']['oneOf'], 'const'), 'The candidates of a node, for this account: what the page draws once node is chosen.');
    $this->assertNotContains((string) $this->nodes()['draft'], array_column($node[0]['then']['properties']['id']['oneOf'], 'const'));
    $this->assertSame('select', $schema['properties']['venue']['x-surface']['widget']);
    $this->assertSame(['entityType' => 'event_venue'], $schema['properties']['venue']['x-surface']['reference']);
  }

}
