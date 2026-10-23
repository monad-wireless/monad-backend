<?php

namespace App\Controller\Admin;

use App\Entity\GroundTruthConflict;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Exclusion E3: two scans claiming the same nonce with different
 * (participant_token, zone_id, direction).
 *
 * Read-only for the same reason as the scans themselves — this IS the audit trail, and a
 * deletable audit trail is not one. The value of the page is timing: the only moment a human can
 * still find out what actually happened at that doorframe is while the session is running, so an
 * operator watching this list mid-session can go and ask. Afterwards it is an exclusion and
 * nothing more.
 */
class GroundTruthConflictCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return GroundTruthConflict::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Scan conflict')
            ->setEntityLabelInPlural('Scan conflicts (E3)')
            ->setDefaultSort(['observedAt' => 'DESC'])
            ->setSearchFields(['labSessionId', 'scanNonce', 'zoneId'])
            ->setHelp('index', 'Pre-registration exclusion E3. The stored scan was kept; the refused claim is recorded here and never reconciled.')
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('labSessionId', 'Lab session');
        yield TextField::new('zoneId', 'Zone');
        yield TextField::new('scanNonce', 'Nonce');
        yield TextField::new('acceptedTriple', 'Accepted');
        yield TextField::new('rejectedTriple', 'Rejected');
        yield TextField::new('rejectedMonoNs', 'Rejected mono_ns')->hideOnIndex();
        yield TextField::new('rejectedWallMs', 'Rejected wall_ms')->hideOnIndex();
        yield DateTimeField::new('observedAt', 'Observed');
    }
}
