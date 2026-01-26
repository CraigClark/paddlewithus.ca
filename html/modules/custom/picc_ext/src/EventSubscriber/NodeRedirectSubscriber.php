<?php

declare(strict_types=1);

namespace Drupal\picc_ext\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class NodeRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly AdminContext $adminContext,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    if ($request->attributes->get('_route') !== 'entity.node.canonical') {
      return;
    }

    if ($this->adminContext->isAdminRoute($request->attributes->get('_route_object'))) {
      return;
    }

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface) {
      return;
    }

    if (!$node->isPublished()) {
      return;
    }

    if ($this->currentUser->hasPermission('skip node redirect')) {
      return;
    }

    if (!$node->hasField('field_redirect') || !$node->hasField('field_redirect_destination')) {
      return;
    }

    if ((bool) $node->get('field_redirect')->value !== TRUE) {
      return;
    }

    if ($node->get('field_redirect_destination')->isEmpty()) {
      return;
    }

    $link_item = $node->get('field_redirect_destination')->first();
    $url = $link_item->getUrl();

    if (!$url) {
      return;
    }

    $target = $url->toString(TRUE);

    $response = new RedirectResponse($target->getGeneratedUrl(), 301);

    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['user.permissions'])
      ->addCacheTags($node->getCacheTags());

    $cacheability->applyTo($response);

    $event->setResponse($response);
  }

}
