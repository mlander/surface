<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\data_surface\Pipeline\ResultDocument;
use Drupal\data_surface_examples\ConceptPanel;
use Drupal\data_surface_examples\Resource\VenueResource;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\data_surface\Traits\ExampleFilesPanelTrait;
use Drupal\Tests\data_surface\Traits\ServedApiTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 15's venues over HTTP and through their forms.
 *
 * GET on the collection answers the listing, its query the values; POST
 * there adds a venue, 201 with its Location; GET, PATCH and DELETE at that
 * Location read, change and remove it; the reorder is a custom method run
 * with PATCH. The add and edit forms save, the remove form asks first, and
 * the example's page draws its venues and makes its calls.
 *
 * @see docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class VenueApiTest extends BrowserTestBase {

  use ExampleFilesPanelTrait;
  use ServedApiTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples',
    'data_surface_react',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The collection.
   */
  protected const COLLECTION = 'surface-api/event.venue';

  /**
   * A fourth venue.
   */
  protected const TOWN_HALL = [
    'label' => 'Town Hall',
    'id' => 'town_hall',
    'capacity' => 300,
    'address' => '1 Market Square',
    'rooms' => [['id' => 'town_hall_council', 'label' => 'Council chamber', 'seats' => 120]],
  ];

  /**
   * Loads the venues' weights afresh.
   *
   * @return array<string, int>
   *   Each venue's weight, by machine name.
   */
  protected function weights(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('event_venue');
    $storage->resetCache();
    $weights = [];
    foreach ($storage->loadMultiple() as $id => $venue) {
      $weights[(string) $id] = (int) $venue->get('weight');
    }
    ksort($weights);
    return $weights;
  }

  /**
   * Tests the paths: the listing, a venue added, changed and removed.
   */
  public function testTheCollectionListsAndTheVenuesAreWritten(): void {
    $this->problem($this->send('GET', self::COLLECTION, NULL, [], FALSE), 403);
    $this->drupalLogin($this->drupalCreateUser([VenueResource::VIEW, VenueResource::ADMINISTER]));

    // The listing: its outputs alone, the query string its values.
    $response = $this->send('GET', self::COLLECTION . '?limit=2');
    $this->assertSame([
      'items' => [
        ['id' => 'riverside', 'label' => 'Riverside Hall', 'capacity' => 520, 'weight' => 0],
        ['id' => 'library', 'label' => 'Old Library', 'capacity' => 90, 'weight' => 1],
      ],
      'total' => 3,
    ], $this->decoded($response));
    $this->assertStringEndsWith('/surface-api/event.venue/schema>; rel="describedby"', $response->getHeaderLine('Link'));
    $this->assertSame('3', $response->getHeaderLine('Contract-Version'));
    $harbour = ['id' => 'harbour', 'label' => 'Harbour Centre', 'capacity' => 950, 'weight' => 2];
    $this->assertSame([$harbour], $this->decoded($this->send('GET', self::COLLECTION . '?offset=2'))['items']);
    $refused = $this->problem($this->send('GET', self::COLLECTION . '?limit=500'), 422);
    $limit = ['path' => '/limit', 'code' => 'not_in_range', 'message' => 'This value should be between 1 and 50.'];
    $this->assertSame([$limit], $refused['errors']);
    $schema = $this->decoded($this->send('GET', self::COLLECTION . '/schema'), 200, 'application/schema+json');
    $this->assertSame(['offset', 'limit', 'status'], array_keys($schema['properties']), "The collection's schema is the listing's.");
    $this->assertSame('create', $this->decoded($this->send('GET', self::COLLECTION . ':create'))['operation'], "The create's envelope is its custom method's.");
    $this->assertSame('list', $this->decoded($this->send('GET', self::COLLECTION . ':list'))['operation']);
    $this->problem($this->send('PATCH', self::COLLECTION, ['venues' => []]), 405);

    // Added: 201, and its Location is where it is read.
    $response = $this->send('POST', self::COLLECTION, self::TOWN_HALL);
    $added = $this->decoded($response, 201);
    $this->assertSame([...ResultDocument::KEYS, 'outputs', 'contract', 'created'], array_keys($added));
    $this->assertStringEndsWith('/surface-api/event.venue/town_hall', $response->getHeaderLine('Location'));
    $this->assertSame(['resource' => 'event.venue', 'operation' => 'update', 'parameters' => ['id' => 'town_hall']], $added['created']);
    $read = $this->send('GET', self::COLLECTION . '/town_hall');
    $this->assertSame('update', $this->decoded($read)['operation']);
    $tag = $read->getHeaderLine('ETag');
    $this->assertNotSame('', $tag);
    $this->assertSame(4, $this->decoded($this->send('GET', self::COLLECTION))['total']);

    // Changed, held to the ETag it was read with.
    $changed = $this->decoded($this->send('PATCH', self::COLLECTION . '/town_hall', ['capacity' => 350], ['If-Match' => $tag]));
    $this->assertSame(['/capacity'], $changed['changes']);
    $this->problem($this->send('PATCH', self::COLLECTION . '/town_hall', ['capacity' => 360], ['If-Match' => $tag]), 412);

    // Reordered: a custom method, run with PATCH, written in one commit.
    $first = ['id' => 'town_hall', 'weight' => -1];
    $reorder = self::COLLECTION . ':reorder';
    $this->problem($this->send('POST', $reorder, ['venues' => [$first]]), 405);
    $rehearsed = $this->decoded($this->send('PATCH', $reorder . '?dryRun=true', ['venues' => [$first]]));
    $this->assertTrue($rehearsed['x-surface-dry-run']);
    $this->assertSame(0, $this->weights()['town_hall']);
    $concert = ['id' => 'concert_hall', 'weight' => 0];
    $unknown = $this->problem($this->send('PATCH', $reorder, ['venues' => [$first, $concert]]), 422);
    $named = ['path' => '/venues/1/id', 'code' => 'not_found', 'message' => 'No venue is named concert_hall.'];
    $this->assertSame([$named], $unknown['errors']);
    $this->assertSame(0, $this->weights()['town_hall'], 'Refused as a whole.');
    $last = ['id' => 'riverside', 'weight' => 4];
    $reordered = $this->decoded($this->send('PATCH', $reorder, ['venues' => [$first, $last]]));
    $this->assertTrue($reordered['committed']);
    $this->assertSame(['harbour' => 2, 'library' => 1, 'riverside' => 4, 'town_hall' => -1], $this->weights());
    $listed = $this->decoded($this->send('GET', self::COLLECTION))['items'];
    $this->assertSame(['town_hall', 'library', 'harbour', 'riverside'], array_column($listed, 'id'));

    // Removed with DELETE.
    $removed = $this->decoded($this->send('DELETE', self::COLLECTION . '/town_hall'));
    $this->assertTrue($removed['committed']);
    $this->assertSame('Town Hall', $removed['values']['label']);
    $this->problem($this->send('DELETE', self::COLLECTION . '/town_hall'), 404);
    $this->assertSame(3, $this->decoded($this->send('GET', self::COLLECTION))['total']);
  }

  /**
   * Tests the forms, the remove confirmation and the example's page.
   */
  public function testTheFormsAndThePage(): void {
    $assert = $this->assertSession();
    $this->drupalGet('surface-examples/15/add');
    $assert->statusCodeEquals(403);
    $this->drupalGet('surface-examples/15');
    $assert->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser([
      VenueResource::VIEW,
      VenueResource::ADMINISTER,
      'administer site configuration',
    ]));

    $this->drupalGet('surface-examples/15/add');
    $assert->statusCodeEquals(200);
    $this->submitForm([
      'surface[label]' => 'Town Hall',
      'surface[id]' => 'town_hall',
      'surface[capacity]' => '300',
      'surface[address]' => '1 Market Square',
    ], 'Save');
    $assert->pageTextContains('The venue Town Hall has been added.');
    $assert->addressEquals('surface-examples/15');
    // The page's calls find a venue of their name, so they write nothing.
    $assert->pageTextContains('This site has a venue named town_hall, so each call below is made now as a dry run, and nothing is written');
    $storage = $this->container->get('entity_type.manager')->getStorage('event_venue');
    $this->assertSame(300, $storage->loadUnchanged('town_hall')->get('capacity'));

    // Example 2 offers it at the next request.
    $this->drupalGet('surface-examples/2');
    $assert->optionExists('surface[venue]', 'town_hall');

    // On edit the machine name is held.
    $this->drupalGet('surface-examples/15/venue/town_hall');
    $assert->statusCodeEquals(200);
    $assert->fieldDisabled('surface[id]');
    $this->submitForm(['surface[capacity]' => '350'], 'Save');
    $assert->pageTextContains('The venue Town Hall has been saved.');
    $this->assertSame(350, $storage->loadUnchanged('town_hall')->get('capacity'));

    // Removed after a confirmation.
    $this->drupalGet('surface-examples/15/venue/town_hall/delete');
    $assert->pageTextContains('town_hall is removed, and this cannot be undone.');
    $this->submitForm([], 'Remove a venue');
    $assert->pageTextContains('The venue Town Hall has been removed.');
    $this->assertNull($storage->loadUnchanged('town_hall'));

    // The page: the venues, the paths, the tools, the files and the calls,
    // which leave the three venues shipped as they were.
    $this->drupalGet('surface-examples/15');
    $assert->statusCodeEquals(200);
    $assert->elementsCount('css', '.data-surface-venues tbody tr', 3);
    $assert->pageTextContains('Riverside Hall');
    $assert->linkByHrefExists('/surface-examples/15/venue/riverside');
    $assert->linkByHrefExists('/surface-react/event.venue/create');
    $assert->elementsCount('css', '.data-surface-concept-paths tbody tr', count(ConceptPanel::VENUE_PATHS));
    $assert->pageTextContains('data_surface:event.venue:reorder');
    $assert->pageTextContains('Town Hall removed: written, so the three venues shipped are what is left');
    $assert->pageTextContains('No venue is named concert_hall.');
    $this->assertFilesPanel(15);
    $storage->resetCache();
    $this->assertSame(['harbour', 'library', 'riverside'], array_keys($storage->loadMultiple()));
  }

}
