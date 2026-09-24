<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings: who can open this interface, and the two facts that change how it reads.
 *
 * OPERATOR ACCOUNTS ARE NOT PARTICIPANTS. They are listed here and not in the Participants
 * lane because they are a different population with a different question attached — "who can
 * sign in" rather than "who walked" — and mixing them was one reason the old Participants CRUD
 * had to show three kinds of identity at once.
 *
 * There is no "add operator" button. `/api/auth/register` can only mint ROLE_USER by design,
 * and promotion runs through `app:user:create --admin` on the container: a screen that could
 * grant ROLE_SUPERADMIN is a privilege-escalation surface, and one reachable over public HTTPS
 * would be a published one. The edit link goes to the user CRUD, which is where the role
 * checkboxes already live behind the same session.
 */
#[IsGranted('ROLE_SUPERADMIN')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AdminUrlGenerator $adminUrls,
        private readonly string $adminTimezone,
    ) {
    }

    #[AdminRoute(path: '/settings', name: 'settings')]
    public function index(): Response
    {
        // Roles live in a json column and PostgreSQL has no LIKE for json, so the filter is
        // applied in PHP over the accounts the repository returns rather than in DQL. The
        // population is operators, which is single digits; a query that needed a cast to text
        // would buy nothing and would have to be kept in step with the security layer's own
        // reading of the array.
        $operators = [];
        foreach ($this->users->findBy([], ['createdAt' => 'ASC']) as $user) {
            if ($user instanceof User && in_array(UserRole::SUPERADMIN->value, $user->getRoles(), true)) {
                $operators[] = [
                    'user' => $user,
                    'edit_url' => $this->adminUrls
                        ->setController(UserCrudController::class)
                        ->setAction(Action::EDIT)
                        ->setEntityId($user->getId())
                        ->generateUrl(),
                ];
            }
        }

        return $this->render('admin/settings.html.twig', [
            'operators' => $operators,
            'timezone' => $this->adminTimezone,
        ]);
    }
}
