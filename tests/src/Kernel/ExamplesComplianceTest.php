<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\ViolationSummary;
use Drupal\data_surface_examples\Surface\RegistrationStep3Surface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests step 4: the compliance module's alter of step 3.
 *
 * With the module on, step 3 gains a privacy notice under the module's
 * name, its title is relabelled, and the notice is required once the
 * capacity is above a hundred: the alter's own #[RefinesInput] method on
 * the key it mounted, watching the owner's capacity. Step 3 does not
 * change and does not know.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesComplianceTest extends DataSurfaceKernelTestBase {

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
    'data_surface_examples_compliance',
  ];

  /**
   * The path the alter's key is mounted at.
   */
  protected const NOTICE = 'third_party_settings.data_surface_examples_compliance.privacy_notice';

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
   * Builds step 3 in its configure situation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function surface(): DataSurfaceInterface {
    $surfaces = $this->container->get('data_surface.surfaces');
    return $surfaces->build(RegistrationStep3Surface::class, $surfaces->situation(RegistrationStep3Surface::class, 'configure'));
  }

  /**
   * Reads the privacy notice's definition off a surface.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The notice's definition.
   */
  protected function notice(DataSurfaceInterface $surface): DataDefinitionInterface {
    $mount = $surface->getDefinition('third_party_settings');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $mount);
    $module = $mount->getPropertyDefinition('data_surface_examples_compliance');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $module);
    $notice = $module->getPropertyDefinition('privacy_notice');
    $this->assertNotNull($notice);
    return $notice;
  }

  /**
   * Tests the key appears, the label changes, and the notice tightens.
   */
  public function testTheAlterAppliesAndRequiresTheNoticeAboveOneHundred(): void {
    $surface = $this->surface();
    $this->assertSame('Public event title', (string) $surface->getDefinition('title')->getLabel());
    $this->assertNotNull($this->notice($surface));
    $this->assertFalse($this->notice($surface)->isRequired());
    $this->assertSame(['capacity'], $surface->getDefinitions()->dependencies('third_party_settings'));

    $this->assertFalse($this->notice($surface->refine(['capacity' => 100]))->isRequired());
    $this->assertTrue($this->notice($surface->refine(['capacity' => 101]))->isRequired());

    $paths = fn (array $values): array => array_map(
      static fn ($violation): string => $violation->fullPath(),
      iterator_to_array($this->pipeline()->validate($surface, $values + [
        'title' => 'Meetup',
        'venue' => 'riverside',
        'room' => 'riverside_main',
        'pricing' => 'free',
        'contact' => ['email' => 'events@example.com'],
      ])),
    );
    $this->assertSame([], $paths(['capacity' => 100]));
    $this->assertSame([self::NOTICE], $paths(['capacity' => 101]));
    $notice = fn (string $text): array => [
      'capacity' => 101,
      'third_party_settings' => ['data_surface_examples_compliance' => ['privacy_notice' => $text]],
    ];
    $this->assertSame([self::NOTICE], $paths($notice('')));
    $this->assertSame([], $paths($notice('We keep your email for a year.')));
  }

  /**
   * Tests the notice is stored under the module's name in step 3's config.
   */
  public function testTheNoticeIsStoredUnderTheModulesName(): void {
    $surfaces = $this->container->get('data_surface.surfaces');
    $context = $surfaces->situation(RegistrationStep3Surface::class, 'configure');
    $surface = $surfaces->build(RegistrationStep3Surface::class, $context);
    $result = $this->pipeline()->submit($surface, [
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'capacity' => 250,
      'third_party_settings' => ['data_surface_examples_compliance' => ['privacy_notice' => 'We keep your email for a year.']],
    ], $surfaces->target(RegistrationStep3Surface::class, $context, $surface));
    $this->assertTrue($result->isValid(), ViolationSummary::fromViolations($result->violations));
    $this->assertSame(
      'We keep your email for a year.',
      $this->config('data_surface_examples.registration_step3')->get(self::NOTICE),
    );
  }

}
