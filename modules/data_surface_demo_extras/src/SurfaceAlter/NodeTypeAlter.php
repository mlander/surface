<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\SurfaceAlter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\HasStorageShapeInterface;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface\Target\SettingsShapeInterface;
use Drupal\data_surface_demo_extras\NodeTypeReviewSettings;
use Drupal\data_surface_demo_extras\Plugin\DataSurfaceWidget\CommaSeparatedListWidget;
use Drupal\data_surface_demo_extras\ReviewDeadlineShape;
use Drupal\data_surface_demo_node_type\Surface\NodeTypeSurface;

/**
 * The editorial review settings, on the content type surface.
 *
 * The surface half of a comparison: the same two settings are added to
 * core's own content type form by NodeTypeFormHooks, the way a module
 * without a surface would add them. Everything the form alter keeps in
 * form code is said here as contract, where every caller of the surface
 * reads it:
 *
 * - The deadline is asked for the way a person says it, an amount and a
 *   unit, and stored as the seconds the classic form stores. The
 *   conversion is this alter's storage shape, ReviewDeadlineShape, which
 *   the build hands the surface for this module's mount and the target
 *   adapter applies to it and nothing else: the content type's target
 *   reads and writes the seconds. The classic form does the same
 *   arithmetic, NodeTypeReviewSettings::seconds(), in its element
 *   validator, where no caller that skips the form reads it. The unit is
 *   part of the contract, so a caller names it rather than inferring it
 *   from the stored integer's bounds, and business days, which no
 *   reading of those bounds can answer, are one more choice. The range
 *   is checked on the amount and unit a caller sent, in the caller's
 *   units, by the constraint on the pair.
 * - The tags are a list of strings, which is the classic form's comma
 *   split said as a type: a caller sends a list and there is nothing to
 *   split. What the classic form then does to each tag — trims it, lower
 *   cases it, drops repeats — is said as what a stored tag looks like, a
 *   pattern on each item and uniqueness on the list, and a caller is
 *   refused rather than silently rewritten, because the pipeline never
 *   turns a value into a different one on a caller's behalf.
 *
 * Both keys are mounted at third_party_settings.data_surface_demo_extras,
 * which is where core keeps a node type's third party settings, so the
 * content type's target writes them there without knowing this module.
 * Nothing here is refined: neither key depends on another.
 *
 * Named by class, so this module reads the node type demo's surface
 * without depending on it: discovery skips an alter of a surface whose
 * module is not enabled.
 *
 * @see \Drupal\data_surface_demo_extras\Hook\NodeTypeFormHooks
 */
#[AltersSurface(NodeTypeSurface::class)]
final class NodeTypeAlter implements SurfaceAlterInterface, HasStorageShapeInterface {

  use StringTranslationTrait;

  /**
   * Constructs a NodeTypeAlter.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $unit = DataDefinition::create('string')
      ->setLabel($this->t('Unit'))
      ->addConstraint('LabeledChoice', [
        'choices' => NodeTypeReviewSettings::units(),
        'labels' => [
          'hours' => $this->t('Hours'),
          'days' => $this->t('Days'),
          'weeks' => $this->t('Weeks'),
          NodeTypeReviewSettings::BUSINESS_DAYS => $this->t('Business days'),
        ],
      ]);
    DefinitionMetadata::setDefaultValue($unit, NodeTypeReviewSettings::DEFAULT_UNIT);
    $inputs->addDefinition(NodeTypeReviewSettings::DEADLINE, MapDataDefinition::create()
      ->setLabel($this->t('Review deadline'))
      ->setDescription($this->t('How long an editor has to review new content of this type, from one hour to thirty days. Leave the amount empty for no deadline.'))
      ->setPropertyDefinition(NodeTypeReviewSettings::AMOUNT, DataDefinition::create('integer')
        ->setLabel($this->t('Amount'))
        ->addConstraint('Range', ['min' => 1]))
      ->setPropertyDefinition(NodeTypeReviewSettings::UNIT, $unit)
      ->addConstraint('DataSurfaceDemoExtrasReviewDeadline', []));

    $tag = DataDefinition::create('string')
      ->setLabel($this->t('Audience tag'))
      ->addConstraint('Regex', [
        'pattern' => NodeTypeReviewSettings::TAG_PATTERN,
        'message' => 'An audience tag is lower case letters and digits, words joined by one space or one hyphen, with nothing around it.',
      ]);
    $inputs->addDefinition(NodeTypeReviewSettings::TAGS, (new ListDataDefinition([], $tag))
      ->setLabel($this->t('Audience tags'))
      ->setDescription($this->t('Who content of this type is written for, each tag listed once and in lower case, for example "local news" or "sports".'))
      ->setSetting(CommaSeparatedListWidget::SETTING, TRUE)
      ->addConstraint('DataSurfaceDemoExtrasUniqueItems', []), []);
    $inputs->describe('third_party_settings.data_surface_demo_extras', label: $this->t('Editorial review'));
  }

  /**
   * {@inheritdoc}
   *
   * The deadline is stored as seconds, and read back in the largest
   * fixed unit that divides them.
   */
  public function storageShape(): SettingsShapeInterface {
    return new ReviewDeadlineShape();
  }

}
