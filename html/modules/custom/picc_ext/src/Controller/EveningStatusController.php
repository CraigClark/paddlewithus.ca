<?php

namespace Drupal\picc_ext\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Render\RenderContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns the evening program status banner as a standalone HTML fragment.
 *
 * The front page is fully cacheable (by LiteSpeed and Drupal's page cache) and
 * carries only a lightweight placeholder. A JS behaviour computes today's date
 * in America/Toronto and fetches this endpoint with a `?d=YYYY-MM-DD` query, so
 * the fragment URL changes at local midnight — the edge cache misses and the
 * banner re-renders (resetting to "not updated today"). Within a day the URL is
 * stable, so the fragment is cached cheaply.
 *
 * Freshness is driven by the URL changing at midnight, not by cache expiry, so
 * it survives LiteSpeed ignoring TTLs or tags. The response is still given a
 * max-age capped at the next local midnight and the block content list tag, so
 * Drupal's own caches behave and editor edits bust it immediately.
 */
class EveningStatusController extends ControllerBase {

  public function __construct(
    protected RendererInterface $renderer,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('renderer'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Renders the evening_program_status view block as a bare HTML fragment.
   */
  public function fragment(): CacheableResponse {
    // Render the existing view block display for the current language. Rendering
    // in an isolated context lets us capture the metadata it bubbles (cache
    // tags, contexts) without leaking it into a page render.
    $build = views_embed_view('evening_program_status', 'evening_program_status');

    $context = new RenderContext();
    $markup = (string) $this->renderer->executeInRenderContext($context, function () use (&$build) {
      return $this->renderer->render($build);
    });

    $bubbled = CacheableMetadata::createFromRenderArray($build);
    if (!$context->isEmpty()) {
      $bubbled = $bubbled->merge(CacheableMetadata::createFromObject($context->pop()));
    }

    $response = new CacheableResponse($markup);

    // Build the response cacheability deliberately rather than inheriting the
    // view's: the view is intentionally `cache: none` (its "updated today?"
    // Twig test must re-evaluate on every render, so it must never be cached
    // permanently), which bubbles max-age 0. We keep its tags and contexts but
    // replace that max-age with "seconds until local midnight" — the reset
    // point. The changing `?d=` query is what actually busts the edge cache at
    // midnight; this max-age just keeps Drupal's caches honest.
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags($bubbled->getCacheTags());
    $cacheability->addCacheContexts($bubbled->getCacheContexts());
    // Editor edits to the status block bust the fragment right away.
    $cacheability->addCacheTags(['block_content_list']);
    $cacheability->setCacheMaxAge($this->secondsUntilMidnight());
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Seconds from now until the next local midnight (minimum 1).
   *
   * Uses the site default timezone (America/Toronto), matching the banner's own
   * server-side "today" comparison, so the cache expires at the local rollover.
   */
  protected function secondsUntilMidnight(): int {
    $tz_name = $this->config('system.date')->get('timezone.default') ?: date_default_timezone_get();
    $tz = new \DateTimeZone($tz_name);
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($tz);
    $midnight = $now->modify('tomorrow')->setTime(0, 0, 0);
    return max(1, $midnight->getTimestamp() - $now->getTimestamp());
  }

}
