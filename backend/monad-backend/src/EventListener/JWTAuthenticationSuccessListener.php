<?php

namespace App\EventListener;

use App\Constants\ErrorCode;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Exception\AuthException;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;

#[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_success', method: 'onAuthenticationSuccess')]
class JWTAuthenticationSuccessListener
{
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        if ($user->getStatus() === UserStatus::DELETED) {
            throw new AuthException(ErrorCode::AUTH_ACCOUNT_DELETED, Response::HTTP_FORBIDDEN);
        }

        if ($user->getStatus() === UserStatus::PENDING) {
            throw new AuthException(ErrorCode::AUTH_ACCOUNT_PENDING, Response::HTTP_FORBIDDEN);
        }

        if ($user->getStatus() === UserStatus::BANNED) {
            throw new AuthException(ErrorCode::AUTH_ACCOUNT_DISABLED, Response::HTTP_FORBIDDEN);
        }

        $data = [
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'token' => $event->getData()['token']
        ];

        $event->setData($data);
    }
}
