<?php

declare(strict_types=1);

namespace Drupal\surface_examples_performance\Resource;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_performance\Storage\PerformanceStorage;

/**
 * The settings core's performance form edits, declared once.
 *
 * How long browsers and proxies may cache a page, and whether CSS and
 * JavaScript files are aggregated, with the titles, the descriptions and
 * the list of periods core's form gives them. Stored in core's
 * system.performance, each key at the path core keeps it at, and held to
 * core's config schema for that object as core wrote it: the schema says
 * only that the maximum age is an integer, and the resource offers a list
 * of integers, which it allows.
 */
#[Resource('system.performance',
  label: new TranslatableMarkup('Performance settings'),
  description: new TranslatableMarkup('How long browsers and proxies may cache a page, and whether CSS and JavaScript files are aggregated, as core keeps them in system.performance.'),
  storage: PerformanceStorage::class,
)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure performance'),
  description: new TranslatableMarkup('Sets how long browsers and proxies may cache a page and whether CSS and JavaScript files are aggregated.'),
  permission: 'administer site configuration',
)]
final class PerformanceResource implements ResourceInterface {

  public const MAX_AGE = 'max_age';
  public const CSS_PREPROCESS = 'css_preprocess';
  public const JS_PREPROCESS = 'js_preprocess';

  /**
   * The maximum ages core's performance form offers, in seconds.
   */
  public const PERIODS = [0, 60, 180, 300, 600, 900, 1800, 2700, 3600, 10800, 21600, 32400, 43200, 86400];

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    // Core's labels. "<no caching>" is escaped once, so the form shows it
    // and the documents, which read a label as markup, keep it.
    $formatter = \Drupal::service('date.formatter');
    $periods = [];
    foreach (self::PERIODS as $seconds) {
      $periods[$seconds] = $seconds === 0 ? Markup::create(Html::escape('<' . t('no caching') . '>')) : $formatter->formatInterval($seconds);
    }
    $bandwidth = ArrangementGroup::details('bandwidth_optimization', t('Bandwidth optimization'), description: t('External resources can be optimized automatically, which can reduce both the size and number of requests made to your website.'));

    $inputs->add(self::MAX_AGE, 'integer', t('Browser and proxy cache maximum age'), default: 0)
      ->setDescription(t('This is used as the value for max-age in Cache-Control headers.'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['labels' => $periods]);
    $inputs->describe(self::MAX_AGE, group: ArrangementGroup::details('caching', t('Caching')));

    $inputs->add(self::CSS_PREPROCESS, 'boolean', t('Aggregate CSS files'), default: TRUE)
      ->setRequired(TRUE);
    $inputs->describe(self::CSS_PREPROCESS, group: $bandwidth);

    $inputs->add(self::JS_PREPROCESS, 'boolean', t('Aggregate JavaScript files'), default: TRUE)
      ->setRequired(TRUE);
    $inputs->describe(self::JS_PREPROCESS, group: $bandwidth);
  }

}
