<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\InvalidParametersException;
use App\Exception\LinkNotFoundException;
use App\Service\LinkNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NotifyController extends AbstractController
{
    public function __construct(
        private readonly LinkNotificationService $notificationService,
    ) {
    }

    #[Route('/notify/{linkName}', name: 'app_notify', methods: ['GET', 'POST'])]
    public function __invoke(string $linkName, Request $request): JsonResponse
    {
        try {
            $queryParams = $request->query->all();
            $notified = $this->notificationService->send($linkName, $queryParams);

            return $this->json([
                'status' => 'ok',
                'link' => $linkName,
                'channels_notified' => $notified,
            ]);
        } catch (LinkNotFoundException $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (InvalidParametersException $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'error',
                'message' => 'Failed to dispatch notification: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
