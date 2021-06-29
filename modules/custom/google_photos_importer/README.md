##About

This module provides the ability connect the user google photo library with
Drupal 8 Media.

## Usage

The most user visible functionality is covered by the
`google_photo_connection_link_builder` service.
So simply build the link to a Google Photo library, call this service like that:

```
$google_photo_connect_link =
\Drupal::service('google_photo_connection_link_builder')
->getLink('Import Google photos');
```

### Depend on the context, the next links will be returned:
1. If user didn't connect his account yet - the link to middleware controller
   will be returned. The controller will create a new google_api_client object
   and redirect to a google api authentication callback.
2. If has already connected his profile to google photos - then the link will
   point to a form with photos selection.
3. If user remove the authentication - the link will point to authenticate
   callback.

### Permissions
Module provide 2 permissions:
- "Use Google API client" - Grand this permission to a roles that need
  to have access to a google api client entity, for example to link
  google photo importer to an event node.
- "Revoke Google API client access" - this permission aims to control
  which role can have access to revoke access link.

### Local fallback image field
For the imported images from google photo API, we are using the local
copy of the image. The fallback field is defined in 2 places:
1. Hardcoded in
   `\Drupal\google_photos_importer\Form\GooglePhotosImporterForm::IMAGE_FALLBACK_FIELD`
2. On the Google Media display mode settings, when
`Formatter for google image field` chosen to display the google image url.
For `Formatter for google image field` press the gear  icon and choose
the  fallback image field.
Note: the image field must be added to bundle first.

#### `Formatter for google image field` configuration
You can choose the various styles for remote media image and local image.
More options for google photo could be added directly to the formatter.
Just follow this format: WWW_HHH.
See more here `\Drupal\google_photos_importer\Plugin\Field\FieldFormatter::L73`.
