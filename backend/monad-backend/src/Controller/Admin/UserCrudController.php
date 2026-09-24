<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Participant and operator accounts.
 *
 * Two things here are not ordinary CRUD. Passwords are write-only — the form never shows the
 * hash and an empty field means "leave it alone", so editing a name cannot silently blank a
 * credential. And deletion is soft: the entity's softDelete() scrubs email, name, password and
 * roles in place, which is what a participant withdrawing actually needs (their identity gone,
 * their pseudonymous scans intact and still countable). A hard DELETE would either orphan or
 * cascade into measurement data, and neither is a decision an admin screen should be making.
 */
class UserCrudController extends AbstractCrudController
{
    use StateWordFields;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['email', 'name'])
            ->setHelp('index', 'Roles: every account has ROLE_USER implicitly. ROLE_SUPERADMIN is what grants access to this interface.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $anonymise = Action::new('anonymise', 'Anonymise', 'fa fa-user-slash')
            ->linkToCrudAction('anonymise')
            ->displayIf(static fn (User $user) => !$user->isDeleted())
            ->addCssClass('text-danger');

        // IP-149 — the participant's runs, sessions, handsets and contribution, on one page.
        $participant = Action::new('participant', 'Runs', 'fa fa-person-walking')
            ->linkToRoute('admin_participant', static fn (User $u) => ['id' => $u->getId()?->toRfc4122()]);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $participant)
            ->add(Crud::PAGE_DETAIL, $participant)
            ->add(Crud::PAGE_INDEX, $anonymise)
            ->add(Crud::PAGE_DETAIL, $anonymise)
            // Hard delete is off: see the class comment. Anonymise is the supported path.
            ->disable(Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email');
        yield TextField::new('name')->setRequired(false);

        // Bound to assignedRoles, NOT to roles. getRoles() is the security contract and appends the
        // implicit ROLE_USER on every read, so a field bound to it showed a tick nobody had set and
        // could not persist the absence of one. assignedRoles is the stored array and round-trips.
        yield $this->state(ChoiceField::new('assignedRoles', 'Roles')
            ->setChoices(array_combine(
                array_map(static fn (UserRole $r) => $r->name, UserRole::cases()),
                array_map(static fn (UserRole $r) => $r->value, UserRole::cases()),
            ))
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setHelp('ROLE_USER is implicit and does not need to be ticked. ROLE_SUPERADMIN grants full access to this management interface.'));

        yield $this->state(ChoiceField::new('status')
            ->setChoices(array_combine(
                array_map(static fn (UserStatus $s) => ucfirst($s->value), UserStatus::cases()),
                UserStatus::cases(),
            )));

        // UNMAPPED, which is the whole trick. The entity's password carries #[Assert\NotBlank],
        // so a mapped field would bind an empty edit form onto the entity and fail validation
        // before any controller code could restore the old hash — and binding null would be a
        // TypeError against setPassword(string) even earlier. Left unmapped, the hash on the
        // entity is untouched by the form, validation sees the real value, and the submitted
        // plaintext is picked up by the POST_SUBMIT listener below and hashed onto the entity.
        // Never shown outside forms: there is nothing useful to display about a hash.
        yield TextField::new('password', 'New password')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('always_empty', true)
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->setHelp('Leave blank to keep the current password.')
            ->onlyOnForms();

        yield DateTimeField::new('createdAt', 'Created')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Updated')->onlyOnDetail();
        yield DateTimeField::new('deletedAt', 'Deleted')->onlyOnDetail();
    }

    public function anonymise(AdminContext $context, EntityManagerInterface $entityManager): RedirectResponse
    {
        /** @var User $user */
        $user = $context->getEntity()->getInstance();

        // Refusing to anonymise yourself: it would scrub the roles of the session doing the
        // scrubbing, and the next page load would be a 403 with no obvious cause.
        if ($user === $this->getUser()) {
            $this->addFlash('danger', 'You cannot anonymise the account you are signed in as.');
        } else {
            $user->softDelete();
            $entityManager->flush();
            $this->addFlash('success', 'Account anonymised. Its scans remain, keyed by participant token.');
        }

        return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    public function createEntity(string $entityFqcn): User
    {
        $user = new User();
        $user->setStatus(UserStatus::ACTIVE);

        return $user;
    }

    public function createNewFormBuilder(
        EntityDto $entityDto,
        KeyValueStore $formOptions,
        AdminContext $context,
    ): FormBuilderInterface {
        return $this->hashPasswordOnSubmit(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    public function createEditFormBuilder(
        EntityDto $entityDto,
        KeyValueStore $formOptions,
        AdminContext $context,
    ): FormBuilderInterface {
        return $this->hashPasswordOnSubmit(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * Move the unmapped plaintext onto the entity, hashed.
     *
     * The PRIORITY is load-bearing. Symfony's own ValidationListener is registered on POST_SUBMIT
     * at priority 0 while the form factory builds the form, which is before this method ever sees
     * the builder — so at equal priority it runs FIRST, validates a still-null password and fails
     * #[Assert\NotBlank] on a new account no matter what was typed. That was the admin's 422 on
     * user create. Above 0, this listener runs first and #[Assert\NotBlank] sees a hash — on a new
     * account the one just made here, on an edit the one already in the database.
     */
    private function hashPasswordOnSubmit(FormBuilderInterface $formBuilder): FormBuilderInterface
    {
        return $formBuilder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            if (!$form->has('password')) {
                return;
            }

            $plaintext = $form->get('password')->getData();
            if ($plaintext === null || $plaintext === '') {
                return; // Blank means "keep the current password".
            }

            $user = $form->getData();
            if ($user instanceof User) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $plaintext));
            }
        }, 100);
    }
}
