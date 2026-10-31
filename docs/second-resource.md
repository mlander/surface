# Your second resource

A rule between keys makes what one key allows depend on another key's
value. When that answer has to be computed, you write it as a refiner:
a static method that takes the key's definition and the values of the
keys it watches, and returns a narrower definition.
[Rules between keys](introduction/07-rules-between-keys.md) introduces
both, and the rules you can declare without a method.

[Your first resource](first-resource.md) had keys that stand alone. Most
settings pages have at least one that does not: a region is only a
region of the chosen country. This page adds exactly that, one key whose
allowed values depend on another key's value, written as one refiner,
and stops there.

It is the store location in the same module, `surface_examples_first`. The
storage, the permission and the route are the first page's, with new
names, and the schema and install file are written from the resource
by the same command; only the refiner is new.

## The settings

<!-- yaml: modules/surface_examples_first/config/schema/surface_examples_first.schema.yml#surface_examples_first.store -->

```yaml
surface_examples_first.store:
  type: config_object
  label: 'Store location'
  constraints:
    FullyValidatable: ~
  mapping:
    country:
      type: string
      label: 'Country'
    region:
      type: string
      label: 'Region'
```

<!-- code: modules/surface_examples_first/config/install/surface_examples_first.store.yml -->

```yaml
country: CA
region: CA-ON
```

<!-- code: modules/surface_examples_first/src/Storage/StoreStorage.php -->

```php
<?php

declare(strict_types=1);

namespace Drupal\surface_examples_first\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Keeps the store location in its config object.
 */
final class StoreStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples_first.store';

}
```

<!-- yaml: modules/surface_examples_first/surface_examples_first.routing.yml#surface_examples_first.store -->

```yaml
surface_examples_first.store:
  path: '/admin/config/services/surface-store'
  defaults:
    _form: 'Drupal\surface\Form\SurfaceOperationForm'
    _title: 'Store location'
    _surface_resource: 'Drupal\surface_examples_first\Resource\StoreResource'
    _surface_operation: 'configure'
```

## The resource, and its one rule

<!-- code: modules/surface_examples_first/src/Resource/StoreResource.php -->

```php
<?php

declare(strict_types=1);

namespace Drupal\surface_examples_first\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_first\Storage\StoreStorage;

/**
 * The store location: a region is one of the chosen country's.
 */
#[Resource('first.store',
  label: new TranslatableMarkup('Store location'),
  storage: StoreStorage::class,
)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the store location'),
  description: new TranslatableMarkup('Sets the store location: its country, and a region of that country.'),
  permission: 'administer surface_examples_first',
)]
final class StoreResource implements ResourceInterface {

  public const COUNTRY = 'country';
  public const REGION = 'region';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add(self::COUNTRY, 'string', t('Country'), default: 'CA')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'CA' => t('Canada'),
          'US' => t('United States'),
        ],
      ]);

    $inputs->add(self::REGION, 'string', t('Region'), default: 'CA-ON')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => self::regions('CA') + self::regions('US'),
      ]);
  }

  /**
   * The region must be one of the chosen country's.
   *
   * The parameter $country watches self::COUNTRY by its name, the one place
   * the key is spelled as a string: rename the key, rename the parameter.
   */
  #[RefinesInput(self::REGION)]
  public static function regionOfCountry(DataDefinitionInterface $region, string $country): DataDefinitionInterface {
    return $region->addConstraint('LabeledChoice', [
      'choices' => self::regions($country),
    ]);
  }

  /**
   * Lists a country's regions.
   *
   * @param string $country
   *   The country code.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Region code => name.
   */
  public static function regions(string $country): array {
    return match ($country) {
      'CA' => [
        'CA-BC' => t('British Columbia'),
        'CA-ON' => t('Ontario'),
        'CA-QC' => t('Quebec'),
      ],
      'US' => [
        'US-CA' => t('California'),
        'US-NY' => t('New York'),
        'US-TX' => t('Texas'),
      ],
      default => [],
    };
  }

}
```

`defineInputs()` still says everything a key may ever hold: `region` is
any of the six regions. It never reads another key. The dependency is
the method below it:

- **`#[RefinesInput(self::REGION)]`** marks a refiner of `region`, a
  static method like everything else on the class. The key is named by
  its constant, as on the first page.
- **Its first parameter is the key's definition**; each one after it is
  a key it watches, matched by name. `string $country` watches
  `country`, so the method runs once `country` has a value, and is handed
  that value. The signature is the whole dependency declaration. The
  parameter is the one place the key is named without its constant, so
  its name must match the value of `self::COUNTRY`, `'country'`, and a
  rename changes both; the method's docblock says so.
- **It may only narrow.** The answer replaces the six regions with the
  chosen country's three, which is fewer; an answer that added a region
  not declared, or dropped the constraint, would be refused the moment it
  was given. That is what lets every caller trust what it read: whatever
  the answers, the region is one of the six.
- **One method, one rule**, its docblock saying the rule.

## What each door does with it

- **The form** rebuilds the region select over AJAX when the country
  changes, offering the three regions of the country chosen.
- **The tool** refuses a region of the other country. Both keys are
  required, so both are sent on every call (`ddev drush` on a site run
  with DDEV):

  ```bash
  drush tool:run surface:first.store:configure --uid=1 --input='{"values":{"country":"US","region":"CA-ON"}}' --input=dry_run=true
  drush tool:run surface:first.store:configure --uid=1 --input='{"values":{"country":"US","region":"US-NY"}}' --input=dry_run=true
  ```

  ```
  Failed: The values were refused:
  /region: The value you selected is not a valid choice. This applies when Country is US (United States). Choose US-CA (California), US-NY (New York) or US-TX (Texas).
  ```

  ```
  Success: Dry run of Configure the store location: the values were accepted and prepared, and nothing was written.
  ```

- **The schema** states the rule as one `if`/`then` per country: if
  `country` is `CA`, `region` is one of the three Canadian regions, and
  so on, so a client with no PHP knows it before it sends anything.

That is the second idea. [Concepts](concepts.md) lists the rest, each
with the question that would make you need it.
