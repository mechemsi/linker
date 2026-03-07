<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LinkConfigLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LinksController extends AbstractController
{
    public function __construct(
        private readonly LinkConfigLoader $configLoader,
    ) {
    }

    #[Route('/links', name: 'app_links', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $links = $this->configLoader->getAllLinks();

            $result = [];
            foreach ($links as $name => $link) {
                $parameters = [];
                foreach ($link->parameters as $param) {
                    $paramInfo = [
                        'required' => $param->required,
                        'type' => $param->type,
                    ];
                    if (null !== $param->default) {
                        $paramInfo['default'] = $param->default;
                    }
                    $parameters[$param->name] = $paramInfo;
                }

                $result[$name] = [
                    'message_template' => $link->messageTemplate,
                    'parameters' => $parameters,
                    'channels' => array_map(
                        static fn ($ch) => $ch->transport,
                        $link->channels,
                    ),
                ];
            }

            return $this->json([
                'status' => 'ok',
                'links' => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
