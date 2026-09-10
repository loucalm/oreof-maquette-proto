<?php

declare(strict_types=1);

namespace App\Controller;

use App\Twig\RoleExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RoleController extends AbstractController
{
    #[Route('/role/{role}', name: 'role_switch', methods: ['POST'], requirements: ['role' => 'admin|responsable'])]
    public function switch(string $role, Request $request): Response
    {
        $request->getSession()->set('proto_role', $role);
        $this->addFlash('info', sprintf('Vue « %s » activée.', RoleExtension::ROLES[$role]));

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('formation_index'));
    }
}
