<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormState;
use Drupal\data_surface_examples\Form\ResetExamplesForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the examples' reset puts every example back to the shipped files.
 *
 * For a retake: whatever was saved, and whatever another module stored
 * on an example — the compliance module's license under example 3's third
 * party settings — goes, and the files in config/install are what is
 * left.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesResetTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_examples_keys',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['data_surface_examples']);
  }

  /**
   * Reads what the module ships for one example.
   *
   * @param string $name
   *   The config object.
   *
   * @return array
   *   The shipped values.
   */
  protected function shipped(string $name): array {
    $path = $this->container->get('extension.list.module')->getPath('data_surface_examples');
    return Yaml::decode((string) file_get_contents($path . '/config/install/' . $name . '.yml'));
  }

  /**
   * Reads what one example holds now, without the installer's keys.
   *
   * @param string $name
   *   The config object.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(string $name): array {
    $this->container->get('config.factory')->reset();
    return array_diff_key($this->config($name)->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Tests a changed example 2, a mounted key and a closed room are reset.
   */
  public function testTheResetPutsBackWhatTheModuleShips(): void {
    $two = 'data_surface_examples.registration_step2';
    $three = 'data_surface_examples.registration_step3';
    $hash = $this->config($two)->get('_core');
    $this->assertNotNull($hash);
    $this->config($two)->set('venue', 'harbour')->set('room', 'harbour_deck')->set('capacity', 120)->save();
    $this->config($three)
      ->set('title', 'Changed')
      ->set('third_party_settings', ['data_surface_examples_compliance' => ['license' => '2048', 'stewards' => 3]])
      ->save();
    $this->assertSame('harbour_deck', $this->stored($two)['room']);
    // Example 4's closed rooms live in State, not in any example's
    // settings, and are opened again too.
    $this->container->get('state')->set('data_surface_examples_compliance.closed_rooms', ['riverside_east']);
    // So does example 10's workflow state, which is cleared.
    $this->container->get('state')->set('data_surface_examples_workflow.state', 'published');
    // Examples 7 to 11 too, and the ticket types example 9 reads.
    $this->config('data_surface_examples.bulletin')->set('frequency', 'monthly')->save();
    $this->config('data_surface_examples.ticket_types')->set('types.vip', ['label' => 'VIP', 'locked' => FALSE])->save();
    // Example 14's managed key, its pasted key kept beside it, and the
    // managed key another module stored on the webhook.
    $this->config('data_surface_examples.mailer')
      ->set('source', 'managed')
      ->set('credential', ['key_id' => 'mail_service', 'api_key' => 'mk-1'])
      ->save();
    $this->config('data_surface_examples.webhook')->set('third_party_settings', ['data_surface_examples_keys' => ['key_id' => 'webhook_signing']])->save();
    // Example 15's venues: one changed, one removed, one added.
    $venues = $this->container->get('entity_type.manager')->getStorage('event_venue');
    $riverside = $venues->load('riverside');
    $uuid = $riverside->uuid();
    $riverside->set('capacity', 9)->set('weight', 7)->save();
    $venues->load('library')->delete();
    $venues->create(['id' => 'town_hall', 'label' => 'Town Hall', 'capacity' => 300])->save();

    $form_state = new FormState();
    $this->container->get('form_builder')->submitForm(ResetExamplesForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $names = [
      'data_surface_examples.registration_step1',
      $two,
      $three,
      'data_surface_examples.bulletin',
      'data_surface_examples.ticket_types',
      'data_surface_examples.mailer',
      'data_surface_examples.webhook',
    ];
    foreach ($names as $name) {
      $this->assertSame($this->shipped($name), $this->stored($name), $name);
    }
    $this->assertArrayNotHasKey('third_party_settings', $this->stored($three));
    $this->assertNull($this->container->get('state')->get('data_surface_examples_compliance.closed_rooms'));
    $this->assertNull($this->container->get('state')->get('data_surface_examples_workflow.state'));
    // The venues shipped, as shipped, and no other; a venue kept keeps its
    // uuid.
    $venues->resetCache();
    $kept = array_keys($venues->loadMultiple());
    sort($kept);
    $this->assertSame(['harbour', 'library', 'riverside'], $kept);
    foreach (['riverside', 'library', 'harbour'] as $id) {
      $name = 'data_surface_examples.event_venue.' . $id;
      $this->assertSame($this->shipped($name), array_diff_key($this->stored($name), ['uuid' => TRUE]), $name);
    }
    $this->assertSame($uuid, $venues->load('riverside')->uuid());
    // Riverside Hall's main hall seats 400, more than example 4 allows
    // without a license, so its ceiling is what the shipped room shows.
    foreach ([$two, $three] as $name) {
      $stored = $this->stored($name);
      $this->assertSame(['riverside', 'riverside_main', 50], [$stored['venue'], $stored['room'], $stored['capacity']], $name);
    }
    // Where the object came from is the installer's to say, and a reset
    // does not change it.
    $this->assertSame($hash, $this->config($two)->get('_core'));
    $this->assertSame(
      ['The examples are back to the settings the module ships with.'],
      array_map('strval', $this->container->get('messenger')->messagesByType('status')),
    );
  }

}
