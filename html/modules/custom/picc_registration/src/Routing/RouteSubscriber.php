<?php

declare(strict_types=1);

namespace Drupal\picc_registration\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\views\Plugin\views\display\Page;
use Symfony\Component\Routing\RouteCollection;

/**
 * Replaces the Profile module's collection route with our admin view.
 *
 * The contrib EntityListBuilder at /admin/people/profiles is unusable past
 * a few dozen profiles. We swap that route's controller to render the
 * picc_profiles_admin view (page_1 display) instead, keeping the URL and
 * the existing People > Profiles menu link.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.profile.collection');
    if (!$route) {
      return;
    }

    $route->setDefaults([
      '_controller' => '\Drupal\views\Routing\ViewPageController::handle',
      'view_id' => 'picc_profiles_admin',
      'display_id' => 'page_1',
      '_title' => 'Profiles',
    ]);
    $route->setRequirements([
      '_permission' => 'administer profile',
    ]);
    $route->setOption('_view_display_plugin_class', Page::class);
    $route->setOption('_view_display_plugin_id', 'page');
    $route->setOption('_view_display_show_admin_links', TRUE);
    $route->setOption('_view_argument_map', []);
    $route->setOption('returns_response', FALSE);
  }

}
