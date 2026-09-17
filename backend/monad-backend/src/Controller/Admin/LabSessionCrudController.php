<?php

namespace App\Controller\Admin;

use App\Entity\LabSession;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * The recording-session register — every session the app uploaded (IP-149).
 *
 * READ-ONLY, all of it. A row is written by the upload path when artefacts land
 * and completed when the sidecar arrives; nothing an operator could type here is
 * a fact about what a phone recorded. The list is the CRUD index because EasyAdmin
 * gives filters, search and paging for free; the detail is a custom page
 * (`admin_recording_session`) because it renders the sidecar block by block,
 * lists the artefacts with download links, and embeds the walk figures.
 *
 * NOT the ground-truth sessions. Those are the scan groups keyed by
 * `lab_session_id`, listed under Lab operations; a recording session's page links
 * to the ground-truth session whose scans name it.
 */
class LabSessionCrudController extends AbstractCrudController
{
    use StateWordFields;

    public static function getEntityFqcn(): string
    {
        return LabSession::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Recording session')
            ->setEntityLabelInPlural('Recording sessions')
            ->setDefaultSort(['firstArtefactAt' => 'DESC'])
            ->setSearchFields(['id', 'participantId', 'site', 'machine', 'buildId'])
            ->setPaginatorPageSize(50)
            ->setHelp(
                'index',
                'One row per session the app uploaded to datasets/monad-app-sessions/. "Complete" means the '
                .'sidecar (metadata.json) arrived; a row without it has streams and no description. '
                .'Sessions uploaded before 2026-09-04 appear after `app:lab-sessions:backfill`.'
            );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('quest'))
            ->add(ChoiceFilter::new('platform')->setChoices(['iOS' => 'ios', 'Android' => 'android']))
            ->add(BooleanFilter::new('completedAt', 'Complete'))
            ->add(TextFilter::new('participantId', 'Participant'))
            ->add(DateTimeFilter::new('firstArtefactAt', 'Uploaded'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $open = Action::new('open', 'Open', 'fa fa-folder-open')
            ->linkToRoute('admin_recording_session', static fn (LabSession $s) => ['id' => $s->getId()]);

        return $actions
            ->add(Crud::PAGE_INDEX, $open)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('firstArtefactAt', 'Uploaded');
        yield TextField::new('id', 'Session')
            ->formatValue(static fn ($v, LabSession $s) => $s->shortId());
        yield TextField::new('participantId', 'Participant')
            ->formatValue(static fn ($v, LabSession $s) => substr($s->getParticipantId(), 0, 8));
        yield AssociationField::new('quest')
            ->formatValue(static fn ($v, LabSession $s) => $s->getQuest()?->getName() ?? '—');
        // ArrayField, not TextField: the property is a list and TextField refuses a value it cannot cast.
        yield ArrayField::new('roles')
            ->formatValue(static fn ($v, LabSession $s) => implode(' ', $s->getRoles()) ?: '—');
        yield TextField::new('platform')
            ->formatValue(static fn ($v, LabSession $s) => trim(($s->getPlatform() ?? '') . ' ' . ($s->getMachine() ?? '')) ?: '—');
        yield TextField::new('buildId', 'Build');
        yield IntegerField::new('artefactCount', 'Files')
            ->formatValue(static fn ($v, LabSession $s) => $s->getArtefactCount());
        // Generic fields for the derived numbers: TextField refuses an int it did not read from a string column.
        yield Field::new('artefactBytes', 'Bytes')
            ->formatValue(static fn ($v, LabSession $s) => self::bytes($s->getArtefactBytes()));
        yield Field::new('durationSeconds', 'Duration')
            ->formatValue(static fn ($v, LabSession $s) => self::duration($s->getDurationSeconds()));
        yield $this->state(BooleanField::new('complete', 'Complete')->renderAsSwitch(false), 'complete', 'no sidecar');
        yield TextField::new('interruptedReason', 'Interrupted')
            ->formatValue(static fn ($v, LabSession $s) => $s->getInterruptedReason() ?? '');
    }

    private static function bytes(int $bytes): string
    {
        $value = (float) $bytes;
        foreach (['B', 'kB', 'MB', 'GB'] as $i => $unit) {
            if ($value < 1000 || $unit === 'GB') {
                return $i === 0 ? sprintf('%d %s', $value, $unit) : sprintf('%.1f %s', $value, $unit);
            }
            $value /= 1000;
        }

        return sprintf('%.1f GB', $value);
    }

    private static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds < 60) {
            return sprintf('%d s', $seconds);
        }
        if ($seconds < 3600) {
            return sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
