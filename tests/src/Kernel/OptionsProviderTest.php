<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\OptionsProviderInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface_examples\Resource\RegistrationStep2Resource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests core's options question answered from a key's resolved lists.
 *
 * Example 2's room is the subject: its declared list is every room of
 * every venue, read from site state, and refining by a venue narrows it
 * to that venue's rooms. Possible is the declared list, settable the
 * refined one.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class OptionsProviderTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds example 2 in its configure operation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built resource.
   */
  protected function surface(): DataSurfaceInterface {
    $resources = $this->container->get('data_surface.resources');
    return $resources->build(RegistrationStep2Resource::class, $resources->operation(RegistrationStep2Resource::class, 'configure'));
  }

  /**
   * Tests possible is the declared list and settable the refined one.
   */
  public function testPossibleIsDeclaredAndSettableIsRefined(): void {
    $surface = $this->surface();
    $declared = $surface->getDefinition('room');
    $refined = $surface->refine(['venue' => 'harbour'])->getDefinition('room');
    $provider = $this->options()->provider($declared, $refined);
    $this->assertInstanceOf(OptionsProviderInterface::class, $provider);

    // Every room of every venue, with its label.
    $all = $this->container->get('data_surface_examples.venue_choices')->rooms();
    $this->assertSame('Garden room', $all['library_garden']);
    $this->assertArrayHasKey('riverside_main', $all);
    $this->assertSame($all, $provider->getPossibleOptions());
    $this->assertSame(array_keys($all), $provider->getPossibleValues());

    // The harbour's rooms alone, with the same labels.
    $this->assertSame(['harbour_auditorium', 'harbour_deck'], $provider->getSettableValues());
    $this->assertSame(array_intersect_key($all, array_flip(['harbour_auditorium', 'harbour_deck'])), $provider->getSettableOptions());
    $account = $this->container->get('current_user');
    $this->assertSame($provider->getSettableOptions(), $provider->getSettableOptions($account));

    // The list is read from the venues, so it carries their list tag.
    $this->assertContains('config:event_venue_list', $provider->cacheability()->getCacheTags());
  }

  /**
   * Tests a provider with no refined definition settles on the declared.
   */
  public function testWithoutRefinedSettableIsDeclared(): void {
    $declared = $this->surface()->getDefinition('room');
    $provider = $this->options()->provider($declared);
    $this->assertSame($provider->getPossibleValues(), $provider->getSettableValues());
    $this->assertSame($provider->getPossibleOptions(), $provider->getSettableOptions());
    $this->assertNotSame([], $provider->getSettableValues());
  }

  /**
   * Tests a key with no list answers nothing, and is cacheable for ever.
   */
  public function testKeyWithoutListAnswersEmpty(): void {
    $surface = $this->surface();
    $provider = $this->options()->provider($surface->getDefinition('title'), $surface->refine(['venue' => 'harbour'])->getDefinition('title'));
    $this->assertSame([], $provider->getPossibleValues());
    $this->assertSame([], $provider->getPossibleOptions());
    $this->assertSame([], $provider->getSettableValues());
    $this->assertSame([], $provider->getSettableOptions());
    $this->assertSame([], $provider->cacheability()->getCacheTags());
  }

}
