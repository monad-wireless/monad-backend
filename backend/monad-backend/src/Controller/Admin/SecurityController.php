<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Login and logout for the management interface.
 *
 * Separate from AuthController because the two answer different questions: that one issues a JWT
 * to a phone over JSON, this one starts a browser session for a human. Sharing a controller would
 * mean sharing a failure mode — a form post that returns a token, or an API client that follows a
 * redirect to a login page.
 */
class SecurityController extends AbstractController
{
    #[Route('/admin/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('@EasyAdmin/page/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),

            // No translation_domain: there is no translations/ directory, and the labels below
            // are passed as literal strings, so the template's default domain is fine.
            'favicon_path' => '/favicon.ico',
            'page_title' => 'MonadCount',
            'csrf_token_intention' => 'authenticate',
            'target_path' => $this->generateUrl('admin'),
            'username_label' => 'Email',
            'password_label' => 'Password',
            // No "forgot password" link: there is no reset flow, and an operator locked out is
            // recovered with app:user:create --promote from the container, which is also the only
            // path that cannot be triggered by someone who merely knows an address.
            'forgot_password_enabled' => false,
            // Deliberately off. A long-lived cookie on a management surface buys a few seconds of
            // convenience for an operator and an unattended-laptop foothold for everyone else.
            'remember_me_enabled' => false,
        ]);
    }

    /**
     * Intercepted by the firewall's logout listener — the body never runs, and Symfony requires
     * the route to exist anyway.
     */
    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key on the admin firewall.');
    }
}
