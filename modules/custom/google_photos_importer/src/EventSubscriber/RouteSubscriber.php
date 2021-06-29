<?php

namespace Drupal\google_photos_importer\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Google photos importer route subscriber.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -300];
    return $events;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    /** @var \Symfony\Component\Routing\Route $route */
    if ($route = $collection->get('entity.google_api_client.revoke_form')) {
      $route->setRequirement('_permission', 'revoke google api client access');
    }
    if ($route = $collection->get('google_api_client.callback')) {
      $route->setRequirement('_custom_access', '\Drupal\google_photos_importer\Controller\GooglePhotosImporterController::authenticateAccess');
    }
  }

}
