<?php

namespace Drupal\google_photos_importer\Traits;

use Drupal\Component\Serialization\Json;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\google_api_client\Entity\GoogleApiClient;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupContent;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\google_photos_importer\Service\EventContentGenerator;

/**
 * The trait for using in the Google importer forms.
 *
 * @package Drupal\google_photos_importer\Traits
 */
trait ImporterTrait {

  /**
   * Adds the Back button to the form.
   *
   * @param array $form
   *   The complete form array.
   */
  private function addBackButton(array &$form) {
    $form['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back'),
      '#url' => Url::fromUserInput($this->getRequest()->get('destination')),
      '#attributes' => [
        'class' => [
          'button',
        ],
      ],
    ];
  }

  /**
   * Adds the 'Process in background' checkbox to the form.
   *
   * @param array $form
   *   The complete form array.
   */
  private function addBgProcessCheckbox(array &$form) {
    $form['bg_process'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Process in background'),
      '#default_value' => FALSE,
    ];
  }

  /**
   * Adds pager to the form.
   *
   * @param array $form
   *   The complete form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $data_info
   *   The google photo/album information.
   */
  private function addPager(array &$form, FormStateInterface $form_state, array $data_info) {
    $prev_page_token = $this->googlePager->initDrupalPager($data_info);

    $tags = [
      1 => $this->t('‹ Previous'),
      3 => $this->t('Next ›'),
    ];

    $form['pager'] = [
      '#theme' => 'views_mini_pager__photo_importer',
      '#tags' => $tags,
      '#element' => 0,
      '#parameters' => [
        'next_page_token' => $data_info['next_page_token'],
        'prev_page_token' => $prev_page_token,
      ],
      '#route_name' => '<none>',
    ];
  }

  /**
   * Adds the 'total selected' link to the form.
   *
   * @param array $form
   *   The complete form array.
   */
  private function addTotalSelectedLink(array &$form) {
    $form['total_selected'] = [
      '#type' => 'link',
      '#title' => '',
      '#url' => Url::fromRoute('<none>'),
      '#prefix' => '<div class="total-selected">' . $this->t('Selected: '),
      '#suffix' => '</div>',
    ];
  }

  /**
   * Adds the 'selection bucket' hidden element to the form.
   *
   * @param array $form
   *   The complete form array.
   */
  private function addSelectionBucket(array &$form) {
    $form['ga_selection_bucket'] = [
      '#type' => 'hidden',
      '#value' => '',
      '#attributes' => [
        'id' => 'ga-selection-bucket',
      ],
    ];
  }

  /**
   * Adds the 'results container' container to the form.
   *
   * @param array $form
   *   The complete form array.
   */
  private function addResultsContainer(array &$form) {
    $form['results_container'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['results-container'],
      ],
    ];
  }

  /**
   * Adds selection to the form.
   *
   * @param array $form
   *   The complete form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  private function addSelectItems(array &$form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'google_photos_importer/select_items';

    $form['select_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Select All/None'),
      '#default_value' => FALSE,
    ];
  }

  /**
   * Adds Google Photo API token.
   *
   * @param array $form
   *   The complete form array.
   */
  private function addApiToken(array &$form) {
    $user = User::load($this->currentUser()->id());
    /** @var \Drupal\google_api_client\Entity\GoogleApiClient $google_api_client */
    $google_api_client = $user->get('field_google_photo_api_client')->entity;

    $element['#attached']['drupalSettings']['google_photos_api_token'] = NULL;

    if (($google_api_client instanceof GoogleApiClient) && $google_api_client->getAuthenticated()) {
      $form['#attached']['drupalSettings']['google_photos_api_token'] = Json::decode($google_api_client->getAccessToken())['access_token'];
    }
  }

  /**
   * Creates Drupal Media entity.
   *
   * @param \Google_Service_PhotosLibrary_MediaItem $mediaItem
   *   the Google Media Item entity.
   * @param int|null $uid
   *   The user ID.
   *
   * @return \Drupal\media\Entity\Media
   *   The Media entity.
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function createMedia(\Google_Service_PhotosLibrary_MediaItem $mediaItem, int $uid = NULL): Media {
    $base_url = $mediaItem->getBaseUrl();

    $image_path = $base_url . '=w900';
    $image_name = $mediaItem['modelData']['filename'];
    $request_time = $this->time->getRequestTime();
    $html_month = $this->dateFormatter->format($request_time, 'html_month');

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->retrieveFile($image_path, "public://{$html_month}/", $image_name);

    $values = [
      'bundle' => self::MEDIA_BUNDLE_NAME,
      'field_media_rgp_url' => $base_url,
      self::IMAGE_FALLBACK_FIELD => [
        'target_id' => $file->id(),
        'alt' => $image_name,
      ],
    ];
    if ($uid) {
      $values['uid'] = $uid;
    }

    /** @var \Drupal\media\Entity\Media $media */
    $media = Media::create($values);

    $metadata = $mediaItem->getMediaMetadata();
    $creation_time = $metadata->getCreationTime();

    $media->setName($image_name);
    $media->setPublished();
    $media->set('field_start_date', $this->dateFormatter->format(strtotime($creation_time), self::DATE_FIELD_FORMAT_NAME));
    $media->set('field_end_date', $this->dateFormatter->format(strtotime($creation_time), self::DATE_FIELD_FORMAT_NAME));
    $media->set('field_media_rgp_id', $mediaItem->getId());
    $media->set('field_mime_type', $mediaItem->getMimeType());
    $media->set('field_media_height', $metadata->getHeight());
    $media->set('field_media_width', $metadata->getWidth());
    $media->set('field_media_description', $mediaItem->getDescription());
    $media->set('field_media_rgp_product_url', $mediaItem->getProductUrl());
    $media->save();

    return $media;
  }

  /**
   * Create the media references.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\media\MediaInterface $media
   *   A media entity.
   * @param \Drupal\node\NodeInterface|null $event
   *   The Event node.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function createMediaReferences(FormStateInterface $form_state, MediaInterface $media, NodeInterface $event = NULL) {
    $media_plugin_id = 'group_media:' . self::MEDIA_BUNDLE_NAME;
    $entity = $form_state->get('entity');

    if ($entity instanceof Node) {
      $media->get('field_related_' . $entity->bundle())->appendItem($entity);
      if ($this->isSetEventContentGenerator()) {
        $this->eventContentGenerator->attachEventToMedia($media, $event);
      }
      $media->save();
      $group_contents = GroupContent::loadByEntity($entity);
      foreach ($group_contents as $group_content) {
        /** @var \Drupal\group\Entity\Group $group */
        $group = $group_content->getGroup();
        $group->addContent($media, $media_plugin_id);
        if ($this->isSetEventContentGenerator()) {
          $this->eventContentGenerator->attachEventToGroup($group, $event);
        }
      }
    }

    if ($entity instanceof Group) {
      $media->save();
      $entity->addContent($media, $media_plugin_id);
      if ($this->isSetEventContentGenerator()) {
        $this->eventContentGenerator->attachEventToGroup($entity, $event);
      }
    }
  }

  /**
   * Retrieve the image file.
   *
   * @param string $file_uri
   *   The image file URI.
   * @param string $directory
   *   The destination file path.
   * @param string $image_name
   *   The name of file.
   *
   * @return \Drupal\file\FileInterface|null
   *   A \Drupal\file\FileInterface object which describes the file.
   *
   */
  public function retrieveFile(string $file_uri, string $directory, string $image_name) {
    // Try to re-use the file if one was already downloaded.
    /* @var \Drupal\file\FileInterface[] $files */
    $files = $this->entityTypeManager
      ->getStorage('file')
      ->loadByProperties(['uri' => $directory . $image_name]);

    $file = reset($files);
    if ($file instanceof FileInterface) {
      $existing_file_path = $this->fileSystem->realpath($file->getFileUri());
      if (file_exists($existing_file_path)) {
        return $file;
      }
    }

    $content = FALSE;

    try {
      $content = file_get_contents($file_uri);
      if ($content === FALSE) {
        return NULL;
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
      $this->getLogger('google_photos_importer:GooglePhotosImporterForm')
        ->error($e->getMessage());
    }

    if ($this->fileSystem
      ->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      return file_save_data($content, $directory . $image_name, FileSystemInterface::EXISTS_REPLACE);
    }

    return NULL;
  }

  /**
   * Sets batch.
   *
   * @param string $form_class_name
   *   The Import form class name.
   * @param array $google_photo_ids
   *   The Google photo items array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function setBatch(string $form_class_name, array $google_photo_ids, FormStateInterface $form_state) {
    $this->batchBuilder
      ->setTitle($this->t('Processing'))
      ->setInitMessage($this->t('Initializing.'))
      ->setProgressMessage($this->t('Completed @current of @total.'))
      ->setErrorMessage($this->t('An error has occurred.'));

    $this->batchBuilder->setFile(drupal_get_path('module', 'google_photos_importer') . "/src/Form/{$form_class_name}.php");
    $this->batchBuilder->addOperation([
      $this,
      'processItems',
    ], [$google_photo_ids, $form_state]);
    $this->batchBuilder->setFinishCallback([$this, 'finished']);

    batch_set($this->batchBuilder->toArray());
  }

  /**
   * Processor for batch operations.
   *
   * @param array $items
   *   The elements array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $context
   *   Reference to an array used for Batch API storage.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processItems(array $items, FormStateInterface $form_state, array &$context) {
    $limit = 1;

    // Set default progress values.
    if (empty($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = count($items);
    }

    // Save items to array which will be changed during processing.
    if (empty($context['sandbox']['items'])) {
      $context['sandbox']['items'] = $items;
    }

    $counter = 0;
    if (!empty($context['sandbox']['items'])) {
      // Remove already processed items.
      if ($context['sandbox']['progress'] != 0) {
        array_splice($context['sandbox']['items'], 0, $limit);
      }

      foreach ($context['sandbox']['items'] as $item) {
        if ($counter != $limit) {
          $this->processItem($item, $form_state);

          $counter++;
          $context['sandbox']['progress']++;

          $context['message'] = $this->t('Now processing item :progress of :count', [
            ':progress' => $context['sandbox']['progress'],
            ':count' => $context['sandbox']['max'],
          ]);

          // Increment total processed item values. Will be used in finished
          // callback.
          $context['results']['processed'] = $context['sandbox']['progress'];
        }
      }
    }

    // If not finished all tasks, we count percentage of process. 1 = 100%.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Process single item.
   *
   * @param string $google_photo_id
   *   The Google photo item ID.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param int|null $uid
   *   The user ID.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processItem(string $google_photo_id, FormStateInterface $form_state, int $uid = NULL) {
    $this->googlePhotosImporterClient->authenticate($uid);
    $mediaStorage = $this->entityTypeManager->getStorage('media');

    $mediaItem = $this->googlePhotosImporterClient
      ->googleServicePhotosLibrary
      ->mediaItems
      ->get($google_photo_id);

    $medias = $mediaStorage->loadByProperties([
      'bundle' => self::MEDIA_BUNDLE_NAME,
      'field_media_rgp_id' => $mediaItem->getId(),
    ]);

    $media = empty($medias) ? $this->createMedia($mediaItem, $uid) : reset($medias);
    $event = $this->isSetEventContentGenerator() ? $this->eventContentGenerator->getEvent($mediaItem, $form_state, $uid) : NULL;

    $this->createMediaReferences($form_state, $media, $event);
  }

  /**
   * Finished callback for batch.
   *
   * @param bool $success
   *   TRUE if batch successfully completed.
   * @param array $results
   *   Batch results.
   * @param array $operations
   *   An array of methods run in the batch.
   */
  public function finished(bool $success, array $results, array $operations) {
    $this->messenger()
      ->addStatus($this->t('Imported %count photos', [
        '%count' => $results['processed'],
      ]));
  }

  /**
   * Checks if the eventContentGenerator is set.
   *
   * @return bool
   *   TRUE if set.
   */
  private function isSetEventContentGenerator(): bool {
    return isset($this->eventContentGenerator) && $this->eventContentGenerator instanceof EventContentGenerator;
  }

}
