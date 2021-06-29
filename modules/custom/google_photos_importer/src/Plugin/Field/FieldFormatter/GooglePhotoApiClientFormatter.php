<?php

namespace Drupal\google_photos_importer\Plugin\Field\FieldFormatter;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Url;
use Drupal\google_photos_importer\Service\GooglePhotoConnectionLinkBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'entity reference label' formatter.
 *
 * @FieldFormatter(
 *   id = "google_photo_api_client",
 *   label = @Translation("Google Photo Connection"),
 *   description = @Translation("Display the Google Photo Connection of the referenced entities."),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */

class GooglePhotoApiClientFormatter extends EntityReferenceFormatterBase {

  /**
   * The Google Photo Connection Link Builder service.
   *
   * @var \Drupal\google_photos_importer\Service\GooglePhotoConnectionLinkBuilder
   */
  protected $googlePhotoConnectionLinkBuilder;

  /**
   * Constructs an GooglePhotoApiClientFormatter instance.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings settings.
   * @param \Drupal\google_photos_importer\Service\GooglePhotoConnectionLinkBuilder $google_photo_connection_link_builder
   *   The entity display repository.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, GooglePhotoConnectionLinkBuilder $google_photo_connection_link_builder) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->googlePhotoConnectionLinkBuilder = $google_photo_connection_link_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('google_photo_connection_link_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    $settings = $field_definition->getSettings();
    $target = $field_definition->getTargetEntityTypeId();
    return $target === 'user' && $settings['target_type'] === 'google_api_client';
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {

      $elements[$delta] = $this->googlePhotoConnectionLinkBuilder->getLink('Connect Google Photo account', (int) $items[$delta]->getValue()['target_id']);
      /** @var \Drupal\google_api_client\Entity\GoogleApiClient $google_photo_api_client */
      $google_photo_api_client = $items[$delta]->entity;

      if ($google_photo_api_client->getAuthenticated()) {
        $label = $this->t('Revoke access to your Google Photos account');

        $uri = Url::fromRoute('entity.google_api_client.revoke_non_admin_form', [
          'google_api_client' => $entity->id(),
          'destination' => \Drupal::destination()->getAsArray()['destination'],
        ]);

        if (isset($uri)) {
          $elements[$delta] = [
            '#type' => 'link',
            '#title' => $label,
            '#url' => $uri,
            '#options' => $uri->getOptions() + [
              'attributes' => [
                'class' => ['use-ajax'],
                'data-dialog-type' => 'modal',
                'data-dialog-options' => Json::encode([
                  'width' => 900,
                ]),
              ],
            ],
          ];
          $elements[$delta]['#attached'] = [
            'library' => ['core/drupal.dialog.ajax'],
          ];
        }
      }

      $elements[$delta]['#cache']['tags'] = $entity->getCacheTags();
    }

    return $elements;
  }

}
