<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;

/**
 * The application's own pages: no authentication component, because the
 * plugin requires sign-in for every route that is not public.
 */
class DashboardController extends Controller
{
    /**
     * @return \Cake\Http\Response
     */
    public function index(): Response
    {
        return $this->getResponse()->withStringBody('dashboard');
    }

    /**
     * A bearer-token API endpoint (OwnAuthApplication): answers with the
     * identity's email, or 401 without one.
     *
     * @return \Cake\Http\Response
     */
    public function ping(): Response
    {
        $identity = $this->getRequest()->getAttribute('identity');
        if ($identity === null) {
            return $this->getResponse()->withStatus(401);
        }

        return $this->getResponse()->withStringBody('pong ' . (string)$identity->get('email'));
    }

    /**
     * An MCP endpoint (`RockSoftwareUsers.bearer.paths`): answers with what the
     * request knows about who is calling.
     *
     * @return \Cake\Http\Response
     */
    public function agent(): Response
    {
        $request = $this->getRequest();
        $identity = $request->getAttribute('identity');

        return $this->getResponse()->withType('application/json')->withStringBody((string)json_encode([
            'email' => $identity?->get('email'),
            'bearer' => $request->getAttribute('rockSoftwareUsers.bearer'),
            'isViaAgent' => $request->getAttribute('rockSoftwareUsers.isViaAgent'),
        ]));
    }

    /**
     * @return \Cake\Http\Response
     */
    public function health(): Response
    {
        return $this->getResponse()->withStringBody('ok');
    }
}
