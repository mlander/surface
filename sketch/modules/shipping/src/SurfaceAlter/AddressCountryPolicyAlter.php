<?php

declare(strict_types=1);

namespace Drupal\shipping\SurfaceAlter;

use Drupal\address\Surface\AddressFieldSettingsSurface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface_sketch\Surface\Attribute\AltersSurface;
use Drupal\surface_sketch\Surface\Attribute\RefinesInput;

/**
 * A shipping module's rule for ONE field type: it targets that subsurface.
 * It only tightens, so it implements no interface; the attribute is
 * what makes it an alter.
 */
#[AltersSurface(AddressFieldSettingsSurface::class)]
final class AddressCountryPolicyAlter {

  /**
   * Found in src/SurfaceAlter and autowired, the way a hook class is.
   */
  public function __construct(private readonly ConfigFactoryInterface $config) {}

  /**
   * Only countries the site ships to may be offered. Reads no sibling,
   * so it runs once.
   */
  #[RefinesInput('available_countries')]
  public function onlyShippable(DataDefinitionInterface $available_countries): DataDefinitionInterface {
    if ($available_countries instanceof ListDataDefinitionInterface) {
      $available_countries->getItemDefinition()->addConstraint('Choice', [
        'choices' => $this->config->get('shipping.settings')->get('countries'),
      ]);
    }
    return $available_countries;
  }

}
