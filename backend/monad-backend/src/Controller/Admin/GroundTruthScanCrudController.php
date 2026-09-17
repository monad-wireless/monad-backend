<?php

namespace App\Controller\Admin;

use App\Entity\GroundTruthScan;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Ground-truth scans — READ ONLY, and not as a convenience.
 *
 * This table is the study's people channel: the only stream that counts humans rather than
 * phones. Its integrity rests on two pre-registered rules — a scan is never overwritten, and a
 * contradiction is logged to ground_truth_conflicts rather than reconciled by judgement (E3). An
 * admin UI that let an operator "fix" a row would make both unenforceable, and would do it
 * invisibly, months before anyone reads the data.
 *
 * So creation, editing and deletion are removed here rather than merely discouraged. The
 * legitimate way to change what this table says is to scan again.
 */
class GroundTruthScanCrudController extends AbstractCrudController
{
    use StateWordFields;

    public static function getEntityFqcn(): string
    {
        return GroundTruthScan::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ground-truth scan')
            ->setEntityLabelInPlural('Ground-truth scans')
            ->setDefaultSort(['receivedAt' => 'DESC'])
            ->setSearchFields(['labSessionId', 'participantToken', 'zoneId', 'scanNonce', 'recordingSessionId'])
            ->setHelp('index', 'Immutable record. Idempotency is a UNIQUE index on scan_nonce; duplicates keep the earliest mono_ns.')
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
        // An opaque pseudonym, by design: there is no foreign key to users, so a scan cannot be
        // joined to an account. Shown because an operator needs to see repeat scans, not identity.
        yield TextField::new('participantToken', 'Participant token');
        yield TextField::new('zoneId', 'Zone');
        yield $this->state(TextField::new('direction'));
        yield TextField::new('site')->hideOnIndex();
        yield TextField::new('monoNs', 'mono_ns')->hideOnIndex();
        yield TextField::new('wallMs', 'wall_ms')->hideOnIndex();
        yield TextField::new('scanNonce', 'Nonce')->hideOnIndex();
        yield TextField::new('recordingSessionId', 'Recording session')->hideOnIndex();
        yield DateTimeField::new('receivedAt', 'Received');
    }
}
