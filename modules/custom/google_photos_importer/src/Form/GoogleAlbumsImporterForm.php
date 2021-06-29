<?php

namespace Drupal\google_photos_importer\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\google_photos_importer\Traits\ImporterTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class GoogleAlbumsImporterForm.
 */
class GoogleAlbumsImporterForm extends FormBase implements ImporterFormInterface {

  use ImporterTrait;

  /**
   * Drupal\google_photos_importer\Service\GooglePhotosClient definition.
   *
   * @var \Drupal\google_photos_importer\Service\GooglePhotosClient
   */
  protected $googlePhotosImporterClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * @var \Drupal\google_photos_importer\GooglePager|object|null
   */
  private $googlePager;

  /**
   * Batch Builder.
   *
   * @var \Drupal\Core\Batch\BatchBuilder
   */
  protected $batchBuilder;

  /**
   * The Event auto creation service.
   *
   * @var \Drupal\google_photos_importer\Service\EventContentGenerator
   */
  protected $eventContentGenerator;

  /**
   * The queue object.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);

    $instance->googlePhotosImporterClient = $container->get('google_photos_importer.client')
      ->authenticate();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->time = $container->get('datetime.time');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->fileSystem = $container->get('file_system');
    $instance->pagerManager = $container->get('pager.manager');
    $instance->batchBuilder = new BatchBuilder();
    $instance->googlePager = $container->get('google_photos_importer.google_pager');
    $instance->eventContentGenerator = $container->get('google_photos_importer.event_content_generator');
    $instance->queue = $container->get('queue')->get('google_import_queue');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_albums_importer_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $data = ''): array {
    $albums_info = $this->getAlbumsInfo();

    if (!empty($albums_info['error'])) {
      return ['#markup' => $this->t('Please connect to google account first.')];
    }

    if (empty($albums_info)) {
      return ['#markup' => $this->t('You are do not have any albums. Please make sure you have created albums Google photo account.')];
    }

    $more_info_link = Link::fromTextAndUrl('here', Url::fromUri('https://support.google.com/photos/answer/6131416'))
      ->toString();
    $this->messenger()
      ->addWarning($this->t('Note: only shared albums are listed here. Read more about shared albums @here.', ['@here' => $more_info_link]));

    $form['#attached']['library'][] = 'google_photos_importer/selection_album_bucket';
    $form['#attached']['library'][] = 'google_photos_importer/selection_bucket_styles';

    $this->addApiToken($form);

    $this->addBgProcessCheckbox($form);

    $form['auto_create_event'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto create event'),
      '#default_value' => FALSE,
    ];

    $this->addBackButton($form);

    $form['submit_before'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#attributes' => [
        'class' => [
          'google-photos-importer-form',
        ],
      ],
    ];

    $this->addTotalSelectedLink($form);

    $form['total_selected'] = [
      '#type' => 'link',
      '#title' => '',
      '#url' => Url::fromRoute('<none>'),
      '#prefix' => '<div class="total-selected">' . $this->t('Selected: '),
      '#suffix' => '</div>',
    ];

    $this->addSelectionBucket($form);

    $this->addSelectItems($form, $form_state);

    $this->addPager($form, $form_state, $albums_info);

    $this->addResultsContainer($form);

    [$entity_type_id, $entity_id] = explode('-', $data);
    $entity = $this->entityTypeManager->getStorage($entity_type_id)
      ->load($entity_id);
    $form_state->set('entity', $entity);

    foreach ($albums_info['items'] as $item) {
      $form['results_container'][$item['id']] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];

      $form['results_container'][$item['id']]['image'] = [
        '#theme' => 'image',
        '#uri' => $item['cover'],
        '#alt' => $item['title'],
        '#attributes' => [
          '#width' => 300,
        ],
      ];

      $form['results_container'][$item['id']]['selected'] = [
        '#type' => 'checkbox',
        '#title' => $item['title'],
        '#description' => $this->t('Select this box to import the album'),
        '#attributes' => [
          'data-album-id' => $item['id'],
          'data-album-title' => $item['title'],
        ],
        '#weight' => '0',
      ];
    }

    $form['pager_bottom'] = $form['pager'];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#attributes' => [
        'class' => [
          'google-photos-importer-form',
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $albums_mapping = [];
    $google_photo_ids = [];
    $google_photo_ids_prev = [];
    $albums = Json::decode($form_state->getUserInput()['ga_selection_bucket']);
    $albums_count = count($albums);

    foreach ($albums as $album_id => $album_title) {
      $this->retrieveAlbumPhotosFromGoogle($google_photo_ids, $album_id);
      // The albums mapping creation.
      if ((bool) $form_state->getValue('auto_create_event')) {
        $albums_mapping[$album_id]['title'] = $album_title;
        $albums_mapping[$album_id]['items'] = array_diff($google_photo_ids, $google_photo_ids_prev);
        $google_photo_ids_prev = $google_photo_ids;
      }
    }

    if (!empty($google_photo_ids)) {
      if (!empty($albums_mapping)) {
        $form_state->set('albums_mapping', $albums_mapping);
      }
      if ((bool) $form_state->getValue('bg_process')) {
        $photos_count = count($google_photo_ids);
        foreach ($google_photo_ids as $mid) {
          $this->queue->createItem([
            'mid' => $mid,
            'form_state' => $form_state,
            'uid' => $this->currentUser()->id(),
          ]);
        }
        $this->queue->createItem([
          'username' => $this->currentUser()->getAccountName(),
          'email' => $this->currentUser()->getEmail(),
          'lang_code' => $this->currentUser()->getPreferredLangcode(),
          'photos_count' => $photos_count,
          'albums_count' => $albums_count,
        ]);
        $this->messenger()->addMessage(self::BG_MESSAGE);
        $this->messenger()->addMessage($this->t("We scheduled to import @photos_count photo(s), in @albums_count album(s)", [
          '@photos_count' => $photos_count,
          '@albums_count' => $albums_count,
          ]));
      }
      else {
        $this->setBatch('GoogleAlbumsImporterForm', $google_photo_ids, $form_state);
      }
    }
  }

  /**
   * Recursively retrieve the results from google for album.
   *
   * @param array $google_photo_ids
   *   The Google photo ids array to be imported.
   * @param string $album_id
   *   The album id to retrive the photos from.
   * @param null $next_page_token
   *   The next page token if exists.
   */
  private function retrieveAlbumPhotosFromGoogle(array &$google_photo_ids, string $album_id, $next_page_token = NULL): void {
    $search_filters = new \Google_Service_PhotosLibrary_SearchMediaItemsRequest();

    $search_filters->setAlbumId($album_id);
    if ($next_page_token) {
      $search_filters->setPageToken($next_page_token);
    }

    $search_result = $this->googlePhotosImporterClient
      ->googleServicePhotosLibrary
      ->mediaItems
      ->search($search_filters);
    $mediaItems = $search_result->getMediaItems();

    foreach ($mediaItems as $media_item) {
      $google_photo_ids[] = $media_item->getId();
    }

    $next_page_token = $search_result->getNextPageToken();
    if ($next_page_token) {
      $this->retrieveAlbumPhotosFromGoogle($google_photo_ids, $album_id, $next_page_token);
    }
  }

  /**
   * Fetch the google photo album information.
   */
  private function getAlbumsInfo(): array {
    $albums_info = [];
    if (!$this->googlePhotosImporterClient->googleServicePhotosLibrary instanceof \Google_Service_PhotosLibrary) {
      return ['error' => 'no client attached'];
    }

    try {
      $next_page_token_from_request = $this->getRequest()
        ->get('next_page_token');
      $albums = $this->googlePhotosImporterClient->googleServicePhotosLibrary->sharedAlbums->listSharedAlbums([
        'pageToken' => $next_page_token_from_request,
        'pageSize' => 10,
      ]);
      $albums_info['next_page_token'] = $albums->getNextPageToken();

      /** @var \Google_Service_PhotosLibrary_Album $album */
      foreach ($albums->getSharedAlbums() as $album) {
        $albums_info['items'][$album->getId()] = [
          'cover' => $album->getCoverPhotoBaseUrl(),
          'id' => $album->getId(),
          'number_of_photos' => $album->getTotalMediaItems(),
          'title' => $album->getTitle(),
          'shared_data' => $album->getShareInfo(),
        ];
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
      $this->getLogger('google_photos_importer:GoogleAlbumsImporterForm')
        ->error($e->getMessage());
    }

    return $albums_info;
  }

}
