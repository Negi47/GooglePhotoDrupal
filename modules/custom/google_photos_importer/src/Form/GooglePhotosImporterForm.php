<?php

namespace Drupal\google_photos_importer\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\google_photos_importer\Traits\ImporterTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class GooglePhotosImporterForm.
 */
class GooglePhotosImporterForm extends FormBase implements ImporterFormInterface {

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
   * The Google Photo Connection Link Builder service.
   *
   * @var \Drupal\google_photos_importer\Service\GooglePhotoConnectionLinkBuilder
   */
  protected $googlePhotoConnectionLinkBuilder;

  /**
   * Batch Builder.
   *
   * @var \Drupal\Core\Batch\BatchBuilder
   */
  protected $batchBuilder;

  /**
   * @var \Drupal\google_photos_importer\GooglePager|object|null
   */
  private $googlePager;

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
    $instance->googlePhotoConnectionLinkBuilder = $container->get('google_photo_connection_link_builder');
    $instance->batchBuilder = new BatchBuilder();
    $instance->googlePager = $container->get('google_photos_importer.google_pager');
    $instance->queue = $container->get('queue')->get('google_import_queue');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_photos_importer_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $data = ''): array {
    $items_info = $this->getMediaItemsInfo($form, $form_state);

    if (!empty($items_info['error'])) {
      $form['markup'] = [
        '#type' => 'item',
        '#plain_text' => $this->t('Please connect to google account first.'),
      ];
      return $form;
    }

    if (empty($items_info) || (!isset($items_info['next_page_token']) && count($items_info) == 1)) {
      $form['markup'] = [
        '#type' => 'item',
        '#plain_text' => $this->t('You are do not have any images. Please make sure you have created Google photo account.'),
      ];
      return $form;
    }

    $form['#attached']['library'][] = 'google_photos_importer/selection_bucket';
    $form['#attached']['library'][] = 'google_photos_importer/selection_bucket_styles';

    $this->addApiToken($form);

    [$entity_type_id, $entity_id] = explode('-', $data);
    $entity = $this->entityTypeManager->getStorage($entity_type_id)
      ->load($entity_id);
    $form_state->set('entity', $entity);

    $this->addBgProcessCheckbox($form);

    $this->addFilters($form);

    $form['submit_before'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#submit' => ['::importPhotosSubmitForm'],
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

    $this->addPager($form, $form_state, $items_info);

    $this->addResultsContainer($form);

    /** @var array $item */
    foreach ($items_info as $id => $item) {
      // If no images.
      if (!is_array($item)) {
        continue;
      }

      $form['results_container'][$id] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];

      $creation_time = new DrupalDateTime($item['creation_time']);
      $description = [
        $this->dateFormatter->format($creation_time->getTimestamp(), 'custom', 'Y-M-d'),
      ];

      $description[] = $item['description'] ?? $item['file_name'];

      $form['results_container'][$id]['image'] = [
        '#theme' => 'image',
        '#uri' => $item['base_url'],
        '#alt' => implode(' ', $description),
        '#attributes' => [
          '#width' => 300,
        ],
      ];

      $form['results_container'][$id]['selected'] = [
        '#type' => 'checkbox',
        '#title' => implode(' ', $description),
        '#attributes' => [
          'data-media-id' => $id,
        ],
        '#weight' => '0',
      ];

      $render[] = [
        '#theme' => 'search_results',
        '#results' => $form['results_container'][$id],
        '#type' => 'remote',
      ];
    }

    $form['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#submit' => ['::importPhotosSubmitForm'],
      '#attributes' => [
        'class' => [
          'google-photos-importer-form',
        ],
      ],
    ];

    $form['pager_bottom'] = $form['pager'];

    return $form;
  }

  /**
   * Adds filters to the form.
   *
   * @param array $form
   *   The complete form array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  private function addFilters(array &$form) {
    $form['is_range'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Search By Date Range?'),
      '#default_value' => $this->getRequest()->get('is_range'),
    ];

    // No range.
    $form['datelist_wrapper'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="is_range"]' => ['checked' => FALSE],
        ],
      ],
    ];
    $form['datelist_wrapper']['datelist'] = [
      '#type' => 'datelist',
      '#date_part_order' => [
        'year',
        'month',
        'day',
      ],
      '#date_date_callbacks' => ['_google_photos_importer_date_date_callbacks'],
      '#element_validate' => [
        [$this, 'validateDatelist'],
      ],
      '#date_year_range' => '1900:' . $this->getCurrentYear(),
      '#required' => FALSE,
    ];

    // Range.
    $date_format = $this->entityTypeManager->getStorage('date_format')
      ->load(self::DATE_FORMAT_NAME)
      ->getPattern();

    $form['date_from'] = [
      '#type' => 'date',
      '#title' => $this->t('Date from'),
      '#title_display' => 'invisible',
      '#date_date_format' => $date_format,
      '#default_value' => $this->getStartDateDefaultValue(),
      '#size' => 10,
      '#states' => [
        'visible' => [
          ':input[name="is_range"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['date_to'] = [
      '#type' => 'date',
      '#title' => $this->t('Date to'),
      '#title_display' => 'invisible',
      '#date_date_format' => $date_format,
      '#default_value' => $this->getEndDateDefaultValue(),
      '#size' => 10,
      '#states' => [
        'visible' => [
          ':input[name="is_range"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $this->addBackButton($form);

    // Controls.
    $form['filter'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Filter'),
      '#submit' => ['::filterPhotosSubmitForm']
      //  '#ajax' => [
      //    'callback' => [$this, 'ajaxCallbackFilter'],
      //  ],
    ];

    $entity_id = $this->getDestinationEntityId();
    $entity_type_id = $this->getDestinationEntityTypeId();
    $form['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => $this->googlePhotoConnectionLinkBuilder->getImportTypeLinkUrl($entity_type_id, $entity_id, 'google_photos_importer.google_photos_importer_form'),
      //      '#ajax' => '',
      '#attributes' => [
        'class' => [
          'button',
          'reset',
        ],
      ],
    ];
  }

  /**
   * Validation callback for a datelist element.
   *
   * If the date is valid, the date object created from the user input is set in
   * the form for use by the caller. The work of compiling the user input back
   * into a date object is handled by the value callback, so we can use it here.
   * We also have the raw input available for validation testing.
   *
   * @param array $element
   *   The element being processed.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   */
  public static function validateDatelist(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $input_exists = FALSE;
    $input = NestedArray::getValue($form_state->getValues(), $element['#parents'], $input_exists);
    $title = static::getElementTitle($element, $complete_form);

    if ($input_exists) {
      $all_empty = static::checkEmptyInputs($input, $element['#date_part_order']);

      // If there's empty input and the field is not required, set it to empty.
      if (empty($input['year']) && empty($input['month']) && empty($input['day']) && !$element['#required']) {
        $form_state->setValueForElement($element, NULL);
      }
      // If there's empty input and the field is required, set an error.
      elseif (empty($input['year']) && empty($input['month']) && empty($input['day']) && $element['#required']) {
        $form_state->setError($element, t('The %field date is required.', ['%field' => $title]));
      }
      elseif (!empty($all_empty)) {
        // A Day must be selected with a month.
        if (!in_array('day', $all_empty) && in_array('month', $all_empty)) {
          $form_state->setError($element['month'], t('A value must be selected for %part.', ['%part' => 'Month']));
        }
      }
    }
  }

  /**
   * Returns the most relevant title of a datetime element.
   *
   * Since datetime form elements often consist of combined date and time fields
   * the element title might not be located on the element itself but on the
   * parent container element.
   *
   * @param array $element
   *   The element being processed.
   * @param array $complete_form
   *   The complete form structure.
   *
   * @return string
   *   The title.
   */
  protected static function getElementTitle(array $element, array $complete_form): string {
    $title = '';
    if (!empty($element['#title'])) {
      $title = $element['#title'];
    }
    else {
      $parents = $element['#array_parents'];
      array_pop($parents);
      $parent_element = NestedArray::getValue($complete_form, $parents);
      if (!empty($parent_element['#title'])) {
        $title = $parent_element['#title'];
      }
    }

    return $title;
  }

  /**
   * Checks the input array for empty values.
   *
   * Input array keys are checked against values in the parts array. Elements
   * not in the parts array are ignored. Returns an array representing elements
   * from the input array that have no value. If no empty values are found,
   * returned array is empty.
   *
   * @param array $input
   *   Array of individual inputs to check for value.
   * @param array $parts
   *   Array to check input against, ignoring elements not in this array.
   *
   * @return array
   *   Array of keys from the input array that have no value, may be empty.
   */
  protected static function checkEmptyInputs(array $input, array $parts): array {
    // Filters out empty array values, any valid value would have a string length.
    $filtered_input = array_filter($input, 'strlen');
    return array_diff($parts, array_keys($filtered_input));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Do nothing here.
  }

  /**
   * Perform the forms filtering.
   *
   * @param array $form
   *   The complete form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function filterPhotosSubmitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->cleanValues()->getValues();
    unset($values['results_container'], $values['select_all'], $values['ga_selection_bucket']);
    // Until this issue is resolved https://www.drupal.org/project/drupal/issues/2950883
    // We must be tied into hacks.
    $destination = $this->getRequest()->get('destination');
    $values['destination'] = $destination;
    $url = Url::fromRoute('<current>')->setOptions(['query' => $values]);

    if (!empty($destination)) {
      $this->getRequest()->query->remove('destination');
    }
    $form_state->setRedirectUrl($url);
  }

  /**
   * Submission handler.
   *
   * Import photos.
   *
   * @param array $form
   *   The complete form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function importPhotosSubmitForm(array &$form, FormStateInterface $form_state) {
    if (!empty($form_state->getUserInput()['ga_selection_bucket'])) {
      $google_photo_ids = explode(',', $form_state->getUserInput()['ga_selection_bucket']);
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
        ]);
        $this->messenger()->addMessage(self::BG_MESSAGE);
      }
      else {
        $this->setBatch('GooglePhotosImporterForm', $google_photo_ids, $form_state);
      }
    }
  }

  /**
   * Fetch the google photo album information.
   *
   * @param array $form
   *   The complete form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   */
  private function getMediaItemsInfo(array $form, FormStateInterface $form_state): array {
    $items_info = [];
    if (!$this->googlePhotosImporterClient->googleServicePhotosLibrary instanceof \Google_Service_PhotosLibrary) {
      return ['error' => 'no client attached'];
    }

    try {
      $search = new \Google_Service_PhotosLibrary_SearchMediaItemsRequest();
      $filters = new \Google_Service_PhotosLibrary_Filters();

      // Media type filter.
      $media_type_filter = new \Google_Service_PhotosLibrary_MediaTypeFilter();
      $media_type_filter->setMediaTypes(['PHOTO']);
      $filters->setMediaTypeFilter($media_type_filter);

      // Date filters.
      $request_filters = $this->getRequest()->query->all();

      if (!empty($request_filters['is_range'])) {
        $date_filter = new \Google_Service_PhotosLibrary_DateFilter();
        $date_range = new \Google_Service_PhotosLibrary_DateRange();

        // Start date.
        $start_date = new \Google_Service_PhotosLibrary_Date();
        $from = $request_filters['date_from'];
        $timestamp_from = strtotime($from) ?: 0;

        $start_date->setYear($this->dateFormatter->format($timestamp_from, 'custom', 'Y'));
        $start_date->setMonth($this->dateFormatter->format($timestamp_from, 'custom', 'm'));
        $start_date->setDay($this->dateFormatter->format($timestamp_from, 'custom', 'd'));
        $date_range->setStartDate($start_date);

        // End date.
        $end_date = new \Google_Service_PhotosLibrary_Date();
        $to = $request_filters['date_to'];
        $timestamp_to = strtotime($to) ?: $this->time->getRequestTime();

        $end_date->setYear($this->dateFormatter->format($timestamp_to, 'custom', 'Y'));
        $end_date->setMonth($this->dateFormatter->format($timestamp_to, 'custom', 'm'));
        $end_date->setDay($this->dateFormatter->format($timestamp_to, 'custom', 'd'));
        $date_range->setEndDate($end_date);

        // Set filter.
        $date_filter->setRanges($date_range);
        $filters->setDateFilter($date_filter);
      }
      elseif (!empty($request_filters['datelist'])) {
        $date_list = $request_filters['datelist'];

        if (is_array($date_list)) {
          $date_filter = new \Google_Service_PhotosLibrary_DateFilter();
          $date = new \Google_Service_PhotosLibrary_Date();

          if (!empty($date_list['year'])) {
            $date->setYear($date_list['year']);
          }
          if (!empty($date_list['month'])) {
            $date->setMonth($date_list['month']);
          }
          if (!empty($date_list['day'])) {
            $date->setDay($date_list['day']);
          }

          // Set filter.
          $date_filter->setDates($date);
          $filters->setDateFilter($date_filter);
        }
      }

      $search->setFilters($filters);

      // Pager.
      $next_page_token = $this->getRequest()->get('next_page_token');
      $search->setPageToken($next_page_token);
      // Save the pager info for current user.
      $current_page = $request_filters['page'] ?? 0;
      if ($current_page && $next_page_token) {
        $this->googlePager->saveCurrentPageToken($request_filters, (int) $current_page, $next_page_token);
      }

      // This is just desired page size. Google may not respect that page size.
      $search->setPageSize(25);

      $search_media_items_response = $this->googlePhotosImporterClient->googleServicePhotosLibrary->mediaItems->search($search);
      $items_info['next_page_token'] = $search_media_items_response->getNextPageToken();
      $google_photos = $search_media_items_response->getMediaItems();

      /** @var \Google_Service_PhotosLibrary_MediaItem $photo_item */
      foreach ($google_photos as $photo_item) {
        $items_info[$photo_item->getId()] = [
          'base_url' => $photo_item->getBaseUrl(),
          'description' => $photo_item->getDescription(),
          'file_name' => $photo_item['modelData']['filename'],
          'creation_time' => $photo_item->getMediaMetadata()->getCreationTime(),
        ];
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
      $this->getLogger('google_photos_importer:GooglePhotosImporterForm')
        ->error($e->getMessage());
    }

    return $items_info;
  }

  /**
   * Returns current year.
   *
   * @return string
   */
  private function getCurrentYear(): string {
    return $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y');
  }

  /**
   * Returns the destination entity ID.
   *
   * @return int
   *   The entity ID.
   */
  private function getDestinationEntityId(): int {
    $data = $this->getRequest()->get('data');
    return (int) preg_replace('~[^0-9]~', '', $data);
  }

  /**
   * Returns Returns the destination entity type ID.
   *
   * @return string
   *   The entity type ID.
   */
  private function getDestinationEntityTypeId(): string {
    $data = $this->getRequest()->get('data');
    return preg_replace('~[0-9-]~', '', $data);
  }

  /**
   * Returns the 'date_from' field default value.
   *
   * @return string
   */
  private function getStartDateDefaultValue(): string {
    $request_filters = $this->getRequest()->query->all();

    if (!empty($request_filters['date_from'])) {
      return $request_filters['date_from'];
    }

    return '';
  }

  /**
   * Returns the 'date_to' field default value.
   *
   * @return string
   */
  private function getEndDateDefaultValue(): string {
    $request_filters = $this->getRequest()->query->all();

    if (!empty($request_filters['date_to'])) {
      return $request_filters['date_to'];
    }

    return '';
  }

}
