<?php

namespace App\Controller\Admin;

use App\Admin\StateWord;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldInterface;

/**
 * One line per enum or boolean field, and the badge becomes a word (IP-157 Phase 8).
 *
 * Every CRUD controller that shows an enum or a boolean uses this, including the ones that
 * left the menu: they stay ROUTABLE and are still reached from links on the reading pages
 * until the Runs and Participants lanes replace them, so `IN_PROGRESS` would have survived in
 * exactly the places nobody was looking.
 *
 * Two things happen in `state()` and both are needed. `formatValue()` turns the stored value
 * into the word; `setTemplatePath()` sends the cell to the plain text template, because
 * BooleanField and ChoiceField render through their own templates, which draw the badge and
 * would ignore the formatted value. The FORM side is untouched — the template path is a
 * read-side concern — so a ChoiceField is still a select and a BooleanField still a checkbox.
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
            ->formatValue(static fn (mixed $value): string => StateWord::render($value, $whenTrue, $whenFalse))
            ->setTemplatePath('@EasyAdmin/crud/field/text.html.twig');
    }
}
