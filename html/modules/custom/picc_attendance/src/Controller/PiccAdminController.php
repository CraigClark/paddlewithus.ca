<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lightweight admin overview for /admin/config/picc.
 *
 * Drupal core's SystemController::overview() expects a 3-level menu tree
 * (group → subgroup → leaf) and silently drops any direct child that has
 * no children of its own. Our PICC group currently has a single leaf
 * (Attendance) so the stock overview renders "You do not have any
 * administrative items." This controller renders the direct children of
 * the group as admin blocks without requiring a third level.
 */
class PiccAdminController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    protected MenuLinkTreeInterface $menuTree,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('menu.link_tree'));
  }

  /**
   * Renders direct children of the given menu link as an admin page.
   */
  public function overview(string $link_id) {
    $parameters = new MenuTreeParameters();
    $parameters->setRoot($link_id)->excludeRoot()->setTopLevelOnly()->onlyEnabledLinks();
    $tree = $this->menuTree->load(NULL, $parameters);
    $tree = $this->menuTree->transform($tree, [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);

    $cacheability = new CacheableMetadata();
    $items = [];
    foreach ($tree as $key => $element) {
      $cacheability = $cacheability->merge(CacheableMetadata::createFromObject($element->access));
      if (!$element->access->isAllowed()) {
        continue;
      }
      $link = $element->link;
      $items[$key] = [
        'title' => $link->getTitle(),
        'description' => $link->getDescription(),
        'options' => $link->getOptions(),
        'url' => $link->getUrlObject(),
      ];
    }

    if (!$items) {
      $build = ['#markup' => $this->t('You do not have any administrative items.')];
      $cacheability->applyTo($build);
      return $build;
    }

    ksort($items);
    $build = [
      '#theme' => 'admin_block',
      '#block' => [
        'content' => [
          '#theme' => 'admin_block_content',
          '#content' => $items,
        ],
      ],
    ];
    $cacheability->applyTo($build);
    return $build;
  }

}
