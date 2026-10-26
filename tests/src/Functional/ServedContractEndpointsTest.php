<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\Pipeline\ResultDocument;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\data_surface\Traits\ServedApiTrait;

/**
 * Tests the React page and the API's reads over HTTP: contract 3.
 *
 * The examples' landing page links the React page of examples 1 to 3.
 * Example 2 is the subject throughout, because it is where an answer
 * narrows another: the venue the rooms, the room the capacity. The page
 * and the envelope answer an administrator and refuse anonymous, as
 * problem details; the full schema, `application/schema+json`, is the
 * same whatever is stored, and may be cached; `POST .../schema` narrows
 * the room by the venue and shows an orphaned room stale; and a dry run
 * refuses a room of another venue and a capacity above the room's,
 * accepts a valid payload, and writes nothing either way. On example 3,
 * with example 4's module on, the narrowing follows the license the
 * module's alter mounted.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ServedContractEndpointsTest extends BrowserTestBase {

  use ServedApiTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_react',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The config object example 2 keeps its settings in.
   */
  protected const CONFIG = 'data_surface_examples.registration_step2';

  /**
   * Example 2's path.
   */
  protected const STEP2 = 'surface-api/registration.step2';

  /**
   * Narrows example 2 or 3 for values: POST .../schema.
   *
   * @param string $path
   *   The resource's path.
   * @param array $values
   *   The values document.
   * @param string[] $stale
   *   The stale paths left empty, as JSON Pointers.
   *
   * @return array
   *   The envelope.
   */
  protected function narrowed(string $path, array $values, array $stale = []): array {
    return $this->decoded($this->send('POST', $path . '/schema', $values, $stale === [] ? [] : ['X-Surface-Stale' => implode(', ', $stale)]));
  }

  /**
   * Reads the values a property's oneOf offers.
   *
   * @param array $property
   *   The property schema.
   *
   * @return array
   *   The values, the untitled null entry left out.
   */
  protected function offered(array $property): array {
    return array_column(array_filter($property['oneOf'], fn (array $choice): bool => isset($choice['title'])), 'const');
  }

  /**
   * Tests the full schema: its access, its content, and that no value moves it.
   */
  public function testFullSchemaReadsNoValue(): void {
    $this->problem($this->send('GET', self::STEP2 . '/schema'), 403);

    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $response = $this->send('GET', self::STEP2 . '/schema');
    $first = (string) $response->getBody();
    // The full schema itself, served as one, written in contract 4, said
    // in the header and the document, which says where it is served.
    // Decision: see
    // docs/decisions.md#contract-4-fewer-keywords-nothing-new-on-the-wire.
    $full = $this->decoded($response, 200, 'application/schema+json');
    $this->assertSame(['$schema', '$id', 'title'], array_slice(array_keys($full), 0, 3));
    $this->assertSame(4, $full['x-surface']['contract']);
    $this->assertSame('configure', $full['x-surface']['operation']['id']);
    $this->assertStringEndsWith('/surface-api/registration.step2/schema', $full['$id']);
    $room = $full['properties']['room'];
    $this->assertCount(6, $this->offered($room));
    $this->assertSame(['widget' => 'select', 'dependsOn' => ['venue'], 'enumerated' => TRUE], $room['x-surface']);
    // Every listable dependency as a conditional: three venues, six rooms.
    $this->assertCount(9, $full['allOf']);
    // Cacheable: it reads nothing stored, so it is not max-age 0 as the
    // envelope is, which is never stored.
    $this->assertSame('-1 (Permanent)', $response->getHeaderLine('X-Drupal-Cache-Max-Age'));
    $this->assertStringContainsString('url.query_args', $response->getHeaderLine('X-Drupal-Cache-Contexts'));
    $this->assertStringContainsString('no-store', $this->send('GET', self::STEP2)->getHeaderLine('Cache-Control'));

    // Another stored state: the envelope follows it, the full schema does
    // not.
    $this->config(self::CONFIG)
      ->set('venue', 'harbour')
      ->set('room', 'harbour_deck')
      ->set('capacity', 90)
      ->save();
    $this->assertSame('harbour_deck', $this->decoded($this->send('GET', self::STEP2))['values']['room']);
    $this->assertSame($first, (string) $this->send('GET', self::STEP2 . '/schema')->getBody());

    // An operation that names nothing is no path.
    $this->problem($this->send('GET', 'surface-api/registration.step2:nothing/schema'), 404);
  }

  /**
   * Tests who may read the page and the envelope, and its shape.
   */
  public function testAccessAndShape(): void {
    $this->drupalGet('surface-react/registration.step2/configure');
    $this->assertSession()->statusCodeEquals(403);
    // Refused as problem details, never Drupal's error page.
    $denied = $this->problem($this->send('GET', self::STEP2), 403);
    $this->assertSame('about:blank', $denied['type']);
    $this->assertSame('Forbidden', $denied['title']);
    $this->problem($this->send('POST', self::STEP2 . '/schema', [], [], FALSE), 403);

    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    // The examples' landing page links each form's React twin.
    $this->drupalGet('surface-examples');
    foreach ([1, 2, 3] as $number) {
      $this->assertSession()->linkByHrefExists('/surface-react/registration.step' . $number . '/configure');
    }
    $this->drupalGet('surface-react/registration.step2/configure');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->titleEquals('React: Configure registration (step 2) | Drupal');
    $this->assertSession()->elementExists('css', '#data-surface-react');
    $settings = $this->getDrupalSettings()['dataSurfaceReact'];
    $this->assertSame('registration.step2', $settings['resource']);
    $this->assertSame('configure', $settings['operation']);
    $this->assertStringEndsWith('/surface-api', $settings['apiBase']);
    $this->assertStringEndsWith('/session/token', $settings['tokenUrl']);
    // Where the API answers it: the resource, the method the operation.
    $this->assertStringEndsWith('/surface-api/registration.step2', $settings['endpoints']['read']);
    $this->assertStringEndsWith('/surface-api/registration.step2/schema', $settings['endpoints']['schema']);
    $this->assertSame('PATCH', $settings['endpoints']['write']['method']);
    $this->assertSame($settings['endpoints']['read'], $settings['endpoints']['write']['url']);

    $response = $this->send('GET', self::STEP2);
    $envelope = $this->decoded($response);
    $this->assertSame(['resource', 'operation', 'label', 'schema', 'values', 'stale', 'notes'], array_keys($envelope));
    $this->assertSame('registration.step2', $envelope['resource']);
    $this->assertSame('configure', $envelope['operation']);
    $this->assertSame('https://json-schema.org/draft/2020-12/schema', $envelope['schema']['$schema']);
    $this->assertSame(4, $envelope['schema']['x-surface']['contract']);
    $this->assertStringEndsWith('/surface-api/registration.step2', $envelope['schema']['$id']);
    $this->assertSame(['venue'], $envelope['schema']['properties']['room']['x-surface']['dependsOn']);
    $this->assertSame('riverside', $envelope['values']['venue']);
    $this->assertSame([], $envelope['notes']);
    // The fingerprint is the ETag, strong, and the full schema is linked.
    $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]+"$/', $response->getHeaderLine('ETag'));
    $this->assertMatchesRegularExpression('#^<https?://[^>]+/surface-api/registration\.step2/schema>; rel="describedby"$#', $response->getHeaderLine('Link'));
    // HEAD answers the same headers, and no body.
    $head = $this->send('HEAD', self::STEP2);
    $this->assertSame(200, $head->getStatusCode());
    $this->assertSame($response->getHeaderLine('ETag'), $head->getHeaderLine('ETag'));

    // A resource that names nothing is a 404, and a POST without the
    // session's token is refused before it is read.
    $this->problem($this->send('GET', 'surface-api/registration.step9'), 404);
    $this->problem($this->send('POST', self::STEP2 . '/schema', [], [], FALSE), 403);
    $this->problem($this->send('PATCH', self::STEP2 . '?dryRun=true', [], [], FALSE), 403);
  }

  /**
   * Tests the narrowing narrows the room by the venue, and keeps a stale room.
   *
   * The orphaned room is the form's own: shown stale, held unanswered so
   * the capacity is no longer capped by it, round-tripped by its path,
   * and refused by a save from there.
   */
  public function testNarrowingNarrowsTheRoomByTheVenue(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $values = [
      'title' => 'Spring meetup',
      'capacity' => 50,
      'open' => TRUE,
      'venue' => 'library',
      'room' => 'riverside_main',
    ];
    $refined = $this->narrowed(self::STEP2, $values);
    $room = $refined['schema']['properties']['room'];
    $this->assertSame(['library_reading', 'library_garden'], $this->offered($room));
    $this->assertTrue($room['x-surface']['refined']);
    // The riverside's room is orphaned by the new venue: discarded, shown
    // on the empty option, its stored value kept on the server. The
    // capacity was answered under that room, so its input goes with it:
    // the discard cascade's fixed point. Every path a JSON Pointer.
    $this->assertSame(['/room', '/capacity'], $refined['discarded']);
    $this->assertTrue($room['x-surface']['stale']);
    $this->assertNull($refined['values']['room']);
    $this->assertSame(['/room'], $refined['stale']);
    $this->assertSame(['/room' => 'stale'], array_column($refined['notes'], 'code', 'path'));
    $this->assertSame('library', $refined['values']['venue']);
    $this->assertSame('Spring meetup', $refined['values']['title']);
    $this->assertStringEndsWith('/surface-api/registration.step2', $refined['schema']['$id']);
    // The orphaned room is held unanswered, so the capacity is refined
    // against no room at all: its declared lower bound and no upper one,
    // and no room's words under it. It falls back to what is stored.
    $capacity = $refined['schema']['properties']['capacity'];
    $this->assertSame(1, $capacity['minimum']);
    $this->assertArrayNotHasKey('maximum', $capacity);
    $this->assertArrayNotHasKey('description', $capacity);
    $this->assertSame(50, $refined['values']['capacity']);

    // The stale path round-trips: sent back with the room still empty, it
    // stands for the stored room again, which the new venue orphans
    // again, so the answer is the same one.
    $again = $this->narrowed(self::STEP2, $refined['values'], $refined['stale']);
    $this->assertSame(['/room'], $again['stale']);
    $this->assertNull($again['values']['room']);
    $this->assertArrayNotHasKey('maximum', $again['schema']['properties']['capacity']);

    // Saved from there, the empty room stands for the stored one, which
    // the same submission's venue refuses, as the form refuses it
    // (FullSubmitTest::testSaveAfterTheVenueRebuildRefusesTheLeftOverRoom):
    // refused as the stored room, not as an unanswered one, and nothing
    // is written.
    $saved = $this->problem($this->send('PATCH', self::STEP2, $refined['values'], ['X-Surface-Stale' => '/room']), 422);
    $this->assertSame(['/room'], array_column($saved['errors'], 'path'));
    $this->assertStringNotContainsString('required', $saved['errors'][0]['message']);
    $this->container->get('config.factory')->reset();
    $this->assertSame('riverside', $this->config(self::CONFIG)->get('venue'));
    $this->assertSame('riverside_main', $this->config(self::CONFIG)->get('room'));

    // A room of the new venue stands, and narrows the capacity.
    $chosen = $this->narrowed(self::STEP2, ['room' => 'library_reading'] + $values);
    $this->assertSame([], $chosen['discarded']);
    $this->assertSame('library_reading', $chosen['values']['room']);
    $this->assertSame(60, $chosen['schema']['properties']['capacity']['maximum']);

    // Back to the riverside, the stale path sent back empty: the stored
    // room is chosen again.
    $back = $this->narrowed(self::STEP2, ['venue' => 'riverside', 'room' => NULL] + $values, ['/room']);
    $this->assertSame('riverside_main', $back['values']['room']);
    $this->assertSame([], $back['stale']);
    $this->assertSame(['riverside_main', 'riverside_east'], $this->offered($back['schema']['properties']['room']));

    // A partial document is laid over what is stored: the venue alone.
    $partial = $this->narrowed(self::STEP2, ['venue' => 'harbour']);
    $this->assertSame('Spring meetup', $partial['values']['title']);
    $this->assertSame(['/room'], $partial['stale']);
    // A query: nothing is written.
    $this->container->get('config.factory')->reset();
    $this->assertSame('riverside', $this->config(self::CONFIG)->get('venue'));
  }

  /**
   * Tests the narrowing follows the license example 4's alter mounted.
   *
   * The capacity's dependsOn names the license by its path, which is
   * what the app watches; refined without one the capacity stops at a
   * hundred, and with one it is the room's again.
   */
  public function testNarrowingFollowsTheMountedLicense(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $license = 'third_party_settings.data_surface_examples_compliance.license';
    $path = 'surface-api/registration.step3';
    $values = ['venue' => 'riverside', 'room' => 'riverside_main', 'capacity' => 150];
    $refined = $this->narrowed($path, $values);
    $capacity = $refined['schema']['properties']['capacity'];
    $this->assertSame(['room', $license], $capacity['x-surface']['dependsOn']);
    $this->assertSame(100, $capacity['maximum']);

    $values['third_party_settings'] = ['data_surface_examples_compliance' => ['license' => '2048', 'stewards' => 3]];
    $refined = $this->narrowed($path, $values);
    $this->assertSame(400, $refined['schema']['properties']['capacity']['maximum']);
    $this->assertSame('2048', $refined['values']['third_party_settings']['data_surface_examples_compliance']['license']);

    // A license in the wrong format is no license to the refiner: the
    // ceiling of 100 again, the typed value handed back as it was, and
    // the pattern explained by its message.
    $values['third_party_settings']['data_surface_examples_compliance']['license'] = 'EV-2048';
    $refined = $this->narrowed($path, $values);
    $this->assertSame(100, $refined['schema']['properties']['capacity']['maximum']);
    $this->assertSame('Up to 100 without an event license.', $refined['schema']['properties']['capacity']['description']);
    $this->assertSame('EV-2048', $refined['values']['third_party_settings']['data_surface_examples_compliance']['license']);
    // The license is the compliance structure's, defined once.
    $site = $refined['schema']['properties']['third_party_settings']['properties']['data_surface_examples_compliance'];
    $schema = SchemaResolver::inline($site, $refined['schema'])['properties']['license'];
    $this->assertSame('^\\d{4}$', $schema['pattern']);
    $this->assertSame('A license number is four digits, such as 2048.', $schema['x-surface']['patternMessage']);
  }

  /**
   * Tests a dry run refuses what the form refuses, and writes nothing.
   */
  public function testDryRunRehearsesWithoutWriting(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $values = [
      'title' => 'Autumn meetup',
      'capacity' => 90,
      'open' => TRUE,
      'venue' => 'harbour',
      'room' => 'harbour_deck',
    ];
    $path = self::STEP2 . '?dryRun=true';

    // The garden room seats 30, so the capacity asked for fits it: only
    // the room is wrong. A refusal is problem details, its errors by
    // JSON Pointer.
    $wrong_room = $this->problem($this->send('PATCH', $path, ['room' => 'library_garden', 'capacity' => 20] + $values), 422);
    $this->assertStringEndsWith('served-contract.md#refused', $wrong_room['type']);
    $this->assertSame(['/room' => 'no_such_choice'], array_column($wrong_room['errors'], 'code', 'path'));
    $this->assertSame(['type', 'title', 'status', 'detail', 'errors', 'notes', 'stale'], array_keys($wrong_room));
    // What storage rehearsed is never served: it may hold what the caller
    // may not see.
    $this->assertArrayNotHasKey('prepared', $wrong_room);

    $too_many = $this->problem($this->send('PATCH', $path, ['capacity' => 151] + $values), 422);
    $this->assertSame(['/capacity'], array_column($too_many['errors'], 'path'));
    $this->assertStringContainsString('150', $too_many['errors'][0]['message']);

    // The stored room left on its stale empty option after the venue
    // moved stands for the stored room, which the moved venue refuses.
    $moved = $this->problem($this->send('PATCH', $path, ['room' => NULL, 'capacity' => 20] + $values, ['X-Surface-Stale' => '/room']), 422);
    $this->assertSame(['/room'], array_column($moved['errors'], 'path'));

    // Accepted: the answer a write would give, and says it wrote nothing.
    $valid = $this->decoded($this->send('PATCH', $path, $values));
    $this->assertTrue($valid['ok']);
    $this->assertFalse($valid['committed']);
    $this->assertTrue($valid['x-surface-dry-run']);
    $this->assertSame([], $valid['violations']);
    $this->assertSame('harbour_deck', $valid['values']['room']);
    $this->assertSame(['/title', '/venue', '/room', '/capacity'], $valid['changes']);
    $this->assertSame([...ResultDocument::KEYS, 'outputs', 'contract', 'created', 'x-surface-dry-run'], array_keys($valid));
    // The JSON media type is taken as well as a merge patch.
    $this->assertTrue($this->decoded($this->send('PATCH', $path, $values, ['Content-Type' => 'application/json']))['ok']);

    // Nothing was written by any of them.
    $this->container->get('config.factory')->reset();
    $config = $this->config(self::CONFIG);
    $this->assertSame('Spring meetup', $config->get('title'));
    $this->assertSame('riverside_main', $config->get('room'));
    $this->assertSame(50, $config->get('capacity'));
  }

}
