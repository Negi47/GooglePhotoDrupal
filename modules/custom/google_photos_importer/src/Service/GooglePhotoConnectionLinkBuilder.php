<?php

namespace Drupal\google_photos_importer\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\google_api_client\Entity\GoogleApiClient;
use Drupal\user\Entity\User;
use Drupal\Core\Entity\ContentEntityBase;

/**
 * Class Google Photo Connection Link Builder service.
 *
 * @package Drupal\google_photos_importer\Service
 */
class GooglePhotoConnectionLinkBuilder {

  use StringTranslationTrait;
  use RedirectDestinationTrait;

  /**
   * AccountProxyInterface definition.
   *
   * @var AccountProxyInterface
   */
  public $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * GooglePhotoConnectionLinkBuilder constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *  Current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(AccountProxyInterface $account, EntityTypeManagerInterface $entity_type_manager) {
    $this->currentUser = $account;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Returns the Link render array.
   *
   * @param string $link_title
   *   The link label.
   * @param \Drupal\Core\Entity\ContentEntityBase|null $entity
   *   The Content Entity Base.
   *
   * @return array
   *   The Link render array.
   */
  public function getImportTypes(string $link_title, ContentEntityBase $entity = NULL): array {
    $url = $this->getImportTypesLinkUrl($entity);

    $renderable = $this->getLinkRenderable($link_title);
    $renderable['#title'] = $url->getRouteName() === '<nolink>' ? $this->t('Incorrect Google Photo Connect link parameters') : $this->t($link_title);
    $renderable['#url'] = $url;
    $renderable['#options']['attributes']['class'][] = 'google-photo-import-type';

    if ($url->getRouteName() !== 'google_photos_importer.google_photos_importer_controller') {
      $this->addModalProperties($renderable, 'Search Media Items by');
    }

    return $renderable;
  }

  /**
   * Returns the Link render array to load albums.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @param int $entity_id
   *   The entity ID.
   *
   * @return array
   *   The Link render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function searchByAlbum(string $entity_type_id, int $entity_id): array {
    $link_title = 'Search by Album';
    $url = $this->getImportTypeLinkUrl($entity_type_id, $entity_id, 'google_photos_importer.google_albums_importer_form');

    $renderable = $this->getLinkRenderable($link_title);
    $renderable['#title'] = $url->getRouteName() === '<nolink>' ? $this->t('Incorrect Google Photo Connect link parameters') : $this->t($link_title);
    $renderable['#url'] = $url;
    $renderable['#options']['attributes']['class'][] = 'search-by-album';

//    $this->addModalProperties($renderable);

    return $renderable;
  }

  /**
   * Returns the Link render array to load photos.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @param int $entity_id
   *   The entity ID.
   *
   * @return array
   *   The Link render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function searchByDate(string $entity_type_id, int $entity_id): array {
    $link_title = 'Search by Date';
    $url = $this->getImportTypeLinkUrl($entity_type_id, $entity_id, 'google_photos_importer.google_photos_importer_form');

    $renderable = $this->getLinkRenderable($link_title);
    $renderable['#title'] = $url->getRouteName() === '<nolink>' ? $this->t('Incorrect Google Photo Connect link parameters') : $this->t($link_title);
    $renderable['#url'] = $url;
    $renderable['#options']['attributes']['class'][] = 'search-by-date';

//    $this->addModalProperties($renderable);

    return $renderable;
  }

  /**
   * Returns the Link render array.
   *
   * @param string $link_title
   *   The link label.
   * @param int|null $google_api_client_id
   *   The Google API Client ID.
   *
   * @return array
   *   The Link render array.
   */
  public function getLink(string $link_title, int $google_api_client_id = NULL): array {
    $url = $this->getLinkUrl($google_api_client_id);

    $renderable = $this->getLinkRenderable($link_title);
    $renderable['#title'] = $url->getRouteName() === '<nolink>' ? $this->t('Incorrect Google Photo Connect link parameters') : $this->t($link_title);
    $renderable['#url'] = $url;

    return $renderable;
  }

  /**
   * Returns the import types Link Url.
   *
   * @param \Drupal\Core\Entity\ContentEntityBase|null $entity
   *   The Content Entity Base.
   *
   * @return \Drupal\Core\Url
   *   The link Url.
   */
  private function getImportTypesLinkUrl(ContentEntityBase $entity = NULL): Url {
    $google_photo_api_client_value = $this->getGooglePhotoApiClientValue();

    if ($google_photo_api_client_value->isEmpty()
      || $this->googleApiClientIsDeAuthorized($google_photo_api_client_value->entity)) {
      return Url::fromRoute('google_photos_importer.google_photos_importer_controller', [
        'user' => $this->currentUser->id(),
        'redirect_destination' => $this->getDestination(),
      ]);
    }

    if (($entity instanceof ContentEntityBase)
      && !$this->googleApiClientIsDeAuthorized($google_photo_api_client_value->entity)) {
      return Url::fromRoute('google_photos_importer.import_type', [
        'entity_type_id' => $entity->getEntityTypeId(),
        'entity_id' => $entity->id(),
      ]);
    }

    return Url::fromUri('route:<nolink>');
  }

  /**
   * Returns the import type Link Url.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   * @param string $route_name
   *   The link route name.
   *
   * @return \Drupal\Core\Url
   *   The link Url.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function getImportTypeLinkUrl(string $entity_type_id, int $entity_id, string $route_name = 'route:<nolink>'): Url {
    $google_photo_api_client_value = $this->getGooglePhotoApiClientValue();

    if ($google_photo_api_client_value->isEmpty()
      || $this->googleApiClientIsDeAuthorized($google_photo_api_client_value->entity)) {
      return Url::fromRoute('google_photos_importer.google_photos_importer_controller', [
        'user' => $this->currentUser->id(),
        'redirect_destination' => $this->getDestination(),
      ]);
    }

    if (is_numeric($entity_id)
      && !$this->googleApiClientIsDeAuthorized($google_photo_api_client_value->entity)) {
      /** @var \Drupal\Core\Entity\ContentEntityBase $entity */
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
      if ($entity instanceof ContentEntityBase) {
        return Url::fromRoute($route_name, [
          'data' => "$entity_type_id-$entity_id",
          'destination' => $entity->toUrl()->toString(),
        ]);
      }
    }

    return Url::fromUri('route:<nolink>');
  }

  /**
   * Returns the Link Url.
   *
   * @param int|null $google_api_client_id
   *   The Google API Client ID.
   *
   * @return \Drupal\Core\Url
   *   The link Url.
   */
  private function getLinkUrl(int $google_api_client_id = NULL): Url {
    $google_photo_api_client_value = $this->getGooglePhotoApiClientValue();

    if ($google_photo_api_client_value->isEmpty()
      || $this->googleApiClientIsDeAuthorized($google_photo_api_client_value->entity)) {
      return Url::fromRoute('google_photos_importer.google_photos_importer_controller', [
        'user' => $this->currentUser->id(),
        'redirect_destination' => $this->getDestination(),
      ]);
    }

    if (!empty($google_api_client_id)) {
      return Url::fromRoute('google_api_client.callback', [
        'id' => $google_api_client_id,
        'destination' => $this->getDestination(),
      ]);
    }

    return Url::fromUri('route:<nolink>');
  }

  /**
   * Returns the Google Photo API Client field value.
   *
   * @return \Drupal\Core\Field\EntityReferenceFieldItemList
   *   The Google Photo API Client field value.
   */
  private function getGooglePhotoApiClientValue(): EntityReferenceFieldItemList {
    $user = User::load($this->currentUser->id());
    return $user->get('field_google_photo_api_client');
  }

  /**
   * Returns a link renderable array.
   *
   * @param string $link_title
   *   The link label.
   *
   * @return array
   */
  private function getLinkRenderable(string $link_title): array {
    return [
      '#type' => 'link',
      '#title' => $this->t($link_title),
      '#url' => Url::fromUri('route:<nolink>'),
      '#options' => [
        'attributes' => [
          'class' => ['google-photo-connection'],
          'alt' => $this->t($link_title),
          'title' => $this->t($link_title),
        ],
      ],
    ];
  }

  /**
   * Adds the modal properties.
   *
   * @param $renderable
   *   Link render array.
   * @param string $dialog_title
   *   The dialog popup title.
   */
  private function addModalProperties(&$renderable, string $dialog_title = '') {
    $encode = [
      'width' => 900,
    ];
    if (!empty($dialog_title)) {
      $encode['title'] = $this->t($dialog_title);
    }

    $renderable['#options']['attributes']['class'][] = 'use-ajax';
    $renderable['#options']['attributes']['data-dialog-type'] = 'modal';
    $renderable['#options']['attributes']['data-dialog-options'] = Json::encode($encode);
    $renderable['#attached'] = ['library' => ['core/drupal.dialog.ajax']];
  }

  /**
   * Cleanup the destination path.
   *
   * @return string|string[]
   */
  private function getDestination() {
    return str_replace('?_wrapper_format=drupal_modal', '', $this->getDestinationArray()['destination']);
  }

  /**
   * Check in attached api client is authenticated and has not access token
   * error.
   *
   * @param \Drupal\google_api_client\Entity\GoogleApiClient $google_photo_api_client
   *
   * @return bool
   */
  public function googleApiClientIsDeAuthorized(GoogleApiClient $google_photo_api_client): bool {
    $access_token = Json::decode($google_photo_api_client->getAccessToken());
    return !empty($access_token['error']) || !$google_photo_api_client->getAuthenticated();
  }

}
