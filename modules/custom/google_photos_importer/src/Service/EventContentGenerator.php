<?php

namespace Drupal\google_photos_importer\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupContent;
use Drupal\group\Entity\GroupInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Implementation of EventContentGenerator Class.
 *
 * @package Drupal\google_photos_importer\Service
 */
class EventContentGenerator {

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
   * Constructs a new EventContentGenerator.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *  Current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(AccountProxyInterface $account, EntityTypeManagerInterface $entity_type_manager) {
    $this->currentUser = $account;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Returns the Event node.
   *
   * @param string $album_id
   *   The ID of album.
   * @param string $album_title
   *   The title of album.
   * @param int|null $uid
   *   The user ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The Event node entity with the 'is new' flag.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function createOrLoadEventByGoogleAlbumId(string $album_id, string $album_title, int $uid = NULL): EntityInterface {
    $events = $this->lookupEventByGoogleAlbumId($album_id);
    if (!empty($events)) {
      return reset($events);
    }

    return $this->create($album_title, $album_id, $uid);
  }

  /**
   * Loads the event node by Album ID if exists.
   *
   * @param string $album_id
   *   The ID of album.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The Event nodes array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function lookupEventByGoogleAlbumId(string $album_id): array {
    return $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'event',
      'field_album_id' => $album_id,
    ]);
  }

  /**
   * Creates the Event node.
   *
   * @param string $album_title
   *   The title of album.
   * @param string $album_id
   *   The ID of album.
   * @param int|null $uid
   *   The user ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *  The created Event node.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function create(string $album_title, string $album_id, int $uid = NULL): EntityInterface {
    $values = [
      'type' => 'event',
      'title' => $album_title,
      'field_album_id' => $album_id,
    ];
    if ($uid) {
      $values['uid'] = $uid;
    }
    $event = $this->entityTypeManager->getStorage('node')->create($values);
    $event->save();

    return $event;
  }

  /**
   * Returns the Event node.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Google_Service_PhotosLibrary_MediaItem $google_media_item
   *   The Google media item entity.
   * @param int|null $uid
   *   The user ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The Event node.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getEvent(\Google_Service_PhotosLibrary_MediaItem $google_media_item, FormStateInterface $form_state, int $uid = NULL) {
    $albums_mapping = $form_state->get('albums_mapping');

    if (is_null($albums_mapping)) {
      return NULL;
    }

    foreach ($albums_mapping as $album_id => $google_photos_ids) {
      if (in_array($google_media_item->getId(), $google_photos_ids['items'])) {
        $group_name = '';
        $entity = $form_state->get('entity');
        if ($entity instanceof Group) {
          $group_name = $entity->label();
        }

        if ($entity instanceof Node) {
          $group_contents = GroupContent::loadByEntity($entity);
          foreach ($group_contents as $group_content) {
            /** @var \Drupal\group\Entity\Group $group */
            $group = $group_content->getGroup();
            $group_name = $group->label();
          }
        }

        $album_title = $google_photos_ids['title'] ?: $this->generateEventTitle($group_name, $google_media_item, $uid);
        return $this->createOrLoadEventByGoogleAlbumId($album_id, $album_title, $uid);
      }
    }

    return NULL;
  }

  /**
   * Attaches the Event to Media.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param \Drupal\node\NodeInterface $event
   *   The Event node entity.
   */
  public function attachEventToMedia(MediaInterface $media, NodeInterface $event = NULL) {
    if ($event instanceof NodeInterface) {
      $media->get('field_' . $event->bundle())->appendItem($event);
    }
  }

  /**
   * Attaches the Event to Media.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\node\NodeInterface $event
   *   The Event node entity.
   */
  public function attachEventToGroup(GroupInterface $group, NodeInterface $event = NULL) {
    if ($event instanceof NodeInterface && empty(GroupContent::loadByEntity($event))) {
      $node_plugin_id = 'group_node:' . $event->bundle();
      $group->addContent($event, $node_plugin_id);
    }
  }

  /**
   * Generates the Album name.
   *
   * @param string $group_name
   *   The name of Group.
   * @param \Google_Service_PhotosLibrary_MediaItem $google_media_item
   *   The Google media item entity.
   * @param int|null $uid
   *   The user ID.
   *
   * @return string
   *   The generated album name.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function generateEventTitle(string $group_name, \Google_Service_PhotosLibrary_MediaItem  $google_media_item, int $uid = NULL): string {
    if ($uid) {
      /** @var \Drupal\Core\Session\AccountInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      $account_name = $account->getAccountName();
    }
    else {
      $account_name = $this->currentUser->getAccountName();
    }

    return implode(' - ', [$account_name, $group_name, $google_media_item->getMediaMetadata()->getCreationTime()]);
  }

}
