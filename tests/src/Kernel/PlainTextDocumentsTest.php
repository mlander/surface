<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMap;
use Drupal\surface_test\ModeSubsetRefiner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the machine documents carry labels and descriptions as plain text.
 *
 * The adopters' gate (docs/adopters-2026-10-19.md) found escaped
 * placeholders ("Double quotation marks (&quot;)") and raw tags
 * ("<br /><strong>") in the tool listing, both schemas and the React
 * page, and a description escaped by the "Depends on" sentence appended
 * to it ("you&#039;d"). The form renders the markup as before.
 *
 * @see docs/decisions.md#a-machine-document-carries-plain-text
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class PlainTextDocumentsTest extends SurfaceKernelTestBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_test',
  ];

  /**
   * Builds a data surface whose words hold markup.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface.
   */
  protected function surface(): SurfaceInterface {
    return new Surface(DefinitionMap::fromArrays(
      definitions: [
        'scope' => DataDefinition::create('string')
          ->setLabel($this->t('Double quotation marks (@value)', ['@value' => '"'])),
        'protect' => DataDefinition::create('boolean')
          ->setLabel($this->t('Protect all forms'))
          ->setDescription($this->t('Enable it for every form.<br /><strong>Page caching is disabled.</strong>')),
        'mode' => DataDefinition::create('integer')
          ->setLabel($this->t('Mode'))
          ->setDescription($this->t("Check the one you'd like."))
          ->addConstraint('LabeledChoice', [
            'choices' => [0, 1, 2],
            'labels' => [
              0 => $this->t('Off & on (@sign)', ['@sign' => '<']),
              1 => $this->t('Optional'),
              2 => $this->t('Required'),
            ],
          ]),
      ],
      refiners: ['mode' => [SurfaceInterface::OWNER => [new ModeSubsetRefiner()]]],
      refinements: ['mode' => ['scope']],
    ));
  }

  /**
   * Tests both schemas carry the text, and the form the markup.
   */
  public function testBothSchemasCarryPlainText(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $surface = $this->surface();
    $documents = [
      'the schema for these answers' => $emitter->emit($surface, $surface->getDefaultValues(), 'audit')->document['schema'],
      'the full schema' => $emitter->emitFull($surface, 'audit')->document['schema'],
    ];
    foreach ($documents as $which => $schema) {
      $this->assertSame('Double quotation marks (")', $schema['properties']['scope']['title'], $which);
      $this->assertSame("Enable it for every form.\nPage caching is disabled.", $schema['properties']['protect']['description'], $which);
      $this->assertSame("Check the one you'd like.", $schema['properties']['mode']['description'], $which);
      $this->assertSame('Off & on (<)', $schema['properties']['mode']['oneOf'][0]['title'], $which);
    }

    $form = $this->formBuilder()->buildSurfaceForm($surface, $surface->getDefaultValues(), new FormState());
    $this->assertSame('Double quotation marks (&quot;)', (string) $form['scope']['#title'], 'The form renders the markup, as before.');
    $this->assertStringContainsString('<br /><strong>', (string) $form['protect']['#description']);
  }

  /**
   * Tests the tool listing carries the text, the appended sentence too.
   */
  public function testTheToolListingCarriesPlainText(): void {
    $inputs = $this->container->get('surface.input_definitions');
    $map = $inputs->withDependencies($inputs->fromSurface($this->surface(), 'Audit', ''), $this->surface());
    $this->assertSame('Double quotation marks (")', (string) $map->getPropertyDefinition('scope')->getLabel());
    $this->assertSame("Enable it for every form.\nPage caching is disabled.", (string) $map->getPropertyDefinition('protect')->getDescription());
    $this->assertSame("Check the one you'd like. Depends on scope.", (string) $map->getPropertyDefinition('mode')->getDescription(), 'The sentence appended escapes nothing.');
  }

}
