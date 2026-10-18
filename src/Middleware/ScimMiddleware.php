<?php
declare(strict_types=1);

namespace RockSoftware\Users\Middleware;

use Cake\Http\Response;
use Cake\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RockSoftware\Users\Service\Scim\ScimServer;
use RockSoftware\Users\UsersPlugin;

/**
 * Answers `/auth/scim/v2/*` itself, before the application's CSRF check and
 * sign-in requirement: the SCIM bearer token is what authorises it
 * (`ScimServer`). Every other request passes through.
 */
class ScimMiddleware implements MiddlewareInterface
{
    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path !== UsersPlugin::SCIM_PATH && !str_starts_with($path, UsersPlugin::SCIM_PATH . '/')) {
            return $handler->handle($request);
        }

        $baseUrl = rtrim(Router::fullBaseUrl(), '/') . UsersPlugin::SCIM_PATH;
        $result = (new ScimServer($baseUrl))->handle(
            $request->getMethod(),
            substr($path, strlen(UsersPlugin::SCIM_PATH)),
            $request->getQueryParams(),
            (string)$request->getBody(),
            $request->getHeaderLine('Authorization'),
        );

        $response = (new Response())
            ->withStatus($result->status)
            ->withHeader('Cache-Control', 'no-store');
        if ($result->body === null) {
            return $response;
        }

        return $response
            ->withType('application/scim+json')
            ->withStringBody((string)json_encode($result->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
