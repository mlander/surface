<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A site sets a key's default in surface.defaults, and every door sees it.
 *
 * The examples' registration (example 3) and venue (example 15) declare
 * their defaults; the site sets its own over them, each entry a resource
 * id, a key path and a value as a form posts it. The build writes the
 * site's as the key's default, so both documents, the tool listing of a
 * create and the form start from it, while the declaration a config
 * schema is written from keeps the module's. An entry that names no key,
 * or a value its key refuses, refuses the build, naming the key.
 *
 * @see docs/declaring-a-resource.md#a-sites-own-defaults
 * @see docs/decisions.md#a-site-sets-a-default-in-configuration
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class SiteDefaultsTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface', 'surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Sets the site's defaults.
   *
   * @param array<int, array{0: string, 1: string, 2: string}> $entries
   *   Each a resource id, a key path and a value.
   */
  protected function setDefaults(array $entries): void {
    $this->config('surface.defaults')->set('defaults', array_map(static fn (array $entry): array => [
      'resource' => $entry[0],
      'key' => $entry[1],
      'value' => $entry[2],
    ], $entries))->save();
  }

  /**
   * Builds an operation of a resource.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function built(string $resource, string $operation): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build($resource, $resources->operation($resource, $operation, []));
  }

  /**
   * The site's default is the built key's, in both documents and the form.
   */
  public function testSiteDefaultReachesEveryDoor(): void {
    $this->assertSame(50, $this->built('registration.step3', 'configure')->getDefault('capacity'), 'The module declares 50.');
    $this->setDefaults([['registration.step3', 'capacity', '40']]);

    $built = $this->built('registration.step3', 'configure');
    $this->assertSame(40, $built->getDefault('capacity'), 'Read through the coercion table: "40" is the integer 40.');
    $this->assertContains('config:surface.defaults', $built->getCacheTags());
    $emitter = $this->container->get('surface.contract_emitter');
    $this->assertSame(40, $emitter->emitFull($built, 'registration.step3', 'configure')->document['schema']['properties']['capacity']['default']);
    $this->assertSame(40, $emitter->emit($built, $built->getDefaultValues(), 'registration.step3', 'configure')->document['schema']['properties']['capacity']['default']);

    // The declaration a config schema and an install file are written
    // from keeps the module's.
    $resources = $this->container->get('surface.resources');
    $this->assertSame(50, $resources->declaration('registration.step3', $resources->operation('registration.step3', 'configure', []))->getDefault('capacity'));

    // A key with nothing stored starts from the site's on the form.
    $form = $this->formBuilder()->buildSurfaceForm($built, $built->getDefaultValues(), new FormState());
    $this->assertSame(40, $form['capacity']['#default_value']);

    // A build without the entry has no trace of it.
    $this->setDefaults([]);
    $this->assertSame(50, $this->built('registration.step3', 'configure')->getDefault('capacity'));
  }

  /**
   * A create's tool listing and its form start from the site's defaults.
   */
  public function testCreateListingStatesTheSitesDefaults(): void {
    $this->setDefaults([
      ['event.venue', 'weight', '5'],
      ['event.venue', 'status', 'false'],
    ]);
    $manager = $this->container->get('plugin.manager.tool');
    $manager->clearCachedDefinitions();
    $values = $manager->getDefinition('surface:event.venue:create')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions();
    $this->assertSame(5, $values['weight']->getDefaultValue());
    $this->assertFalse($values['status']->getDefaultValue());

    $built = $this->built('event.venue', 'create');
    $form = $this->formBuilder()->buildSurfaceForm($built, $built->getDefaultValues(), new FormState());
    $this->assertSame(5, $form['weight']['#default_value']);
    $this->assertFalse($form['status']['#default_value']);
  }

  /**
   * A key path reaches into a structure attached at a fixed key.
   */
  public function testNestedKeyPath(): void {
    $this->setDefaults([['registration.step3', 'contact.phone', '+1 555 0100']]);
    $built = $this->built('registration.step3', 'configure');
    $this->assertSame(['email' => NULL, 'phone' => '+1 555 0100'], $built->getDefault('contact'));
    // Stated where the full schema states the contact's phone.
    $full = json_encode($this->container->get('surface.contract_emitter')->emitFull($built, 'registration.step3', 'configure')->document['schema']);
    $this->assertStringContainsString('"default":"+1 555 0100"', (string) $full);
  }

  /**
   * A value the key does not accept refuses the build, naming the key.
   */
  public function testValueThatDoesNotFitIsRefused(): void {
    $this->setDefaults([['registration.step3', 'venue', 'nowhere']]);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('surface.defaults sets "venue" on registration.step3 to "nowhere", which the key does not accept: The value you selected is not a valid choice.');
    $this->built('registration.step3', 'configure');
  }

  /**
   * An integer out of its range refuses the build, in the rule's words.
   */
  public function testValueOutOfRangeIsRefused(): void {
    $this->setDefaults([['event.venue', 'weight', '5000']]);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('surface.defaults sets "weight" on event.venue to "5000", which the key does not accept: This value should be between -1000 and 1000.');
    $this->built('event.venue', 'create');
  }

  /**
   * A key that does not exist refuses the build, naming it.
   */
  public function testKeyThatDoesNotExistIsRefused(): void {
    $this->setDefaults([['registration.step3', 'contact.fax', '0']]);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('surface.defaults: registration.step3 has no key "contact.fax". A site default names a key the resource declares, a key inside a map it declares or a structure it attaches (contact.phone), or a key an alter mounts (third_party_settings.<module>.<key>).');
    $this->built('registration.step3', 'configure');
  }

  /**
   * Keys a site default does not reach are refused, each saying why.
   */
  public function testWhatDefaultDoesNotReachIsRefused(): void {
    foreach ([
      [
        ['registration.step3', 'ticket.price', '3'],
        'registration.step3',
        'configure',
        'surface.defaults: "ticket.price" on registration.step3 is inside "ticket", a key with variants, each starting from its own defaults, so a site default does not reach it.',
      ],
      [
        ['registration.step3', 'contact', '{}'],
        'registration.step3',
        'configure',
        'surface.defaults: "contact" on registration.step3 holds a structure: set each of its keys, as "contact.<key>".',
      ],
      [
        ['event.venue', 'slug', 'hall'],
        'event.venue',
        'create',
        'surface.defaults: "slug" on event.venue is computed from label, so it takes no default.',
      ],
      [
        ['event.venue', 'rooms.label', 'Main'],
        'event.venue',
        'create',
        'surface.defaults: "rooms.label" on event.venue is inside "rooms", a collection, each item starting from its structure\'s defaults, so a site default does not reach it.',
      ],
    ] as [$entry, $resource, $operation, $message]) {
      $this->setDefaults([$entry]);
      try {
        $this->built($resource, $operation);
        $this->fail(sprintf('%s on %s was not refused.', $entry[1], $resource));
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
  }

}
