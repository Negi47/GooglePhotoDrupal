<?php

namespace Drupal\google_photos_importer\Form;

/**
 * Provides an interface for Google importer forms.
 */
interface ImporterFormInterface {

  const MEDIA_BUNDLE_NAME = 'google_photo';
  const IMAGE_FALLBACK_FIELD = 'field_image';
  const DATE_FORMAT_NAME = 'short';
  const DATE_FIELD_FORMAT_NAME = 'html_date';
  const BG_MESSAGE = 'Your item(s) importing in background. You will be notified by email once the import will be finished.';

}
