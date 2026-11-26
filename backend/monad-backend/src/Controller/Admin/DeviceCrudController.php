<?php

namespace App\Controller\Admin;

use App\Entity\Device;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * The physical fleet — the boxes stickers are stuck to (IP-128).
 *
 * TWO EDITORIAL LINES, both deliberate.
 *
 * `slug` is the identity printed on adhesive and encoded in the QR. Editing it
 * after a label is on a node does not rename the node — it orphans every sticker
 * already in the building, and worse, silently re-points them at a device that is
 * not the one in front of the person scanning. Editable, because a typo caught
 * before printing must be fixable, but the help text says what it costs.
 *
 * DELETE IS DISABLED. `quest_enrollments.device_id` is measurement provenance:
 * it records which physical node produced a run. Deleting a device would either
 * fail on the FK or, worse, silently rewrite what past measurements mean. A node
 * that leaves service is deactivated (`isActive = false`), which keeps its
 * history readable and its page honest — "retired" rather than a 404 for a
 * sticker that is still on a wall somewhere.
 */
class DeviceCrudController extends AbstractCrudController
{
    use StateWordFields;

    public static function getEntityFqcn(): string
    {
        return Device::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Device')
            ->setEntityLabelInPlural('Devices (fleet)')
            // The register is the Lab section's "Devices" tab; the override adds that section's
            // tab bar and nothing else, so the index keeps EasyAdmin's search, sort and filters.
            ->overrideTemplate('crud/index', 'admin/device_index.html.twig')
            ->setDefaultSort(['slug' => 'ASC'])
            ->setSearchFields(['slug', 'label', 'location', 'siteRef'])
            ->setHelp(
                'index',
                'The physical CSI fleet. A device`s slug is printed on its label and encoded in its QR code '
                .'(https://monad.dubec.dev/d/&lt;slug&gt;), so it must match the node`s hostname exactly.'
            )
            ->setHelp(
                'edit',
                'Changing `slug` orphans every printed label for this node and re-points those stickers '
                .'at a different device. Deactivate rather than delete: enrollments reference this row as '
                .'measurement provenance.'
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        // Provenance is not bookkeeping — see the class docblock.
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('slug')
            ->setHelp('Fleet hostname, lowercase. Also the URL segment in the label QR.');

        yield TextField::new('label')
            ->setHelp('Human name shown in listings, e.g. "Library north window".');

        yield TextareaField::new('location')
            ->setHelp('Where it physically hangs, in words someone could follow.')
            ->hideOnIndex();

        yield TextField::new('siteRef')
            ->setLabel('Site ref')
            ->setHelp('Matches the vocabulary already used in step configs, e.g. fiit/library.');

        yield $this->state(BooleanField::new('isActive')->setLabel('In service'), 'in service', 'retired');

        yield TextareaField::new('publicBlurb')
            ->setLabel('Public blurb')
            ->setHelp('Shown on the public /d/<slug> page. Written for a stranger, not a reviewer.')
            ->hideOnIndex();

        yield TextField::new('radioMac')
            ->setLabel('CSI radio MAC')
            ->setHelp('Operator reference only — never published on the public device page.')
            ->hideOnIndex();

        yield DateTimeField::new('commissionedAt')
            ->setLabel('Commissioned')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')->onlyOnDetail();
        yield DateTimeField::new('updatedAt')->onlyOnDetail();
    }
}
