<?php

declare(strict_types=1);

namespace App\Notification;

use App\Repository\PushTokenRepository;
use Kreait\Firebase\Factory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Picks the PushSender (IP-157): FCM when MONAD_FCM_CREDENTIALS names a readable file, null
 * otherwise. `is_file()` and not "non-empty": the compose mounts the DIRECTORY
 * `/app/config/fcm/` whether or not Ansible has rendered the service account into it, so the
 * variable can be set while the file is absent. Wired as the `App\Notification\PushSender`
 * factory in services.yaml.
 *
 * A credentials file that exists but cannot be parsed falls back to the null adapter with the
 * parser's message as the reason, rather than taking every request that autowires the sender
 * down with it.
 */
final class PushSenderFactory
{
    public function __construct(
        #[Autowire(env: 'MONAD_FCM_CREDENTIALS')]
        private readonly string $credentialsPath,
        private readonly PushTokenRepository $tokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(): PushSender
    {
        $path = trim($this->credentialsPath);
        if ($path === '') {
            return new NullPushSender($this->logger, 'MONAD_FCM_CREDENTIALS is empty');
        }
        if (!is_file($path) || !is_readable($path)) {
            return new NullPushSender($this->logger, sprintf('MONAD_FCM_CREDENTIALS=%s is not a readable file', $path));
        }

        try {
            $messaging = (new Factory())->withServiceAccount($path)->createMessaging();
        } catch (\Throwable $e) {
            $this->logger->error('FCM credentials rejected; push is off', ['path' => $path, 'error' => $e->getMessage()]);

            return new NullPushSender($this->logger, sprintf('the service account at %s was rejected: %s', $path, $e->getMessage()));
        }

        return new FcmPushSender($messaging, new FcmPayloadBuilder(), $this->tokens, $this->logger);
    }
}
