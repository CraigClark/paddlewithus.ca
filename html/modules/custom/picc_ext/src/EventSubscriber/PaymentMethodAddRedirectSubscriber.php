<?php

declare(strict_types=1);

namespace Drupal\picc_ext\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects direct access to the payment method add form.
 */
final class PaymentMethodAddRedirectSubscriber implements
    EventSubscriberInterface
{
    public function __construct(
        private readonly MessengerInterface $messenger,
        private readonly AccountProxyInterface $currentUser,
        private readonly RedirectDestinationInterface $redirectDestination
    ) {}

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (
            $request->attributes->get("_route") !==
            "entity.commerce_payment_method.add_form"
        ) {
            return;
        }

        // Resolve the {user} route parameter (can be an entity or an ID).
        $user_param = $request->attributes->get("user");
        $uid = null;

        if ($user_param instanceof UserInterface) {
            $uid = (int) $user_param->id();
        } elseif (is_numeric($user_param)) {
            $uid = (int) $user_param;
        } else {
            // Fallback to current user.
            $uid = (int) $this->currentUser->id();
        }

        // Optional message (remove if you don't want any UI).
        $this->messenger->addStatus(
            t("To add a new payment method, add it during checkout.")
        );

        // Redirect to the payment methods list, preserving language + destination.
        $options = [
            "query" => $this->redirectDestination->getAsArray(),
        ];

        $url = Url::fromRoute(
            "entity.commerce_payment_method.collection",
            ["user" => $uid],
            $options
        );

        $event->setResponse(new RedirectResponse($url->toString(), 302));
    }

    public static function getSubscribedEvents(): array
    {
        // Run after routing has set _route and parameters.
        return [
            KernelEvents::REQUEST => ["onRequest", 0],
        ];
    }
}
