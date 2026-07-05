<?php

namespace Drupal\picc_ext\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Placeholder mount for the JS-injected evening program status banner.
 *
 * Outputs an empty, height-reserving container instead of rendering the status
 * inline, so the page it sits on stays fully cacheable. The attached behaviour
 * fetches the language-appropriate fragment endpoint and injects the banner
 * client-side. See EveningStatusController for the endpoint and the caching
 * rationale.
 */
#[Block(
  id: 'picc_evening_status_banner',
  admin_label: new TranslatableMarkup('Evening program status banner'),
  category: new TranslatableMarkup('PICC'),
)]
class EveningStatusBannerBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Resolve the endpoint for the current language so the injected fragment
    // carries the right labels and French overrides (/en/… vs /fr/…). The JS
    // appends the Toronto date as a `?d=` query at request time.
    $endpoint = Url::fromRoute('picc_ext.evening_status')->toString();

    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['evening-prog-mount'],
        'aria-live' => 'polite',
        'data-evening-status-endpoint' => $endpoint,
      ],
      '#attached' => [
        'library' => ['picc_ext/evening_status'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The placeholder markup never changes, so it caches permanently. All
   * freshness lives in the fetched fragment.
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   *
   * The endpoint URL is language-prefixed, so vary the placeholder by the
   * interface language.
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['languages:language_interface']);
  }

}
