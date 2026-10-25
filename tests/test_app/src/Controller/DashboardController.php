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
     * @return \Cake\Http\Response
     */
    public function health(): Response
    {
        return $this->getResponse()->withStringBody('ok');
    }
}
