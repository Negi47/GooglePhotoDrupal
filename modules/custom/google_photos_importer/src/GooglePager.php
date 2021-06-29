<?php

namespace Drupal\google_photos_importer;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Pager\PagerManager;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class GooglePager.
 *
 * This service is mostly aims to handle the "prev" pager link.
 */
class GooglePager {

  /**
   * \Drupal\Core\TempStore\PrivateTempStore definition.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $privateTempStore;

  /**
   * \Symfony\Component\HttpFoundation\Request definition.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * @var \Drupal\Core\Pager\PagerManager
   */
  private $drupalPagerManager;

  /**
   * Constructs a new GooglePager object.
   */
  public function __construct(
    PrivateTempStoreFactory $temp_store_factory,
    LoggerChannel $loggerChannel,
    RequestStack $request_stack,
    PagerManager $pager_manager
  ) {
    $this->privateTempStore = $temp_store_factory->get('google_photos_importer_pager');
    $this->currentRequest = $request_stack->getCurrentRequest();
    $this->drupalPagerManager = $pager_manager;
  }

  /**
   * Search the related next_page_token.
   *
   * This method will match the current filters with ?page=N and return the
   * token.
   *
   * @param array $filters
   * @param int $current_page
   *
   * @return string
   */
  public function findPreviousPageToken(array $filters, int $current_page): string {
    $hash = $this->generateFiltersHash($filters);
    $token = $this->privateTempStore->get($hash . ':' . ($current_page - 1));

    return (string) $token;
  }

  /**
   * Save the current page "next_page_token" related to the current ?page=N.
   *
   * @param array $filters
   *   Get query parameters.
   * @param int $page
   *   The current pager page number
   * @param string $next_page_token
   *   The next page token related to currecnt page.
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function saveCurrentPageToken(array $filters, int $page, string $next_page_token): void {
    $hash = $this->generateFiltersHash($filters);
    $this->privateTempStore->set($hash . ':' . $page, $next_page_token);
  }

  /**
   * Generate the hash from filters.
   *
   * @param array $filters
   *
   * @return string
   */
  public function generateFiltersHash(array $filters): string {
    $filters_keys = [
      'date_to',
      'date_from',
      'is_range',
      'datelist',
    ];
    $filters = array_intersect_key($filters, array_flip($filters_keys));
    $hash = Crypt::hashBase64(Json::encode($filters));
    return $hash;
  }

  /**
   * Initialise the Drupal pager manager and get prev_page_token for pager.
   *
   * @param array $data_info
   *
   * @return string
   */
  public function initDrupalPager(array $data_info): string {
    $query = $this->currentRequest->query->all();
    $query['page'] = $query['page'] ?? 0;

    $limit = 1;
    $total = $limit * ((int) $query['page'] + 3);

    if (empty($data_info['next_page_token'])) {
      $total = $limit * ((int) $query['page'] + 1);
    }

    $this->drupalPagerManager->createPager($total, $limit);

    $current_page = $query['page'] ?? 0;

    $prev_page_token = '';
    if ($current_page !== 0) {
      $prev_page_token = $this->findPreviousPageToken($query, $current_page);
    }

    return $prev_page_token;
  }

}
