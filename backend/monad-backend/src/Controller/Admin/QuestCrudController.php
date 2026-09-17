<?php

namespace App\Controller\Admin;

use App\Entity\Quest;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quests: retired as an authoring surface (IP-157).
 *
 * The quest builder at /admin/lab/quests owns every write now: it validates each step against
 * its type's schema and shows the preflight, which this generic CRUD never did. The class stays
 * because the analytics pages link to a `detail` reading of the row and because EasyAdmin
 * resolves the `Quest` entity to a CRUD controller when building URLs; its index redirects to
 * the builder, and NEW, EDIT and DELETE are removed so the only way to change a quest is the
 * validated one.
 */
class QuestCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Quest::class;
    }

    /** The CRUD index is the builder's index now. */
    public function index(AdminContext $context): Response
    {
        return $this->redirectToRoute('admin_lab_quests');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Quest')
            ->setEntityLabelInPlural('Quests')
            ->setDefaultSort(['availableFrom' => 'DESC'])
            ->setSearchFields(['name', 'description']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // IP-149 — funnel, durations, skip reasons and failing steps for this quest.
        $analytics = Action::new('analytics', 'Analytics', 'fa fa-chart-simple')
            ->linkToRoute('admin_quest_analytics', static fn (Quest $q) => ['id' => $q->getId()?->toRfc4122()]);
        // IP-157 — the one write path.
        $builder = Action::new('builder', 'Open in builder', 'fa fa-pen-ruler')
            ->linkToRoute('admin_lab_quests_edit', static fn (Quest $q) => ['id' => $q->getId()?->toRfc4122()]);

        return $actions
            ->remove(Crud::PAGE_DETAIL, Action::EDIT)
            ->remove(Crud::PAGE_DETAIL, Action::DELETE)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_DETAIL, $analytics)
            ->add(Crud::PAGE_DETAIL, $builder);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield TextareaField::new('description');
        yield TextField::new('audience');
        yield DateTimeField::new('availableFrom', 'From');
        yield DateTimeField::new('availableTo', 'To');
        yield NumberField::new('points')->setNumDecimals(1);
        yield IntegerField::new('estimatedDuration', 'Est. minutes');
        yield Field::new('requiredCapabilities', 'Required capabilities')
            ->formatValue(static fn ($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        yield AssociationField::new('armedDevices', 'Armed at devices');
        yield Field::new('recurrence', 'Replay policy')
            ->formatValue(static fn ($value) => null === $value
                ? 'unlimited'
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        yield Field::new('routePolicy', 'Route policy')
            ->formatValue(static fn ($value) => null === $value
                ? 'fixed'
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        yield AssociationField::new('steps')
            ->formatValue(static fn ($value, $entity) => $entity->getSteps()->count() . ' step(s)');
        yield AssociationField::new('createdBy', 'Created by')
            ->formatValue(static fn ($value, $entity) => $entity->getCreatedBy()?->getEmail() ?? '—');
        yield DateTimeField::new('createdAt', 'Created');
        yield DateTimeField::new('updatedAt', 'Updated');
    }
}
