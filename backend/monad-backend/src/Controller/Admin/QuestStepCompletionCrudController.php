<?php

namespace App\Controller\Admin;

use App\Entity\QuestStepCompletion;
use App\Enum\QuestStepCompletionStatus;
use App\Form\JsonType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

/**
 * What a handset actually did, step by step.
 *
 * Read-only: this is device-reported measurement, not content. `stepData` is whatever the phone
 * sent, and it is the record an analysis later joins against — editing it would be editing the
 * result. The reason to look is diagnosis: a run of FAILED at the same step across handsets is a
 * broken step config, not eight broken participants.
 */
class QuestStepCompletionCrudController extends AbstractCrudController
{
    use StateWordFields;

    public static function getEntityFqcn(): string
    {
        return QuestStepCompletion::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Step completion')
            ->setEntityLabelInPlural('Step completions')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('enrollment')
            ->formatValue(static fn ($value, $entity) => $entity->getEnrollment()?->getUser()?->getEmail() ?? '—');
        yield AssociationField::new('step')
            ->formatValue(static fn ($value, $entity) => $entity->getStep()?->getName() ?? '—');
        yield $this->state(ChoiceField::new('status')
            ->setChoices(array_combine(
                array_map(static fn (QuestStepCompletionStatus $s) => $s->value, QuestStepCompletionStatus::cases()),
                QuestStepCompletionStatus::cases(),
            )));
        yield DateTimeField::new('startedAt', 'Started')->hideOnIndex();
        yield DateTimeField::new('completedAt', 'Completed');
        yield Field::new('stepData', 'Step data')->formatValue(static fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->setFormType(JsonType::class)->onlyOnDetail();
        yield AssociationField::new('skipRecords', 'Skips')
            ->formatValue(static fn ($value, $entity) => $entity->getSkipRecords()->count() . ' skip(s)')
            ->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Created')->onlyOnDetail();
    }
}
