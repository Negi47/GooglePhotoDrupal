<?php

namespace Drupal\google_photos_importer\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\google_photos_importer\Service\GooglePhotoConnectionLinkBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;

/**
 * Class GooglePhotosImporterController
 *
 * @package Drupal\google_photos_importer\Controller
 */
class GooglePhotosImporterController extends ControllerBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The Google Photo Connection Link Builder service.
   *
   * @var \Drupal\google_photos_importer\Service\GooglePhotoConnectionLinkBuilder
   */
  protected $googlePhotoConnectionLinkBuilder;

  /**
   * Constructs a GooglePhotosImporterController object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The temp store factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\google_photos_importer\Service\GooglePhotoConnectionLinkBuilder $google_photo_connection_link_builder
   *   The entity display repository.
   */
  public function __construct(AccountInterface $current_user, PrivateTempStoreFactory $temp_store_factory, RequestStack $request_stack, GooglePhotoConnectionLinkBuilder $google_photo_connection_link_builder) {
    $this->currentUser = $current_user;
    $this->tempStoreFactory = $temp_store_factory;
    $this->requestStack = $request_stack;
    $this->googlePhotoConnectionLinkBuilder = $google_photo_connection_link_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('tempstore.private'),
      $container->get('request_stack'),
      $container->get('google_photo_connection_link_builder')
    );
  }

  /**
   * @param \Drupal\Core\Session\AccountInterface $user
   *
   * Checking the filling of the field "field_google_photo_api_client" and if
   *   it is empty, fill it new entity of the GoogleApiClient.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function photosSelection(AccountInterface $user, Request $request): RedirectResponse {
    try {
      if ($user->get('field_google_photo_api_client')->isEmpty()) {

        $entity = $this->entityTypeManager()
          ->getStorage('google_api_client')
          ->load('1');

        if (empty($entity)) {
          throw new NotFoundHttpException($this->t('The base google api entity not found.'));
        }

        $values = $entity->toArray();
        unset($values['id'], $values['uuid'], $values['is_authenticated'], $values['uid'], $values['access_token']);

        $duplicated_entity = $this->entityTypeManager()
          ->getStorage('google_api_client')->create($values);

        $duplicated_entity->setName($user->getAccountName() . " ({$user->id()})");
        $duplicated_entity->save();

        $user->set('field_google_photo_api_client', $duplicated_entity);
        $user->save();
      }

      /** @var \Drupal\google_api_client\Entity\GoogleApiClient $google_photo_api_client */
      $google_photo_api_client = $user->get('field_google_photo_api_client')->entity;
      $google_photo_api_client->setAuthenticated(FALSE);
      $google_photo_api_client->save();

      return new RedirectResponse(Url::fromRoute('google_api_client.callback', [
        'id' => $user->get('field_google_photo_api_client')->target_id,
        'destination' => $request->get('redirect_destination'),
      ])->toString());

    }
    catch (Exeption $e) {
      $this->getLogger('google_photos_importer:GooglePhotosImporterController')
        ->error($e->getMessage());
    }
  }

  /**
   * Provides the import type selection.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   *
   * @return array
   *   The link list.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function importType(string $entity_type_id, int $entity_id): array {
    return [
      '#theme' => 'item_list',
      '#items' => [
        $this->googlePhotoConnectionLinkBuilder->searchByAlbum($entity_type_id, $entity_id),
        $this->googlePhotoConnectionLinkBuilder->searchByDate($entity_type_id, $entity_id),
      ],
    ];
  }

  /**
   * Checks that the user argument from is equal to the current user.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The account to use for get access.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function usersAreEqual(AccountInterface $user): AccessResultInterface {
    return AccessResult::allowedIf($user->hasPermission('access content')
      && ($this->currentUser->id() == $user->id()));
  }

  /**
   * Checks access for authenticate url.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function authenticateAccess(AccountInterface $account): AccessResultInterface {
    $request = $this->requestStack->getCurrentRequest();

    if ($account->hasPermission('use google api client')) {
      return AccessResult::allowed();
    }

    if ($state = $request->get('state')) {
      $state = Json::decode($state);
      $tempStore = $this->tempStoreFactory->get('google_api_client');
      /* We implement an additional hash check so that if the callback
       * is opened for public access like it will be done for google login
       * In that case we rely on the has for verifying that no one is hacking.
       */
      if (!isset($state['hash']) || $state['hash'] != $tempStore->get('state_hash')) {
        $this->messenger()
          ->addError($this->t('Invalid state parameter'), 'error');
        return AccessResult::forbidden();
      }
      else {
        return AccessResult::allowed();
      }
    }

    $account_id = $request->get('id');
    $account_type = $request->get('type', 'google_api_client');
    $access = $this->moduleHandler->invokeAll('google_api_client_authenticate_account_access', [
      $account_id,
      $account_type,
      $account,
    ]);

    // If any module returns forbidden then we don't allow authenticate.
    if (in_array(AccessResult::forbidden(), $access)) {
      return AccessResult::forbidden();
    }
    elseif (in_array(AccessResult::allowed(), $access)) {
      return AccessResult::allowed();
    }

    return AccessResult::neutral();
  }

  /**
   * Checks access to queue running.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function queueRunAccess() {
    $password = 'asdfasdjkkjasdbb';
    $request = $this->requestStack->getCurrentRequest();
    return ($request->get('password') == $password) ? AccessResult::allowed() : AccessResult::forbidden();
  }

  /**
   * Runs the queue:run Drush command.
   *
   * @return array
   */
  public function queueRun() {
    $process = new Process([
      'vendor/drush/drush/drush',
      'queue:run',
      'google_import_queue',
    ]);
    $process->run();

    return [
      '#markup' => $this->t('Completed successfully.'),
    ];
  }

}
