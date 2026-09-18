<?php

namespace App\Controller\Admin;

use App\Entity\Handset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * The handset inventory — the phones that have walked quests (IP-149).
 *
 * Everything on a row was reported by the phone and is not for editing; `label`
 * is the one exception, and it exists so an operator can write "loaner A" next to
 * a machine string. NEW and DELETE are off: a handset comes into existence when
 * it starts a quest, and every enrollment that names it is provenance
 * (`ON DELETE RESTRICT`). The detail is a custom page (`admin_handset`) because it
 * renders the descriptor's nested blocks as tables, which a field list cannot.
 */
class HandsetCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Handset::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Handset')
            ->setEntityLabelInPlural('Handsets')
            ->setDefaultSort(['lastSeenAt' => 'DESC'])
            ->setSearchFields(['installationId', 'machine', 'manufacturer', 'model', 'label'])
            ->setPaginatorPageSize(50)
            ->setHelp(
                'index',
                'One row per app installation. A reinstall is a new row; group by machine to see the phones. '
                .'Only the label is editable — the rest is what the phone reported.'
            );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('platform')->setChoices(['iOS' => 'ios', 'Android' => 'android']))
            ->add(TextFilter::new('machine'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $open = Action::new('open', 'Open', 'fa fa-mobile-screen')
            ->linkToRoute('admin_handset', static fn (Handset $h) => ['id' => $h->getId()->toRfc4122()]);

        return $actions
            ->add(Crud::PAGE_INDEX, $open)
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('label')
            ->setRequired(false)
            ->setHelp('Operator note, e.g. "operator iPhone 15 Pro" or "loaner A". The only editable field.');
        yield TextField::new('platform')->hideOnForm();
        yield TextField::new('machine')->hideOnForm();
        yield TextField::new('model')->hideOnForm();
        yield TextField::new('osVersion', 'OS')->hideOnForm()
            ->formatValue(static fn ($v, Handset $h) => $h->getOsVersion() ?? '—');
        yield TextField::new('build', 'Build')->hideOnForm()
            ->formatValue(static fn ($v, Handset $h) => $h->getBuild() ?? '—');
        // ArrayField, not TextField: the value is a list and TextField refuses what it cannot cast.
        yield ArrayField::new('capabilities', 'Capabilities')->hideOnForm()
            ->formatValue(static fn ($v, Handset $h) => implode(' ', $h->getCapabilities()) ?: '—');
        yield IntegerField::new('enrollmentCount', 'Runs')->hideOnForm();
        yield DateTimeField::new('firstSeenAt', 'First seen')->hideOnForm();
        yield DateTimeField::new('lastSeenAt', 'Last seen')->hideOnForm();
        yield TextField::new('installationId', 'Installation')->hideOnForm()->hideOnIndex();
    }
}
