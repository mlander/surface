<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the React page and the three contract endpoints over HTTP.
 *
 * The examples' landing page links the React page of examples 1 to 3.
 * Example 2 is the subject throughout, because it is where an answer
 * narrows another: the venue the rooms, the room the capacity. The page
 * and the contract answer an administrator and refuse anonymous; refine
 * narrows the room by the venue and shows an orphaned room stale; and
 * validate refuses a room of another venue and a capacity above the
 * room's, accepts a valid payload, and writes nothing either way. On
 * example 3, with example 4's module on, refine follows the licence the
 * module's alter mounted.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ServedContractEndpointsTest extends BrowserTestBase {

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
   * Sends a POST to an endpoint as the logged-in account.
   *
   * @param string $path
   *   The path.
   * @param array $body
   *   The body, sent as JSON.
   * @param bool $token
   *   Whether to send the session's CSRF token in the header.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function post(string $path, array $body, bool $token = TRUE): ResponseInterface {
    $headers = ['Content-Type' => 'application/json'];
    if ($token) {
      $this->drupalGet('session/token');
      $headers['X-CSRF-Token'] = $this->getSession()->getPage()->getContent();
    }
    return $this->getHttpClient()->request('POST', $this->buildUrl($path), [
      'headers' => $headers,
      'cookies' => $this->getSessionCookies(),
      'body' => (string) json_encode($body),
      'http_errors' => FALSE,
    ]);
  }

  /**
   * Decodes a JSON response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   *
   * @return array
   *   The decoded body.
   */
  protected function json(ResponseInterface $response): array {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    return json_decode((string) $response->getBody(), TRUE);
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
   * Tests who may read the page and the contract, and its shape.
   */
  public function testAccessAndShape(): void {
    $this->drupalGet('surface-react/registration.step2/configure');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('surface-api/registration.step2/configure');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame(403, $this->post('surface-api/registration.step2/configure/refine', ['values' => []], FALSE)->getStatusCode());

    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    // The examples' landing page links each form's React twin.
    $this->drupalGet('surface-examples');
    foreach ([1, 2, 3] as $number) {
      $this->assertSession()->linkByHrefExists('/surface-react/registration.step' . $number . '/configure');
    }
    $this->drupalGet('surface-react/registration.step2/configure');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->titleEquals('React: Configure registration | Drupal');
    $this->assertSession()->elementExists('css', '#data-surface-react');
    $settings = $this->getDrupalSettings()['dataSurfaceReact'];
    $this->assertSame('registration.step2', $settings['surface']);
    $this->assertSame('configure', $settings['situation']);
    $this->assertStringEndsWith('/surface-api', $settings['apiBase']);
    $this->assertStringEndsWith('/session/token', $settings['tokenUrl']);

    $this->drupalGet('surface-api/registration.step2/configure');
    $this->assertSession()->statusCodeEquals(200);
    $contract = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame(['surface', 'situation', 'label', 'schema', 'values', 'stale', 'fingerprint'], array_keys($contract));
    $this->assertIsString($contract['fingerprint']);
    $this->assertSame('registration.step2', $contract['surface']);
    $this->assertSame('https://json-schema.org/draft/2020-12/schema', $contract['schema']['$schema']);
    $this->assertSame(['venue'], $contract['schema']['properties']['room']['x-surface']['dependsOn']);
    $this->assertSame('riverside', $contract['values']['venue']);

    // A surface or situation that names nothing is refused, and a POST
    // without the session's token is refused before it is read.
    $this->drupalGet('surface-api/registration.step9/configure');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('surface-api/registration.step2/edit');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame(403, $this->post('surface-api/registration.step2/configure/refine', ['values' => []], FALSE)->getStatusCode());
    $this->assertSame(403, $this->post('surface-api/registration.step2/configure/validate', ['values' => []], FALSE)->getStatusCode());
  }

  /**
   * Tests refine narrows the room by the venue, and keeps a stale room.
   *
   * The orphaned room is the form's own: shown stale, held unanswered so
   * the capacity is no longer capped by it, round-tripped by its path,
   * and refused by a save from there.
   */
  public function testRefineNarrowsTheRoomByTheVenue(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $values = [
      'title' => 'Spring meetup',
      'capacity' => 50,
      'open' => TRUE,
      'venue' => 'library',
      'room' => 'riverside_main',
    ];
    $refined = $this->json($this->post('surface-api/registration.step2/configure/refine', ['values' => $values]));
    $room = $refined['schema']['properties']['room'];
    $this->assertSame(['library_reading', 'library_garden'], $this->offered($room));
    $this->assertTrue($room['x-surface']['refined']);
    // The riverside's room is orphaned by the new venue: discarded, shown
    // on the empty option, its stored value kept on the server. The
    // capacity was answered under that room, so its input goes with it:
    // the discard cascade's fixed point.
    $this->assertSame(['room', 'capacity'], $refined['discarded']);
    $this->assertTrue($room['x-surface']['stale']);
    $this->assertNull($refined['values']['room']);
    $this->assertSame(['room'], $refined['stale']);
    $this->assertSame('library', $refined['values']['venue']);
    $this->assertSame('Spring meetup', $refined['values']['title']);
    // The orphaned room is held unanswered, so the capacity is refined
    // against no room at all: its declared limit, and no room's words
    // under it. It falls back to what is stored.
    $capacity = $refined['schema']['properties']['capacity'];
    $this->assertSame(1000, $capacity['maximum']);
    $this->assertArrayNotHasKey('description', $capacity);
    $this->assertSame(50, $refined['values']['capacity']);

    // The stale path round-trips: sent back with the room still empty, it
    // stands for the stored room again, which the new venue orphans
    // again, so the answer is the same one.
    $again = $this->json($this->post('surface-api/registration.step2/configure/refine', [
      'values' => $refined['values'],
      'stale' => $refined['stale'],
    ]));
    $this->assertSame(['room'], $again['stale']);
    $this->assertNull($again['values']['room']);
    $this->assertSame(1000, $again['schema']['properties']['capacity']['maximum']);

    // Saved from there, the empty room stands for the stored one, which
    // the same submission's venue refuses, as the form refuses it
    // (FullSubmitTest::testSaveAfterTheVenueRebuildRefusesTheLeftOverRoom):
    // refused as the stored room, not as an unanswered one, and nothing
    // is written.
    $saved = $this->json($this->post('surface-api/registration.step2/configure/submit', [
      'values' => $refined['values'],
      'stale' => $refined['stale'],
    ]));
    $this->assertFalse($saved['committed']);
    $this->assertSame(['room'], array_column($saved['violations'], 'path'));
    $this->assertStringNotContainsString('required', $saved['violations'][0]['message']);
    $this->container->get('config.factory')->reset();
    $this->assertSame('riverside', $this->config(self::CONFIG)->get('venue'));
    $this->assertSame('riverside_main', $this->config(self::CONFIG)->get('room'));

    // A room of the new venue stands, and narrows the capacity.
    $chosen = $this->json($this->post('surface-api/registration.step2/configure/refine', [
      'values' => ['room' => 'library_reading'] + $values,
    ]));
    $this->assertSame([], $chosen['discarded']);
    $this->assertSame('library_reading', $chosen['values']['room']);
    $this->assertSame(60, $chosen['schema']['properties']['capacity']['maximum']);

    // Back to the riverside, the stale path sent back empty: the stored
    // room is chosen again.
    $back = $this->json($this->post('surface-api/registration.step2/configure/refine', [
      'values' => ['venue' => 'riverside', 'room' => NULL] + $values,
      'stale' => ['room'],
    ]));
    $this->assertSame('riverside_main', $back['values']['room']);
    $this->assertSame([], $back['stale']);
    $this->assertSame(['riverside_main', 'riverside_east'], $this->offered($back['schema']['properties']['room']));
  }

  /**
   * Tests refine follows the licence example 4's alter mounted on step 3.
   *
   * The capacity's dependsOn names the licence by its path, which is
   * what the app watches; refined without one the capacity stops at a
   * hundred, and with one it is the room's again.
   */
  public function testRefineFollowsTheMountedLicence(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $licence = 'third_party_settings.data_surface_examples_compliance.licence';
    $values = ['venue' => 'riverside', 'room' => 'riverside_main', 'capacity' => 150];
    $refined = $this->json($this->post('surface-api/registration.step3/configure/refine', ['values' => $values]));
    $capacity = $refined['schema']['properties']['capacity'];
    $this->assertSame(['room', $licence], $capacity['x-surface']['dependsOn']);
    $this->assertSame(100, $capacity['maximum']);

    $values['third_party_settings'] = ['data_surface_examples_compliance' => ['licence' => '2048', 'stewards' => 3]];
    $refined = $this->json($this->post('surface-api/registration.step3/configure/refine', ['values' => $values]));
    $this->assertSame(400, $refined['schema']['properties']['capacity']['maximum']);
    $this->assertSame('2048', $refined['values']['third_party_settings']['data_surface_examples_compliance']['licence']);

    // A licence in the wrong format is no licence to the refiner: the
    // ceiling of 100 again, the typed value handed back as it was, and
    // the pattern explained by its message.
    $values['third_party_settings']['data_surface_examples_compliance']['licence'] = 'EV-2048';
    $refined = $this->json($this->post('surface-api/registration.step3/configure/refine', ['values' => $values]));
    $this->assertSame(100, $refined['schema']['properties']['capacity']['maximum']);
    $this->assertSame('Up to 100 without an event licence. With one, up to 400.', $refined['schema']['properties']['capacity']['description']);
    $this->assertSame('EV-2048', $refined['values']['third_party_settings']['data_surface_examples_compliance']['licence']);
    $schema = $refined['schema']['properties']['third_party_settings']['properties']['data_surface_examples_compliance']['properties']['licence'];
    $this->assertSame('^\\d{4}$', $schema['pattern']);
    $this->assertSame('A licence number is four digits, such as 2048.', $schema['x-surface']['patternMessage']);
  }

  /**
   * Tests validate refuses what the form refuses, and writes nothing.
   */
  public function testValidateRehearsesWithoutWriting(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $values = [
      'title' => 'Autumn meetup',
      'capacity' => 90,
      'open' => TRUE,
      'venue' => 'harbour',
      'room' => 'harbour_deck',
    ];
    $path = 'surface-api/registration.step2/configure/validate';

    // The garden room seats 30, so the capacity asked for fits it: only
    // the room is wrong.
    $wrong_room = $this->json($this->post($path, ['values' => ['room' => 'library_garden', 'capacity' => 20] + $values]));
    $this->assertFalse($wrong_room['valid']);
    $this->assertSame(['room'], array_column($wrong_room['violations'], 'path'));
    $this->assertNull($wrong_room['prepared']);

    $too_many = $this->json($this->post($path, ['values' => ['capacity' => 151] + $values]));
    $this->assertFalse($too_many['valid']);
    $this->assertSame(['capacity'], array_column($too_many['violations'], 'path'));
    $this->assertStringContainsString('150', $too_many['violations'][0]['message']);

    // The stored room left on its stale empty option after the venue
    // moved stands for the stored room, which the moved venue refuses.
    $moved = $this->json($this->post($path, [
      'values' => ['room' => NULL, 'capacity' => 20] + $values,
      'stale' => ['room'],
    ]));
    $this->assertFalse($moved['valid']);
    $this->assertSame(['room'], array_column($moved['violations'], 'path'));

    $valid = $this->json($this->post($path, ['values' => $values]));
    $this->assertTrue($valid['valid']);
    $this->assertSame([], $valid['violations']);
    $this->assertSame('harbour_deck', $valid['values']['room']);
    $this->assertSame('harbour_deck', $valid['prepared']['room']);

    // Nothing was written by any of them.
    $this->container->get('config.factory')->reset();
    $config = $this->config(self::CONFIG);
    $this->assertSame('Spring meetup', $config->get('title'));
    $this->assertSame('riverside_main', $config->get('room'));
    $this->assertSame(50, $config->get('capacity'));
  }

}
