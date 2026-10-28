<?php

namespace App\Controller\Admin;

use App\Entity\QuestStepSkipRecord;
use App\Form\JsonType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Why a step was skipped — the app's own account of what went wrong.
 *
 * Read-only device reports, and the most useful page in the content section: a skip is the phone
 * saying it could not do what it was told, with the error code and whatever metadata it had. A
 * cluster of the same errorCode is an equipment or config problem waiting to be found.
 */
class QuestStepSkipRecordCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return QuestStepSkipRecord::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Skip record')
            ->setEntityLabelInPlural('Skip records')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['message', 'errorCode']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'When');
        yield TextField::new('errorCode', 'Error code');
        yield TextField::new('message');
        yield AssociationField::new('stepCompletion', 'Step completion')
            ->formatValue(static fn ($value, $entity) => $entity->getStepCompletion()?->getStep()?->getName() ?? '—')
            ->hideOnIndex();
        yield Field::new('metadata')->formatValue(static fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->setFormType(JsonType::class)->onlyOnDetail();
    }
}
