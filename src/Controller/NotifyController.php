<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\InvalidParametersException;
use App\Exception\LinkNotFoundException;
use App\Exception\NotificationFailedException;
use App\Service\LinkNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class NotifyController extends AbstractController
{
    public function __construct(
        private readonly LinkNotificationService $notificationService,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $notifyLimiter,
    ) {
    }

    #[Route('/notify/{linkName}', name: 'app_notify', methods: ['GET', 'POST'])]
    public function __invoke(string $linkName, Request $request): JsonResponse
    {
        $limiter = $this->notifyLimiter->create($request->getClientIp() ?? 'unknown');
        if (false === $limiter->consume()->isAccepted()) {
            $this->logger->warning('Rate limit exceeded for link "{link}"', [
                'link' => $linkName,
                'client_ip' => $request->getClientIp(),
            ]);

            return $this->json([
                'status' => 'error',
                'message' => 'Rate limit exceeded. Please try again later.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $queryParams = $request->query->all();

        $this->logger->info('Incoming notification request for link "{link}"', [
            'link' => $linkName,
            'method' => $request->getMethod(),
            'parameters' => $queryParams,
            'client_ip' => $request->getClientIp(),
        ]);

        try {
            $notified = $this->notificationService->send($linkName, $queryParams);

            $this->logger->info('Notification request completed for link "{link}"', [
                'link' => $linkName,
                'channels_notified' => $notified,
            ]);

            return $this->json([
                'status' => 'ok',
                'link' => $linkName,
                'channels_notified' => $notified,
            ]);
        } catch (NotificationFailedException $e) {
            $this->logger->warning('Notification partially failed for link "{link}"', [
                'link' => $linkName,
                'channels_notified' => $e->getSucceededTransports(),
                'channels_failed' => $e->getFailedTransports(),
            ]);

            return $this->json([
                'status' => 'partial_failure',
                'link' => $linkName,
                'channels_notified' => $e->getSucceededTransports(),
                'channels_failed' => $e->getFailedTransports(),
            ], Response::HTTP_MULTI_STATUS);
        } catch (LinkNotFoundException $e) {
            $this->logger->warning('Notification request for unknown link "{link}"', [
                'link' => $linkName,
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (InvalidParametersException $e) {
            $this->logger->warning('Notification request with invalid parameters for link "{link}"', [
                'link' => $linkName,
                'errors' => $e->getErrors(),
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error during notification for link "{link}": {error}', [
                'link' => $linkName,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->json([
                'status' => 'error',
                'message' => 'Failed to dispatch notification: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
