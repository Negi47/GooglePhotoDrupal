<?php

namespace Drupal\google_photos_importer\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\google_api_client\Entity\GoogleApiClient;
use Drupal\google_api_client\Service\GoogleApiClientService;
use Drupal\user\Entity\User;
use Google_Service_PhotosLibrary;

/**
 * Class Google Photos API Client Service.
 *
 * @package Drupal\google_photos_importer\Service
 */
class GooglePhotosClient {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  public $loggerFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Google API Client.
   *
   * @var \Drupal\google_api_client\Service\GoogleApiClientService
   */
  private $googleApiClient;

  /**
   * Google Service Photos Library.
   *
   * @var \Google_Service_PhotosLibrary
   */
  public $googleServicePhotosLibrary;

  /**
   * AccountProxyInterface definition.
   *
   * @var AccountProxyInterface
   */
  public $currentUser;

  /**
   * GooglePhotosClient constructor.
   *
   * Callback Controller constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   LoggerChannelFactoryInterface.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\google_api_client\Service\GoogleApiClientService $googleApiClient
   *   GoogleApiClient.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *  Current user.
   */
  public function __construct(LoggerChannelFactoryInterface $loggerFactory,
                              EntityTypeManagerInterface $entityTypeManager,
                              GoogleApiClientService $googleApiClient,
                              AccountProxyInterface $account) {

    $this->loggerFactory = $loggerFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->googleApiClient = $googleApiClient;
    $this->currentUser = $account;
  }

  /**
   * The User authentication.
   *
   * @param int|null $user_id
   *   The User ID.
   *
   * @return $this
   *   The GooglePhotosClient object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function authenticate(int $user_id = NULL): GooglePhotosClient {
    $uid = $user_id ?? $this->currentUser->id();
    $user = User::load($uid);

    /** @var \Drupal\google_api_client\Entity\GoogleApiClient $google_api_client */
    $google_api_client = $user->get('field_google_photo_api_client')->entity;
    $google_api_client_id = NULL;
    if ($google_api_client instanceof GoogleApiClient) {
      $google_api_client_id = ((bool) $google_api_client->getAuthenticated()) ? $google_api_client->id() : NULL;
    }

    $access_token = Json::decode($google_api_client->getAccessToken());
    if (!empty($access_token['error'])) {
      $google_api_client_id = NULL;
    }

    if ($google_api_client_id) {
      $google_api_client = $this->entityTypeManager
        ->getStorage('google_api_client')->load($google_api_client_id);
      $this->googleApiClient->setGoogleApiClient($google_api_client);
      $this->setGoogleServicePhotosLibrary();
    }

    return $this;
  }

  /**
   * Helper method to set Photos Libraries.
   */
  private function setGoogleServicePhotosLibrary(): void {
    // Set up the Photos Library Client that interacts with the API.
    $resource = new Google_Service_PhotosLibrary($this->googleApiClient->googleClient);
    $this->googleServicePhotosLibrary = $resource;
  }

}
