<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;

/**
 * One line per enum or boolean field, and the badge becomes a word (IP-157 Phase 8).
 *
 * Every CRUD controller that shows an enum or a boolean uses this, including the ones that
 * left the menu: they stay ROUTABLE and are still reached from links on the reading pages
 * until the Runs and Participants lanes replace them, so `IN_PROGRESS` would have survived in
 * exactly the places nobody was looking.
 *
 * A dedicated reading template passes enums, arrays and booleans through StateWord.
 * EasyAdmin's text template casts raw values into a title attribute and cannot render
 * these types. The form widget remains ChoiceField or BooleanField as appropriate.
 */
trait StateWordFields
{
    /**
     * Render this field's value as a state word on index and detail pages.
     *
     * @param string|null $whenTrue  the word for a true boolean, e.g. 'in service'
     * @param string|null $whenFalse the word for a false boolean, e.g. 'retired'
     */
    protected function state(FieldInterface $field, ?string $whenTrue = null, ?string $whenFalse = null): FieldInterface
    {
        return $field
            ->setCustomOption('stateWhenTrue', $whenTrue)
            ->setCustomOption('stateWhenFalse', $whenFalse)
            ->setTemplatePath('admin/field/state.html.twig');
    }
}
