<?php

namespace App\Controller\Admin;

use App\Entity\QuestEnrollment;
use App\Enum\QuestEnrollmentStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Who is running which quest, and how far they got.
 *
 * Editable but not creatable: an enrollment is produced by a participant starting a quest in the
 * app, and one conjured here would have no step completions and no device behind it. Editing
 * exists for the real case — a session that ended badly and has to be marked abandoned so the
 * handset stops offering to resume it.
 */
class QuestEnrollmentCrudController extends AbstractCrudController
{
    use StateWordFields;

    public static function getEntityFqcn(): string
    {
        return QuestEnrollment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Enrollment')
            ->setEntityLabelInPlural('Enrollments')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // IP-149 — the reading of a run (steps, handset, sessions, ground truth, figures) is a
        // custom page; the CRUD detail stays for the raw field list and the edit stays for the one
        // legitimate write, marking a dead run abandoned.
        $open = Action::new('open', 'Open', 'fa fa-folder-open')
            ->linkToRoute('admin_enrollment', static fn (QuestEnrollment $e) => ['id' => $e->getId()->toRfc4122()]);

        return $actions
            ->add(Crud::PAGE_INDEX, $open)
            ->add(Crud::PAGE_DETAIL, $open)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user')
            ->setFormTypeOption('choice_label', 'email')
            ->formatValue(static fn ($value, $entity) => $entity->getUser()?->getEmail() ?? '—');
        yield AssociationField::new('quest')
            ->setFormTypeOption('choice_label', 'name')
            ->formatValue(static fn ($value, $entity) => $entity->getQuest()?->getName() ?? '—');
        // IP-128 — which physical node produced this run. MEASUREMENT PROVENANCE,
        // not bookkeeping: read-only for the same reason ground-truth scans are.
        // An admin screen that could reassign a run to another device would make
        // the device column unenforceable, invisibly, months before anyone reads
        // the data.
        yield AssociationField::new('device', 'Device')
            ->formatValue(static fn ($value, $entity) => $entity->getDevice()?->getSlug() ?? '—')
            ->onlyOnDetail();
        // IP-149 — which installation walked this run. Provenance like `device`, read-only like it.
        yield AssociationField::new('handset', 'Handset')
            ->formatValue(static fn ($value, $entity) => $entity->getHandset()?->displayName() ?? 'not reported')
            ->hideOnForm();
        // Server-stamped, and the only column the cooldown gate trusts —
        // `completedAt` arrives in the request body.
        yield DateTimeField::new('completionReceivedAt', 'Completion received')
            ->onlyOnDetail();
        yield $this->state(ChoiceField::new('status')
            ->setChoices(array_combine(
                array_map(static fn (QuestEnrollmentStatus $s) => $s->value, QuestEnrollmentStatus::cases()),
                QuestEnrollmentStatus::cases(),
            )));
        yield TextField::new('dataPath', 'Data path')->setRequired(false)->hideOnIndex();
        yield DateTimeField::new('completedAt', 'Completed')->setRequired(false);
        yield DateTimeField::new('createdAt', 'Created')->hideOnForm();
        yield AssociationField::new('stepCompletions', 'Step completions')
            ->formatValue(static fn ($value, $entity) => $entity->getStepCompletions()->count() . ' completion(s)')
            ->onlyOnDetail();
    }
}
