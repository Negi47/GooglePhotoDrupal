<?php

namespace Drupal\google_photos_importer\Form;

use Drupal\google_api_client\Form\GoogleApiClientRevokeForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityConfirmFormBase;

/**
 * Provides a form for revoking a google_api_client entity.
 *
 * @ingroup google_photos_importer
 */
class GoogleApiClientRevokeNonAdminForm extends GoogleApiClientRevokeForm {

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $google_api_client = $this->entity;
    $service = \Drupal::service('google_api_client.client');
    $service->setGoogleApiClient($google_api_client);
    $service->googleClient->revokeToken();
    $google_api_client->setAccessToken('');
    $google_api_client->setAuthenticated(FALSE);
    $google_api_client->save();
    ContentEntityConfirmFormBase::submitForm($form, $form_state);
    $this->messenger()->addMessage($this->t('GoogleApiClient account revoked successfully'));
    $this->redirect('user.page')->send();
  }

}
