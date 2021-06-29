<?php

namespace Drupal\google_photos_importer\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\google_photos_importer\Form\ImporterFormInterface;
use Drupal\google_photos_importer\Service\GooglePhotosClient;
use Drupal\google_photos_importer\Service\EventContentGenerator;
use Drupal\google_photos_importer\Traits\ImporterTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Google Import Queue processing.
 *
 * @QueueWorker(
 *   id = "google_import_queue",
 *   title = @Translation("Google Import Queue"),
 *   cron = {"time" = 10}
 * )
 */
class GoogleImportQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface, ImporterFormInterface {

  use StringTranslationTrait;
  use LoggerChannelTrait;
  use MessengerTrait;
  use ImporterTrait {
    processItem as protected processingItem;
  }

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
   * The Event auto creation service.
   *
   * @var \Drupal\google_photos_importer\Service\EventContentGenerator
   */
  protected $eventContentGenerator;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Constructs a GoogleImportQueue plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\google_photos_importer\Service\GooglePhotosClient $google_photos_importer_client
   *   The Google Photos Importer client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   * @param \Drupal\google_photos_importer\Service\EventContentGenerator $event_content_generator
   *   The Event auto creation service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   */
  public function __construct(array $configuration, string $plugin_id, array $plugin_definition, GooglePhotosClient $google_photos_importer_client, EntityTypeManagerInterface $entity_type_manager, TimeInterface $time, DateFormatterInterface $date_formatter, FileSystemInterface $file_system, EventContentGenerator $event_content_generator, MailManagerInterface $mail_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->googlePhotosImporterClient = $google_photos_importer_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
    $this->dateFormatter = $date_formatter;
    $this->fileSystem = $file_system;
    $this->eventContentGenerator = $event_content_generator;
    $this->mailManager = $mail_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('google_photos_importer.client'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
      $container->get('file_system'),
      $container->get('google_photos_importer.event_content_generator'),
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (isset($data['username'])) {
      $this->sendMail($data);
    }
    else {
      $this->processingItem($data['mid'], $data['form_state'], $data['uid']);
    }
  }

  /**
   * Sends the email to user after the import finishing.
   *
   * @param array $data
   *   The mail data array.
   */
  private function sendMail(array $data) {
    $params['message'] = $this->t('Dear @username! Your selected @photos_count photos was imported.', [
      '@username' => $data['username'],
      '@photos_count' => $data['photos_count'],
    ]);
    if (isset($data['albums_count'])) {
      $params['message'] = $this->t('Dear @username! Your selected @albums_count albums was imported. Processed @photos_count photos.', [
        '@username' => $data['username'],
        '@photos_count' => $data['photos_count'],
        '@albums_count' => $data['albums_count'],
      ]);
    }

    $this->mailManager->mail('google_photos_importer', 'import_photos', $data['email'], $data['lang_code'], $params, NULL, TRUE);
  }

}
