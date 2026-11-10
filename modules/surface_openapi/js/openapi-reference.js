/**
 * @file
 * Redoc over the site's OpenAPI document: a reference to read, with no
 * call sent from it.
 */
((Drupal, drupalSettings, once) => {
  Drupal.behaviors.surfaceOpenApiReference = {
    attach(context) {
      once('surface-openapi-reference', '#surface-openapi-reference', context).forEach((element) => {
        window.Redoc.init(drupalSettings.surfaceOpenApi.document, { hideDownloadButton: false }, element);
      });
    },
  };
})(Drupal, drupalSettings, once);
