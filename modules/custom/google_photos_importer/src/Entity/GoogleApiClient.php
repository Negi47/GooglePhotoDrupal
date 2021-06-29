<?php

namespace Drupal\google_photos_importer\Entity;

use Drupal\google_api_client\Entity\GoogleApiClient as BaseGoogleApiClient;

/**
 * Defines the GoogleApiClient entity.
 *
 * @ContentEntityType(
 *   id = "google_api_client",
 *   label = @Translation("GoogleApiClient entity"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\google_api_client\Entity\Controller\GoogleApiClientListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "add" = "Drupal\google_api_client\Form\GoogleApiClientForm",
 *       "edit" = "Drupal\google_api_client\Form\GoogleApiClientForm",
 *       "delete" = "Drupal\google_api_client\Form\GoogleApiClientDeleteForm",
 *       "revoke" = "Drupal\google_api_client\Form\GoogleApiClientRevokeForm",
 *       "revoke_non_admin" = "Drupal\google_photos_importer\Form\GoogleApiClientRevokeNonAdminForm",
 *     },
 *   },
 *   base_table = "google_api_client",
 *   admin_permission = "use google api client",
 *   fieldable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "name",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "canonical" = "/google_api_client/{google_api_client}",
 *     "edit-form" = "/admin/config/services/google_api_client/{google_api_client}/edit",
 *     "delete-form" = "/admin/config/services/google_api_client/{google_api_client}/delete",
 *     "revoke-form" = "/admin/config/services/google_api_client/revoke",
 *     "collection" = "/google_api_client/list"
 *   },
 *   field_ui_base_route = "google_api_client.google_api_client_settings",
 * )
 */
class GoogleApiClient extends BaseGoogleApiClient {

}
