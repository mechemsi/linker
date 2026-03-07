<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LinkConfigLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    public function __construct(
        private readonly LinkConfigLoader $configLoader,
    ) {
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $links = $this->configLoader->getAllLinks();

            return $this->json([
                'status' => 'ok',
                'links_loaded' => count($links),
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
