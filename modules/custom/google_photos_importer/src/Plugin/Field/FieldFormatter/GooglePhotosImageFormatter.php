<?php

namespace Drupal\google_photos_importer\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\link\Plugin\Field\FieldFormatter\LinkFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field formatter for Google remote and downloaded photos.
 *
 * @FieldFormatter(
 *   id = "rgp_url_img_formatter",
 *   label = @Translation("Formatter for google image field"),
 *   description = @Translation("Display the remote Google photos in different
 *   sizes."), field_types = {
 *     "rgp_url"
 *   }
 * )
 */
class GooglePhotosImageFormatter extends LinkFormatter {

  /**
   * Formatter plugin manager.
   *
   * @var \Drupal\Core\Field\FormatterPluginManager
   */
  protected $formatterPluginManager;

  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Entity Field Manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->formatterPluginManager = $container->get('plugin.manager.field.formatter');
    $instance->entityFieldManager = $container->get('entity_field.manager');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'image_style_remote' => '',
      'image_style_local' => '',
      'fallback_field' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = [];
    $remote_image_styles = [
      'origin' => $this->t('Original'),
      '200_200' => $this->t('Thumbnail 200x200'),
      '500_300' => $this->t('500x300'),
      '600_400' => $this->t('600x400'),
      '700_500' => $this->t('700x500'),
      '800_450' => $this->t('800x450'),
      '800_600' => $this->t('800x600'),
    ];

    $image_styles = $this->entityTypeManager->getStorage('image_style')
      ->loadMultiple();
    $local_image_styles = [];

    foreach ($image_styles as $image_style) {
      $local_image_styles[$image_style->id()] = $image_style->label();
    }

    $elements['image_style_remote'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style for remote photo link.'),
      '#default_value' => $this->getSetting('image_style_remote'),
      '#options' => $remote_image_styles,
    ];

    $elements['image_style_local'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style for downloaded photo.'),
      '#default_value' => $this->getSetting('image_style_local'),
      '#options' => $local_image_styles,
    ];

    // Hardcode for views.
    $fields = $this->entityFieldManager->getFieldDefinitions(
      'media',
      'google_photo'
    );

    unset($fields['thumbnail']);

    $fallback_field_options = [];
    foreach ($fields as $field_name => $field_definition) {
      $type = $field_definition->getType();
      if ($type !== 'image') {
        continue;
      }
      $fallback_field_options[$field_name] = $field_definition->getLabel() . " ({$field_name})";
    }

    $elements['fallback_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity field manager'),
      '#default_value' => $this->getSetting('fallback_field'),
      '#options' => $fallback_field_options,
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $settings = $this->getSettings();

    $media = $items->getParent()->getEntity();
    if ($media->hasField($settings['fallback_field']) && !$media->{$settings['fallback_field']}->isEmpty()) {
      $image_field_definition = $media->{$settings['fallback_field']}->getFieldDefinition();
      /** @var \Drupal\image\Plugin\Field\FieldFormatter\ImageFormatter $image */
      $image = $this->formatterPluginManager
        ->createInstance('image', [
          'label' => $image_field_definition->get('field_name'),
          'type' => $image_field_definition->get('field_type'),
          'view_mode' => '',
          'field_definition' => $image_field_definition,
          'settings' => [
            'image_style' => $settings['image_style_local'],
            'image_link' => 'content',
          ],
          'third_party_settings' => [],
        ]);
      $image->prepareView([$media->{$settings['fallback_field']}]);
      $elements = $image->viewElements($media->{$settings['fallback_field']}, $langcode);
    }
    else {
      $google_photo_link = $items->first()->getValue();
      if (!empty($google_photo_link)) {
        if ($settings['image_style_remote'] == 'origin') {
          $photo_link = $this->removeSizesFromGooglePhotoLink($google_photo_link['uri']);
          $elements[] = [
            '#theme' => 'image',
            '#uri' => $photo_link,
            '#alt' => 'Google photo',
          ];
        }
        else {
          [$width, $height] = explode('_', $settings['image_style_remote']);
          $photo_link = $this->generateGooglePhotoLinkWithSizes($google_photo_link['uri'], $width, $height);
          $elements[] = [
            '#theme' => 'image',
            '#uri' => $photo_link,
            '#width' => $width,
            '#height' => $height,
            '#alt' => 'Google photo',
          ];
        }
      }
    }

    return $elements;
  }

  /**
   * Generates Google Photo link with specified sizes.
   *
   * @param string $link
   *   Original link. Could be with specified width and height by default.
   * @param int $width
   *   Width parameter.
   * @param int $height
   *   Height parameter.
   *
   * @return string
   *   Updated url.
   */
  private function generateGooglePhotoLinkWithSizes(string $link, int $width, int $height): string {
    preg_match('/(.*)=w/', $link, $matches);

    if ($matches && isset($matches[1])) {
      $link = $matches[1] . "=w$width-h$height-c";
    }
    else {
      preg_match('/(.*)=s/', $link, $matches);
      if ($matches && isset($matches[1])) {
        $link = $matches[1] . "=w$width-h$height-c";
      }
    }

    return $link;
  }

  /**
   * Remove size properties from Google Photo link.
   *
   * @param string $link
   *   Link.
   *
   * @return string
   *   Cleaned-up link.
   */
  private function removeSizesFromGooglePhotoLink(string $link): string {
    preg_match('/(.*)=w/', $link, $matches);

    if ($matches && isset($matches[1])) {
      $link = $matches[1];
    }
    else {
      preg_match('/(.*)=s/', $link, $matches);
      if ($matches && isset($matches[1])) {
        $link = $matches[1];
      }
    }

    return $link;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $settings = $this->getSettings();

    if ($settings['image_style_remote']) {
      $summary[] = $this->t('Image style for google image links: @image_style', [
        '@image_style' => $settings['image_style_remote'],
      ]);
    }
    if ($settings['image_style_local']) {
      $summary[] = $this->t('Image style for downloaded google images: @image_style', [
        '@image_style' => $settings['image_style_local'],
      ]);
    }

    if ($settings['fallback_field']) {
      $summary[] = $this->t('Fallback field: @field', [
        '@field' => $settings['fallback_field'],
      ]);
    }

    return $summary;
  }

}
